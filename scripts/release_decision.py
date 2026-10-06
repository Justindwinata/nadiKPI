#!/usr/bin/env python3
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import zipfile
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import ci_evidence

SCHEMA = "nadi.final-release-decision.v1"
RECEIPT = "CI_EVIDENCE_INGESTION.json"
BUNDLE_MANIFEST = "CI_EVIDENCE_BUNDLE.json"
VERSION_RE = re.compile(r"^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$")


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def load_json(path: Path) -> dict[str, Any]:
    raw = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(raw, dict):
        raise ValueError(f"expected JSON object: {path}")
    return raw


def require_regular(path: Path, label: str) -> None:
    if not path.is_file() or path.is_symlink():
        raise ValueError(f"{label} missing/not a regular file: {path}")


def package_version_from_name(name: str) -> str:
    prefix = "NADI-LSP-MIGAS-"
    suffix = ".zip"
    if not name.startswith(prefix) or not name.endswith(suffix):
        raise ValueError(f"unexpected gate package name: {name}")
    version = name[len(prefix):-len(suffix)]
    if not VERSION_RE.fullmatch(version):
        raise ValueError(f"release version must be explicit semantic version, got: {version}")
    return version


def inspect_release_zip(package: Path) -> dict[str, Any]:
    require_regular(package, "release ZIP")
    with zipfile.ZipFile(package, "r") as zf:
        names = zf.namelist()
        if "RELEASE_MANIFEST.json" not in names:
            raise ValueError("release ZIP missing RELEASE_MANIFEST.json")
        if len(names) != len(set(names)):
            raise ValueError("release ZIP contains duplicate member names")
        for name in names:
            p = Path(name)
            if name.startswith("/") or ".." in p.parts:
                raise ValueError(f"unsafe release ZIP member: {name}")
        manifest = json.loads(zf.read("RELEASE_MANIFEST.json").decode("utf-8"))
        if not isinstance(manifest, dict):
            raise ValueError("release manifest is not an object")
    return manifest


def verify_extracted_release(package: Path) -> dict[str, Any]:
    manifest = inspect_release_zip(package)
    with tempfile.TemporaryDirectory(prefix="nadi-final-release-") as td:
        root = Path(td)
        with zipfile.ZipFile(package, "r") as zf:
            zf.extractall(root)
        verifier = root / "scripts/verify_release_manifest.php"
        require_regular(verifier, "extracted closed-world verifier")
        proc = subprocess.run(["php", str(verifier)], cwd=root, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
        if proc.returncode != 0:
            raise ValueError(f"extracted release manifest verification failed: {proc.stdout.strip()}")
    return manifest


def decide(repo: Path, ingested: Path, output_dir: Path, expected_repository: str) -> Path:
    receipt_path = ingested / RECEIPT
    manifest_path = ingested / BUNDLE_MANIFEST
    require_regular(receipt_path, "CI ingestion receipt")
    require_regular(manifest_path, "CI evidence manifest")

    receipt = load_json(receipt_path)
    if receipt.get("schema") != ci_evidence.INGEST_SCHEMA:
        raise ValueError("CI ingestion receipt schema invalid")
    if receipt.get("release_authorizable") is not True:
        raise ValueError(f"CI ingestion is not release-authorizable: {receipt.get('release_authorization_blockers')}")

    manifest = ci_evidence.verify(repo, ingested, require_pass=True)
    allowed, blockers = ci_evidence.production_release_authorizable(manifest)
    if not allowed:
        raise ValueError(f"CI evidence fails production authorization policy: {blockers}")

    ci = manifest.get("ci", {})
    if ci.get("repository") != expected_repository:
        raise ValueError(f"GitHub repository mismatch: expected {expected_repository}, got {ci.get('repository')}")

    release_dir = ingested / "payload/release"
    packages = [p for p in release_dir.glob("*.zip") if p.is_file() and not p.is_symlink()]
    if len(packages) != 1:
        raise ValueError("ingested PASS evidence must contain exactly one release ZIP")
    package = packages[0]
    version = package_version_from_name(package.name)

    release_manifest = verify_extracted_release(package)
    if release_manifest.get("version") != version:
        raise ValueError(f"release manifest version mismatch: filename={version}, manifest={release_manifest.get('version')}")
    if release_manifest.get("frontend_build_included") is not True:
        raise ValueError("release manifest does not assert frontend_build_included=true")

    output_dir.mkdir(parents=True, exist_ok=True)
    final_name = f"NADI-LSP-MIGAS-FINAL-{version}.zip"
    final_path = output_dir / final_name
    decision_path = output_dir / "FINAL_RELEASE_DECISION.json"
    checksum_path = output_dir / f"{final_name}.sha256"
    for path in (final_path, decision_path, checksum_path):
        if path.exists() or path.is_symlink():
            raise ValueError(f"final output already exists: {path}")

    shutil.copyfile(package, final_path)
    source_sha = sha256_file(repo / "AUDITED_SOURCE_RC_MANIFEST.sha256")
    package_sha = sha256_file(final_path)
    if package_sha != manifest["gate_details"]["package"]["sha256"]:
        final_path.unlink(missing_ok=True)
        raise ValueError("final copied ZIP SHA differs from CI gate package")

    decision = {
        "schema": SCHEMA,
        "decision": "FINAL_PASS",
        "decided_at_utc": datetime.now(timezone.utc).isoformat(),
        "version": version,
        "source_manifest_sha256": source_sha,
        "ci_evidence_bundle_sha256": sha256_file(manifest_path),
        "ci_ingestion_receipt_sha256": sha256_file(receipt_path),
        "release_package": {"path": final_name, "sha256": package_sha, "bytes": final_path.stat().st_size},
        "release_manifest": {
            "version": release_manifest.get("version"),
            "file_count": release_manifest.get("file_count"),
            "frontend_build_included": release_manifest.get("frontend_build_included"),
        },
        "ci": ci,
        "authorization_policy": {
            "github_actions": True,
            "event_name": "workflow_dispatch",
            "ref": "refs/heads/main",
            "expected_repository": expected_repository,
            "explicit_semantic_version": True,
            "source_bound": True,
            "closed_world_ci_evidence": True,
            "extracted_release_manifest_verified": True,
        },
    }
    decision_path.write_text(json.dumps(decision, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    checksum_path.write_text(f"{package_sha}  {final_name}\n", encoding="utf-8")
    return decision_path


def main() -> int:
    parser = argparse.ArgumentParser(description="Authorize a NADI FINAL release only from ingested source-bound PASS CI evidence.")
    parser.add_argument("--repo-root", default=".")
    parser.add_argument("--ingested-evidence", required=True)
    parser.add_argument("--output-dir", default="dist-final")
    parser.add_argument("--expected-repository", default=os.environ.get("NADI_EXPECTED_GITHUB_REPOSITORY"))
    args = parser.parse_args()

    if not args.expected_repository:
        print("FAIL: --expected-repository (or NADI_EXPECTED_GITHUB_REPOSITORY) is required", file=sys.stderr)
        return 2
    try:
        decision = decide(
            Path(args.repo_root).resolve(),
            Path(args.ingested_evidence).resolve(),
            Path(args.output_dir).resolve(),
            args.expected_repository,
        )
    except (ValueError, OSError, json.JSONDecodeError, zipfile.BadZipFile) as exc:
        print(f"FAIL: {exc}", file=sys.stderr)
        return 2
    print(f"PASS final release decision: {decision}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
