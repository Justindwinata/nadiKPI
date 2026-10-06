#!/usr/bin/env python3
from __future__ import annotations

import argparse
import hashlib
import json
import os
import shutil
import stat
import sys
import tempfile
import zipfile
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import ci_evidence
import release_decision
import external_runtime_target

SCHEMA = "nadi.external-ci-consumption.v1"
MAX_ARCHIVE_FILES = 10_000
MAX_ARCHIVE_UNCOMPRESSED_BYTES = 2 * 1024 * 1024 * 1024
MAX_MEMBER_UNCOMPRESSED_BYTES = 512 * 1024 * 1024


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def require_regular(path: Path, label: str) -> None:
    if not path.is_file() or path.is_symlink():
        raise ValueError(f"{label} missing/not a regular file: {path}")


def reject_symlinks(root: Path) -> None:
    if root.is_symlink():
        raise ValueError(f"external artifact root must not be a symlink: {root}")
    for path in root.rglob("*"):
        if path.is_symlink():
            raise ValueError(f"external artifact contains symlink: {path}")


def safe_member_name(raw: str) -> str:
    value = raw.replace("\\", "/")
    if not value or value.startswith("/") or "\x00" in value:
        raise ValueError(f"unsafe external artifact member: {raw!r}")
    parts = Path(value).parts
    if any(part in {"", ".", ".."} for part in parts):
        raise ValueError(f"unsafe external artifact member: {raw!r}")
    if parts and parts[0].endswith(":"):
        raise ValueError(f"unsafe external artifact member: {raw!r}")
    return Path(*parts).as_posix()


def safe_extract_zip(source: Path, destination: Path) -> str:
    require_regular(source, "external CI artifact ZIP")
    archive_sha = sha256_file(source)
    with zipfile.ZipFile(source, "r") as archive:
        infos = archive.infolist()
        if len(infos) > MAX_ARCHIVE_FILES:
            raise ValueError(f"external artifact has too many entries: {len(infos)}")
        seen: set[str] = set()
        total = 0
        normalized: list[tuple[zipfile.ZipInfo, str]] = []
        for info in infos:
            name = safe_member_name(info.filename.rstrip("/")) if info.filename.rstrip("/") else ""
            if not name:
                continue
            if name in seen:
                raise ValueError(f"duplicate external artifact member: {name}")
            seen.add(name)
            mode = (info.external_attr >> 16) & 0o170000
            if mode == stat.S_IFLNK:
                raise ValueError(f"external artifact symlink refused: {name}")
            if info.file_size > MAX_MEMBER_UNCOMPRESSED_BYTES:
                raise ValueError(f"external artifact member too large: {name}")
            total += info.file_size
            if total > MAX_ARCHIVE_UNCOMPRESSED_BYTES:
                raise ValueError("external artifact uncompressed size exceeds safety limit")
            normalized.append((info, name))

        for info, name in normalized:
            target = destination / name
            target.parent.mkdir(parents=True, exist_ok=True)
            if info.is_dir():
                target.mkdir(parents=True, exist_ok=True)
                continue
            with archive.open(info, "r") as src, target.open("wb") as dst:
                shutil.copyfileobj(src, dst, length=1024 * 1024)
    reject_symlinks(destination)
    return archive_sha


def locate_bundle_root(root: Path) -> Path:
    reject_symlinks(root)
    candidates = [p for p in root.rglob(ci_evidence.MANIFEST) if p.is_file() and not p.is_symlink()]
    if len(candidates) != 1:
        raise ValueError(f"external artifact must contain exactly one {ci_evidence.MANIFEST}; found {len(candidates)}")
    bundle_root = candidates[0].parent
    require_regular(bundle_root / f"{ci_evidence.MANIFEST}.sha256", "CI evidence bundle digest")

    # GitHub's artifact ZIP may either contain the bundle files at its root or one
    # wrapper directory. Anything outside the discovered bundle is refused.
    outsiders: list[str] = []
    for path in root.rglob("*"):
        if not path.is_file():
            continue
        try:
            path.relative_to(bundle_root)
        except ValueError:
            outsiders.append(path.relative_to(root).as_posix())
    if outsiders:
        raise ValueError(f"unexpected files outside CI evidence bundle: {outsiders[:5]}")
    return bundle_root


