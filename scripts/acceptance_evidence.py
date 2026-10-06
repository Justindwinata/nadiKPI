#!/usr/bin/env python3
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import sys
import zipfile
from datetime import datetime, timezone
from pathlib import Path

DEFAULT_DIR = Path(os.getenv("NADI_BROWSER_ARTIFACT_DIR", "artifacts/browser-acceptance"))
MANIFEST_NAME = "ACCEPTANCE_EVIDENCE_MANIFEST.json"
DIGEST_NAME = "ACCEPTANCE_EVIDENCE_MANIFEST.sha256"
EXPECTED_FILES = {
    "browser_acceptance.json",
    "dashboard-desktop.png",
    "dashboard-mobile.png",
    "viewer-dashboard.png",
    "viewer-report.png",
    "report-export.csv",
    "report-export.json",
    "report-export.zip",
}
SOURCE_FILES = [
    "AUDITED_SOURCE_RC_MANIFEST.sha256",
    "composer.lock",
    "package-lock.json",
    "scripts/browser_acceptance.py",
    "scripts/http_acceptance.sh",
    "scripts/final-gate.sh",
    ".github/workflows/release-gates.yml",
]


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def require_regular(path: Path, label: str) -> None:
    if path.is_symlink():
        raise ValueError(f"{label} must not be a symlink: {path}")
    if not path.is_file():
        raise ValueError(f"missing {label}: {path}")


def validate_browser_json(path: Path) -> dict[str, object]:
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise ValueError(f"invalid browser acceptance JSON: {exc}") from exc

    if not isinstance(payload, dict):
        raise ValueError("browser acceptance JSON must be an object")
    if not payload.get("started_at_utc") or not payload.get("finished_at_utc"):
        raise ValueError("browser acceptance JSON is missing start/finish timestamps")
    routes = payload.get("routes")
    if not isinstance(routes, list) or len(routes) < 12:
        raise ValueError("browser acceptance JSON does not contain the mandatory route coverage")
    for key in ("console_errors", "page_errors", "request_failures", "bad_responses"):
        if payload.get(key) not in ([], None):
            raise ValueError(f"browser acceptance contains diagnostic failures in {key}")
    scenarios = payload.get("functional_scenarios")
    if not isinstance(scenarios, dict):
        raise ValueError("browser acceptance functional_scenarios is missing")
    for scenario in ("notification_center", "immutable_report", "viewer_creation", "viewer_boundary"):
        if scenario not in scenarios:
            raise ValueError(f"browser acceptance missing functional scenario: {scenario}")
    immutable = scenarios.get("immutable_report") or {}
    content_hash = str(immutable.get("content_hash") or "") if isinstance(immutable, dict) else ""
    if not re.fullmatch(r"[0-9a-f]{64}", content_hash):
        raise ValueError("browser acceptance immutable report hash is invalid")
    return payload


