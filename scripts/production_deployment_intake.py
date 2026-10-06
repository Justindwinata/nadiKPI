#!/usr/bin/env python3
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import shutil
import stat
import sys
import tempfile
import zipfile
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

SCHEMA = "nadi.production-deployment-intake.v1"
DECISION_SCHEMA = "nadi.final-release-decision.v1"
CONSUMPTION_SCHEMA = "nadi.external-ci-consumption.v1"
VERSION_RE = re.compile(r"^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$")
MAX_ARCHIVE_FILES = 20_000
MAX_ARCHIVE_UNCOMPRESSED_BYTES = 3 * 1024 * 1024 * 1024
MAX_MEMBER_UNCOMPRESSED_BYTES = 768 * 1024 * 1024


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def load_json(path: Path) -> dict[str, Any]:
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise ValueError(f"expected JSON object: {path}")
    return data


def require_regular(path: Path, label: str) -> None:
    if not path.is_file() or path.is_symlink():
        raise ValueError(f"{label} missing/not a regular file: {path}")


def safe_member_name(raw: str) -> str:
    value = raw.replace("\\", "/")
    if not value or value.startswith("/") or "\x00" in value:
        raise ValueError(f"unsafe release archive member: {raw!r}")
    parts = Path(value).parts
    if any(part in {"", ".", ".."} for part in parts):
        raise ValueError(f"unsafe release archive member: {raw!r}")
    if parts and parts[0].endswith(":"):
        raise ValueError(f"unsafe release archive member: {raw!r}")
    return Path(*parts).as_posix()


def safe_extract_release(package: Path, destination: Path) -> None:
    require_regular(package, "FINAL release ZIP")
    with zipfile.ZipFile(package, "r") as archive:
        infos = archive.infolist()
        if len(infos) > MAX_ARCHIVE_FILES:
            raise ValueError(f"release archive has too many entries: {len(infos)}")
        seen: set[str] = set()
        normalized: list[tuple[zipfile.ZipInfo, str]] = []
        total = 0
        for info in infos:
            raw = info.filename.rstrip("/")
            if not raw:
                continue
            name = safe_member_name(raw)
            if name in seen:
                raise ValueError(f"duplicate release archive member: {name}")
            seen.add(name)
            mode = (info.external_attr >> 16) & 0o170000
            if mode == stat.S_IFLNK:
                raise ValueError(f"release archive symlink refused: {name}")
            if info.file_size > MAX_MEMBER_UNCOMPRESSED_BYTES:
                raise ValueError(f"release archive member too large: {name}")
            total += info.file_size
            if total > MAX_ARCHIVE_UNCOMPRESSED_BYTES:
                raise ValueError("release archive uncompressed size exceeds safety limit")
            normalized.append((info, name))

        for info, name in normalized:
            target = destination / name
            target.parent.mkdir(parents=True, exist_ok=True)
            if info.is_dir():
                target.mkdir(parents=True, exist_ok=True)
                continue
            with archive.open(info, "r") as src, target.open("wb") as dst:
                shutil.copyfileobj(src, dst, length=1024 * 1024)
            mode = (info.external_attr >> 16) & 0o777
            target.chmod(mode if mode else 0o644)


def verify_release_tree(release_root: Path) -> dict[str, Any]:
    manifest_path = release_root / "RELEASE_MANIFEST.json"
    require_regular(manifest_path, "RELEASE_MANIFEST.json")
    manifest = load_json(manifest_path)
    files = manifest.get("files")
    if not isinstance(files, list):
        raise ValueError("RELEASE_MANIFEST.json files must be an array")
    expected: dict[str, dict[str, Any]] = {}
    for entry in files:
        if not isinstance(entry, dict):
            raise ValueError("release manifest file entry is invalid")
        path = str(entry.get("path") or "")
        digest = str(entry.get("sha256") or "")
        size = entry.get("bytes")
        if not path or path in expected or not re.fullmatch(r"[0-9a-f]{64}", digest) or not isinstance(size, int):
            raise ValueError(f"invalid release manifest entry: {entry}")
        safe_member_name(path)
        expected[path] = entry

    actual: set[str] = set()
    for path in release_root.rglob("*"):
        if path.is_symlink():
            raise ValueError(f"extracted release contains symlink: {path.relative_to(release_root)}")
        if path.is_file():
            rel = path.relative_to(release_root).as_posix()
            if rel == "RELEASE_MANIFEST.json":
                continue
            actual.add(rel)
    if actual != set(expected):
        missing = sorted(set(expected) - actual)
        extra = sorted(actual - set(expected))
        raise ValueError(f"extracted release closed-world mismatch: missing={missing[:5]} extra={extra[:5]}")

    for rel, entry in expected.items():
        path = release_root / rel
        require_regular(path, f"release file {rel}")
        if path.stat().st_size != entry["bytes"]:
            raise ValueError(f"release file size mismatch: {rel}")
        if sha256_file(path) != entry["sha256"]:
            raise ValueError(f"release file SHA-256 mismatch: {rel}")
    if manifest.get("file_count") != len(expected):
        raise ValueError("release manifest file_count mismatch")
    return manifest