def artifact_identity(path: Path, archive_sha: str | None, bundle_root: Path) -> dict[str, Any]:
    if archive_sha is not None:
        return {
            "kind": "zip",
            "name": path.name,
            "sha256": archive_sha,
            "bytes": path.stat().st_size,
        }
    return {
        "kind": "directory",
        "name": path.name,
        "bundle_manifest_sha256": sha256_file(bundle_root / ci_evidence.MANIFEST),
    }


def consume(
    repo: Path,
    artifact: Path,
    expected_repository: str,
    target_binding_path: Path,
    ingestion_root: Path,
    final_output_dir: Path,
) -> Path:
    require_regular(repo / "AUDITED_SOURCE_RC_MANIFEST.sha256", "audited source manifest")
    target_verification = external_runtime_target.verify(repo, target_binding_path)
    binding = json.loads(target_binding_path.read_text(encoding="utf-8"))
    if binding.get("repository") != expected_repository:
        raise ValueError("target binding repository does not match expected repository")
    if final_output_dir.exists() or final_output_dir.is_symlink():
        raise ValueError(f"final output destination already exists: {final_output_dir}")

    temp_parent: tempfile.TemporaryDirectory[str] | None = None
    archive_sha: str | None = None
    try:
        if artifact.is_symlink():
            raise ValueError(f"external artifact must not be a symlink: {artifact}")
        if artifact.is_dir():
            input_root = artifact.resolve()
            reject_symlinks(input_root)
        elif artifact.is_file():
            if not zipfile.is_zipfile(artifact):
                raise ValueError("external CI artifact file must be a ZIP archive")
            temp_parent = tempfile.TemporaryDirectory(prefix="nadi-external-ci-")
            input_root = Path(temp_parent.name)
            archive_sha = safe_extract_zip(artifact.resolve(), input_root)
        else:
            raise ValueError(f"external CI artifact does not exist: {artifact}")

        bundle_root = locate_bundle_root(input_root)
        manifest = ci_evidence.verify(repo, bundle_root, require_pass=True)
        allowed, blockers = ci_evidence.production_release_authorizable(manifest)
        if not allowed:
            raise ValueError(f"external CI PASS evidence is not production-authorizable: {blockers}")

        ci = manifest.get("ci")
        if not isinstance(ci, dict):
            raise ValueError("CI provenance missing from evidence bundle")
        external_runtime_target.verify_ci(binding, ci)
        if ci.get("repository") != expected_repository:
            raise ValueError(f"GitHub repository mismatch: expected {expected_repository}, got {ci.get('repository')}")
        run_id = str(ci.get("run_id") or "").strip()
        run_attempt = str(ci.get("run_attempt") or "").strip()
        if not run_id or not run_attempt:
            raise ValueError("CI provenance is missing run_id/run_attempt")

        ingestion_root.mkdir(parents=True, exist_ok=True)
        ingestion_destination = ingestion_root / f"{run_id}-{run_attempt}"
        ci_evidence.ingest(repo, bundle_root, ingestion_destination, require_pass=True)

        final_output_dir.parent.mkdir(parents=True, exist_ok=True)
        stage = Path(tempfile.mkdtemp(prefix=f".{final_output_dir.name}.staging-", dir=final_output_dir.parent))
        stage_cleanup = True
        try:
            decision_path = release_decision.decide(repo, ingestion_destination, stage, expected_repository)
            decision = json.loads(decision_path.read_text(encoding="utf-8"))
            if decision.get("decision") != "FINAL_PASS":
                raise ValueError("release decision did not produce FINAL_PASS")
            release = decision.get("release_package")
            if not isinstance(release, dict):
                raise ValueError("release decision is missing release_package")
            release_name = str(release.get("path") or "")
            release_path = stage / release_name
            require_regular(release_path, "final release ZIP")
            if sha256_file(release_path) != release.get("sha256"):
                raise ValueError("final release ZIP differs from release decision SHA-256")

            receipt = {
                "schema": SCHEMA,
                "status": "FINAL_PASS",
                "consumed_at_utc": datetime.now(timezone.utc).isoformat(),
                "expected_repository": expected_repository,
                "target_binding": {
                    "path": target_binding_path.name,
                    "sha256": sha256_file(target_binding_path),
                    "repository_id": target_verification["repository_id"],
                    "materialized_commit_sha": target_verification["materialized_commit_sha"],
                    "checkpoint_sha256": target_verification["checkpoint_sha256"],
                    "workflow_sha256": target_verification["workflow_sha256"],
                },
                "source_manifest_sha256": sha256_file(repo / "AUDITED_SOURCE_RC_MANIFEST.sha256"),
                "external_artifact": artifact_identity(artifact.resolve(), archive_sha, bundle_root),
                "ci_evidence_bundle_sha256": sha256_file(bundle_root / ci_evidence.MANIFEST),
                "ingestion": {
                    "path": ingestion_destination.relative_to(repo).as_posix()
                    if repo in ingestion_destination.parents
                    else ingestion_destination.name,
                    "receipt_sha256": sha256_file(ingestion_destination / ci_evidence.INGEST_RESULT),
                },
                "release_decision_sha256": sha256_file(decision_path),
                "release_package": {
                    "name": release_name,
                    "sha256": sha256_file(release_path),
                    "bytes": release_path.stat().st_size,
                },
                "ci": ci,
            }
            receipt_path = stage / "EXTERNAL_CI_CONSUMPTION.json"
            receipt_path.write_text(json.dumps(receipt, indent=2, sort_keys=True) + "\n", encoding="utf-8")
            os.replace(stage, final_output_dir)
            stage_cleanup = False
        finally:
            if stage_cleanup:
                shutil.rmtree(stage, ignore_errors=True)

        return final_output_dir / "EXTERNAL_CI_CONSUMPTION.json"
    finally:
        if temp_parent is not None:
            temp_parent.cleanup()


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Consume a downloaded NADI GitHub Actions PASS artifact through verify -> append-only ingest -> final decision."
    )
    parser.add_argument("--repo-root", default=".")
    parser.add_argument("--artifact", required=True, help="Downloaded GitHub Actions artifact ZIP or extracted bundle directory")
    parser.add_argument("--expected-repository", default=os.environ.get("NADI_EXPECTED_GITHUB_REPOSITORY"))
    parser.add_argument("--target-binding", default=os.environ.get("NADI_EXTERNAL_RUNTIME_TARGET_BINDING"))
    parser.add_argument("--ingestion-root", default="artifacts/ingested-ci")
    parser.add_argument("--final-output-dir", default="dist-final")
    args = parser.parse_args()

    if not args.expected_repository:
        print("FAIL: --expected-repository (or NADI_EXPECTED_GITHUB_REPOSITORY) is required", file=sys.stderr)
        return 2
    if not args.target_binding:
        print("FAIL: --target-binding (or NADI_EXTERNAL_RUNTIME_TARGET_BINDING) is required", file=sys.stderr)
        return 2

    repo = Path(args.repo_root).resolve()
    artifact = Path(args.artifact).resolve()
    target_binding_path = Path(args.target_binding).resolve()
    ingestion_root = (repo / args.ingestion_root).resolve() if not Path(args.ingestion_root).is_absolute() else Path(args.ingestion_root).resolve()
    final_output_dir = (repo / args.final_output_dir).resolve() if not Path(args.final_output_dir).is_absolute() else Path(args.final_output_dir).resolve()
    try:
        receipt = consume(repo, artifact, args.expected_repository, target_binding_path, ingestion_root, final_output_dir)
    except (ValueError, OSError, json.JSONDecodeError, zipfile.BadZipFile) as exc:
        print(f"FAIL: {exc}", file=sys.stderr)
        return 2
    print(f"PASS external CI artifact consumption: {receipt}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