def inspect_artifacts(root: Path) -> tuple[list[dict[str, object]], dict[str, object]]:
    failure = root / "browser_acceptance_failure.txt"
    if failure.exists():
        raise ValueError("browser acceptance failure evidence exists; refusing PASS manifest")

    observed: set[str] = set()
    for item in root.iterdir() if root.is_dir() else []:
        if item.is_symlink():
            raise ValueError(f"acceptance evidence symlink is not allowed: {item.name}")
        if item.is_file() and item.name not in {MANIFEST_NAME, DIGEST_NAME}:
            observed.add(item.name)
    missing = sorted(EXPECTED_FILES - observed)
    unexpected = sorted(observed - EXPECTED_FILES)
    if missing:
        raise ValueError(f"acceptance evidence missing files: {', '.join(missing)}")
    if unexpected:
        raise ValueError(f"unexpected acceptance evidence files: {', '.join(unexpected)}")

    browser_payload = validate_browser_json(root / "browser_acceptance.json")
    try:
        json.loads((root / "report-export.json").read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise ValueError(f"report-export.json is invalid: {exc}") from exc
    try:
        with zipfile.ZipFile(root / "report-export.zip", "r") as archive:
            bad = archive.testzip()
            if bad is not None:
                raise ValueError(f"report Evidence Pack contains corrupt member: {bad}")
            if not archive.namelist():
                raise ValueError("report Evidence Pack is empty")
    except zipfile.BadZipFile as exc:
        raise ValueError("report-export.zip is not a valid ZIP") from exc

    entries = []
    for name in sorted(EXPECTED_FILES):
        path = root / name
        require_regular(path, "acceptance artifact")
        entries.append({"path": name, "bytes": path.stat().st_size, "sha256": sha256_file(path)})
    return entries, browser_payload


def source_fingerprints(repo: Path) -> list[dict[str, object]]:
    entries: list[dict[str, object]] = []
    for relative in SOURCE_FILES:
        path = repo / relative
        require_regular(path, "source binding file")
        entries.append({"path": relative, "bytes": path.stat().st_size, "sha256": sha256_file(path)})
    return entries


def manifest_digest(path: Path) -> str:
    return sha256_file(path)


def generate(repo: Path, root: Path) -> None:
    root.mkdir(parents=True, exist_ok=True)
    entries, browser_payload = inspect_artifacts(root)
    source = source_fingerprints(repo)
    report = ((browser_payload.get("functional_scenarios") or {}).get("immutable_report") or {})
    payload = {
        "schema": "nadi.acceptance-evidence.v1",
        "generated_at_utc": datetime.now(timezone.utc).isoformat(),
        "acceptance_started_at_utc": browser_payload.get("started_at_utc"),
        "acceptance_finished_at_utc": browser_payload.get("finished_at_utc"),
        "artifact_count": len(entries),
        "artifacts": entries,
        "source_fingerprints": source,
        "report_snapshot": {
            "id": report.get("id") if isinstance(report, dict) else None,
            "content_hash": report.get("content_hash") if isinstance(report, dict) else None,
        },
        "closed_world": True,
    }
    manifest = root / MANIFEST_NAME
    manifest.write_text(json.dumps(payload, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    digest = manifest_digest(manifest)
    (root / DIGEST_NAME).write_text(f"{digest}  {MANIFEST_NAME}\n", encoding="utf-8")
    print(f"PASS acceptance evidence manifest: artifacts={len(entries)} sha256={digest}")


def verify(repo: Path, root: Path) -> None:
    manifest = root / MANIFEST_NAME
    digest_path = root / DIGEST_NAME
    require_regular(manifest, "acceptance evidence manifest")
    require_regular(digest_path, "acceptance evidence manifest digest")

    digest_line = digest_path.read_text(encoding="utf-8").strip()
    match = re.fullmatch(r"([0-9a-f]{64})\s+" + re.escape(MANIFEST_NAME), digest_line)
    if not match:
        raise ValueError("acceptance evidence digest sidecar is invalid")
    actual_manifest_digest = manifest_digest(manifest)
    if actual_manifest_digest != match.group(1):
        raise ValueError("acceptance evidence manifest SHA-256 mismatch")

    try:
        payload = json.loads(manifest.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        raise ValueError(f"acceptance evidence manifest is invalid JSON: {exc}") from exc
    if payload.get("schema") != "nadi.acceptance-evidence.v1" or payload.get("closed_world") is not True:
        raise ValueError("acceptance evidence manifest schema/closed_world assertion is invalid")

    entries, browser_payload = inspect_artifacts(root)
    if entries != payload.get("artifacts"):
        raise ValueError("acceptance artifact hashes/bytes differ from manifest")
    source = source_fingerprints(repo)
    if source != payload.get("source_fingerprints"):
        raise ValueError("source fingerprints differ from acceptance evidence manifest")

    report = ((browser_payload.get("functional_scenarios") or {}).get("immutable_report") or {})
    expected_report = payload.get("report_snapshot") or {}
    if not isinstance(report, dict) or report.get("id") != expected_report.get("id") or report.get("content_hash") != expected_report.get("content_hash"):
        raise ValueError("immutable report identity differs from acceptance evidence manifest")

    print(f"PASS acceptance evidence verification: artifacts={len(entries)} source_bound=yes closed_world=yes")


def main() -> int:
    parser = argparse.ArgumentParser(description="Generate or verify tamper-evident NADI browser acceptance evidence.")
    parser.add_argument("mode", choices=["generate", "verify"])
    parser.add_argument("--artifact-dir", type=Path, default=DEFAULT_DIR)
    parser.add_argument("--repo", type=Path, default=Path.cwd())
    args = parser.parse_args()
    repo = args.repo.resolve()
    root = args.artifact_dir.resolve()
    try:
        if args.mode == "generate":
            generate(repo, root)
        else:
            verify(repo, root)
    except ValueError as exc:
        print(f"FAIL: {exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