def consume_final_output(source_repo: Path, final_dir: Path, expected_repository: str, output_dir: Path) -> Path:
    source_manifest = source_repo / "AUDITED_SOURCE_RC_MANIFEST.sha256"
    require_regular(source_manifest, "audited source manifest")
    if output_dir.exists() or output_dir.is_symlink():
        raise ValueError(f"deployment output already exists: {output_dir}")
    if not final_dir.is_dir() or final_dir.is_symlink():
        raise ValueError(f"FINAL output directory missing/not regular directory: {final_dir}")

    decision_path = final_dir / "FINAL_RELEASE_DECISION.json"
    consumption_path = final_dir / "EXTERNAL_CI_CONSUMPTION.json"
    require_regular(decision_path, "FINAL release decision")
    require_regular(consumption_path, "external CI consumption receipt")
    decision = load_json(decision_path)
    consumption = load_json(consumption_path)
    if decision.get("schema") != DECISION_SCHEMA or decision.get("decision") != "FINAL_PASS":
        raise ValueError("FINAL release decision is not an authorized FINAL_PASS decision")
    if consumption.get("schema") != CONSUMPTION_SCHEMA or consumption.get("status") != "FINAL_PASS":
        raise ValueError("external CI consumption receipt is not FINAL_PASS")
    if decision.get("source_manifest_sha256") != sha256_file(source_manifest):
        raise ValueError("FINAL release decision source manifest does not match this source checkpoint")
    if consumption.get("source_manifest_sha256") != sha256_file(source_manifest):
        raise ValueError("external CI consumption source manifest does not match this source checkpoint")
    if consumption.get("expected_repository") != expected_repository:
        raise ValueError("external CI consumption expected repository mismatch")

    decision_ci = decision.get("ci")
    consumption_ci = consumption.get("ci")
    if not isinstance(decision_ci, dict) or not isinstance(consumption_ci, dict) or decision_ci != consumption_ci:
        raise ValueError("CI provenance mismatch between FINAL decision and consumption receipt")
    if decision_ci.get("repository") != expected_repository:
        raise ValueError(f"GitHub repository mismatch: expected {expected_repository}, got {decision_ci.get('repository')}")
    if decision_ci.get("event_name") != "workflow_dispatch" or decision_ci.get("ref") != "refs/heads/main":
        raise ValueError("deployment intake requires workflow_dispatch on refs/heads/main")

    release = decision.get("release_package")
    consumed_release = consumption.get("release_package")
    if not isinstance(release, dict) or not isinstance(consumed_release, dict):
        raise ValueError("release package metadata missing")
    release_name = str(release.get("path") or "")
    package = final_dir / release_name
    require_regular(package, "FINAL release ZIP")
    checksum = final_dir / f"{release_name}.sha256"
    require_regular(checksum, "FINAL release checksum")
    package_sha = sha256_file(package)
    if package_sha != release.get("sha256") or package_sha != consumed_release.get("sha256"):
        raise ValueError("FINAL release ZIP hash disagrees with decision/consumption receipts")
    if package.stat().st_size != release.get("bytes") or package.stat().st_size != consumed_release.get("bytes"):
        raise ValueError("FINAL release ZIP size disagrees with decision/consumption receipts")
    checksum_text = checksum.read_text(encoding="utf-8").strip()
    if checksum_text != f"{package_sha}  {release_name}":
        raise ValueError("FINAL release checksum sidecar mismatch")
    if consumption.get("release_decision_sha256") != sha256_file(decision_path):
        raise ValueError("external CI consumption receipt does not bind the current FINAL release decision")

    version = str(decision.get("version") or "")
    if not VERSION_RE.fullmatch(version):
        raise ValueError(f"invalid semantic release version: {version}")
    if release_name != f"NADI-LSP-MIGAS-FINAL-{version}.zip":
        raise ValueError("FINAL package filename/version mismatch")

    allowed_names = {"FINAL_RELEASE_DECISION.json", "EXTERNAL_CI_CONSUMPTION.json", release_name, f"{release_name}.sha256"}
    actual_names = {p.name for p in final_dir.iterdir() if p.is_file()}
    dirs = [p.name for p in final_dir.iterdir() if p.is_dir()]
    if actual_names != allowed_names or dirs:
        raise ValueError(f"FINAL output directory is not closed-world: files={sorted(actual_names)} dirs={dirs}")

    output_dir.parent.mkdir(parents=True, exist_ok=True)
    stage = Path(tempfile.mkdtemp(prefix=f".{output_dir.name}.staging-", dir=output_dir.parent))
    keep = False
    try:
        custody = stage / "custody"
        release_root = stage / "release"
        custody.mkdir(parents=True)
        release_root.mkdir(parents=True)
        for src in (decision_path, consumption_path, package, checksum):
            shutil.copyfile(src, custody / src.name)
        safe_extract_release(custody / release_name, release_root)
        release_manifest = verify_release_tree(release_root)
        if release_manifest.get("version") != version:
            raise ValueError("extracted release manifest version mismatch")
        if release_manifest.get("frontend_build_included") is not True:
            raise ValueError("extracted release does not assert frontend_build_included=true")

        custody_entries = []
        for path in sorted(custody.iterdir(), key=lambda p: p.name):
            custody_entries.append({"path": f"custody/{path.name}", "sha256": sha256_file(path), "bytes": path.stat().st_size})
        intake = {
            "schema": SCHEMA,
            "status": "AUTHORIZED_FOR_DEPLOYMENT",
            "created_at_utc": datetime.now(timezone.utc).isoformat(),
            "expected_repository": expected_repository,
            "version": version,
            "source_manifest_sha256": sha256_file(source_manifest),
            "release_package": {"path": f"custody/{release_name}", "sha256": package_sha, "bytes": package.stat().st_size},
            "release_root": "release",
            "release_manifest_sha256": sha256_file(release_root / "RELEASE_MANIFEST.json"),
            "final_release_decision_sha256": sha256_file(custody / "FINAL_RELEASE_DECISION.json"),
            "external_ci_consumption_sha256": sha256_file(custody / "EXTERNAL_CI_CONSUMPTION.json"),
            "ci": decision_ci,
            "custody_files": custody_entries,
            "deployment_policy": {
                "closed_world_final_output": True,
                "safe_release_extraction": True,
                "extracted_release_closed_world_verified": True,
                "expected_repository_required": True,
                "workflow_dispatch_main_required": True,
                "no_manual_repackaging": True,
            },
        }
        (stage / "DEPLOYMENT_INTAKE.json").write_text(json.dumps(intake, indent=2, sort_keys=True) + "\n", encoding="utf-8")
        os.replace(stage, output_dir)
        keep = True
    finally:
        if not keep:
            shutil.rmtree(stage, ignore_errors=True)
    return output_dir / "DEPLOYMENT_INTAKE.json"


def main() -> int:
    parser = argparse.ArgumentParser(description="Create a production deployment envelope only from an authorized NADI FINAL output directory.")
    parser.add_argument("--repo-root", default=".")
    parser.add_argument("--final-dir", required=True)
    parser.add_argument("--expected-repository", default=os.environ.get("NADI_EXPECTED_GITHUB_REPOSITORY"))
    parser.add_argument("--output-dir", required=True)
    args = parser.parse_args()
    if not args.expected_repository:
        print("FAIL: --expected-repository (or NADI_EXPECTED_GITHUB_REPOSITORY) is required", file=sys.stderr)
        return 2
    try:
        receipt = consume_final_output(
            Path(args.repo_root).resolve(),
            Path(args.final_dir).resolve(),
            args.expected_repository,
            Path(args.output_dir).resolve(),
        )
    except (ValueError, OSError, json.JSONDecodeError, zipfile.BadZipFile) as exc:
        print(f"FAIL: {exc}", file=sys.stderr)
        return 2
    print(f"PASS production deployment intake: {receipt}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
