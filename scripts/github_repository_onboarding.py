#!/usr/bin/env python3
"""Build and verify the non-authorizing GitHub repository onboarding package for NADI.

The package prepares a frozen checkpoint for repository onboarding without inventing a
GitHub repository identity. Production-authoritative target binding is created later
from repository metadata exported by the real GitHub API.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import shutil
import stat
import sys
import tempfile
import zipfile
from pathlib import Path
from typing import Any

import external_runtime_target as target
import source_freeze

SCHEMA = "nadi.github-repository-onboarding.v1"
MANIFEST_SCHEMA = "nadi.github-repository-onboarding-manifest.v1"
METADATA_REQUIREMENTS_SCHEMA = "nadi.github-repository-metadata-requirements.v1"
STATUS = "WAITING_GITHUB_REPOSITORY_MATERIALIZATION"
CHECKPOINT_NAME = "SOURCE_CHECKPOINT.zip"
REQUEST_NAME = target.REQUEST_NAME
ONBOARDING_NAME = "GITHUB_REPOSITORY_ONBOARDING.json"
REQUIREMENTS_NAME = "GITHUB_REPOSITORY_METADATA_REQUIREMENTS.json"
RUNBOOK_NAME = "GITHUB_REPOSITORY_ONBOARDING.md"
TARGET_POLICY_NAME = "EXTERNAL_RUNTIME_TARGET_POLICY.json"
MATERIALIZATION_POLICY_NAME = "REPOSITORY_MATERIALIZATION_POLICY.json"
MATERIALIZATION_RUNBOOK_NAME = "GITHUB_REPOSITORY_MATERIALIZATION.md"
MANIFEST_NAME = "GITHUB_REPOSITORY_ONBOARDING_MANIFEST.json"
DIGEST_NAME = f"{MANIFEST_NAME}.sha256"
MAX_FILES = 10_000
MAX_MEMBER = 512 * 1024 * 1024
MAX_TOTAL = 2 * 1024 * 1024 * 1024


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def load_json(path: Path) -> dict[str, Any]:
    if not path.is_file() or path.is_symlink():
        raise ValueError(f"JSON file missing/unsafe: {path}")
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise ValueError(f"JSON object required: {path}")
    return data


def metadata_requirements(policy: dict[str, Any]) -> dict[str, Any]:
    return {
        "schema": METADATA_REQUIREMENTS_SCHEMA,
        "status": "REPOSITORY_METADATA_REQUIRED",
        "provider": "github-rest-api",
        "required_server_url": policy["required_server_url"],
        "required_default_branch": policy["required_default_branch"],
        "required_fields": [
            "id",
            "full_name",
            "default_branch",
            "html_url",
            "url",
            "archived",
            "disabled",
            "owner.login",
        ],
        "required_remote_ref_fields": ["ref", "object.type", "object.sha"],
        "collection_examples": [
            "gh api repos/OWNER/REPOSITORY > github-repository-metadata.json",
            "gh api repos/OWNER/REPOSITORY/git/ref/heads/main > github-main-ref.json",
            "GitHub REST GET /repos/{owner}/{repo}",
            "GitHub REST GET /repos/{owner}/{repo}/git/ref/heads/main",
        ],
        "credential_rule": "Do not place GitHub tokens, authorization headers, .env files, or private keys inside the onboarding package or metadata export.",
        "binding_rule": "Only validated repository metadata plus a confirmed remote main HEAD matching the deterministic materialized commit may create a production-authoritative target binding.",
    }


def file_entries(root: Path) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    for path in sorted(root.rglob("*"), key=lambda p: p.relative_to(root).as_posix()):
        if not path.is_file():
            continue
        rel = path.relative_to(root).as_posix()
        if rel in {MANIFEST_NAME, DIGEST_NAME}:
            continue
        rows.append({"path": rel, "bytes": path.stat().st_size, "sha256": sha256_file(path)})
    return rows


def write_manifest(root: Path, onboarding: dict[str, Any]) -> dict[str, Any]:
    files = file_entries(root)
    manifest = {
        "schema": MANIFEST_SCHEMA,
        "status": "ONBOARDING_PACKAGE_READY",
        "closed_world": True,
        "release_authorizing": False,
        "checkpoint_sha256": onboarding["checkpoint_sha256"],
        "source_manifest_sha256": onboarding["source_manifest_sha256"],
        "source_manifest_entries": onboarding["source_manifest_entries"],
        "workflow_sha256": onboarding["workflow_sha256"],
        "file_count": len(files),
        "files": files,
    }
    path = root / MANIFEST_NAME
    path.write_text(json.dumps(manifest, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    (root / DIGEST_NAME).write_text(f"{sha256_file(path)}  {MANIFEST_NAME}\n", encoding="utf-8")
    return manifest


def secret_scan(root: Path) -> None:
    source_freeze.scan_handoff_secrets(root)


def create_package(repo: Path, checkpoint: Path, release_version: str | None, output_dir: Path, output_zip: Path | None) -> dict[str, Any]:
    if output_dir.exists() or output_dir.is_symlink():
        raise ValueError(f"refusing to overwrite onboarding directory: {output_dir}")
    if output_zip is not None and (output_zip.exists() or output_zip.is_symlink()):
        raise ValueError(f"refusing to overwrite onboarding ZIP: {output_zip}")

    policy = target.load_policy(repo)
    cp = target.checkpoint_verification(repo, checkpoint)
    output_dir.mkdir(parents=True)
    shutil.copy2(checkpoint, output_dir / CHECKPOINT_NAME)
    request_path = output_dir / REQUEST_NAME
    request = target.make_request(repo, checkpoint, request_path, release_version)
    requirements = metadata_requirements(policy)
    (output_dir / REQUIREMENTS_NAME).write_text(json.dumps(requirements, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    shutil.copy2(repo / "docs/GITHUB_REPOSITORY_ONBOARDING.md", output_dir / RUNBOOK_NAME)
    shutil.copy2(repo / target.POLICY, output_dir / TARGET_POLICY_NAME)
    shutil.copy2(repo / "config/repository-materialization-policy.json", output_dir / MATERIALIZATION_POLICY_NAME)
    shutil.copy2(repo / "docs/GITHUB_REPOSITORY_MATERIALIZATION.md", output_dir / MATERIALIZATION_RUNBOOK_NAME)

    onboarding = {
        "schema": SCHEMA,
        "status": STATUS,
        "created_at_utc": source_freeze.evidence_now().isoformat(),
        "provider": policy["provider"],
        "release_authorizing": False,
        "checkpoint_file": CHECKPOINT_NAME,
        "checkpoint_sha256": cp["checkpoint_sha256"],
        "source_manifest_sha256": cp["source_manifest_sha256"],
        "source_manifest_entries": cp["source_manifest_entries"],
        "workflow_sha256": cp["workflow_sha256"],
        "target_request_file": REQUEST_NAME,
        "target_request_sha256": sha256_file(request_path),
        "repository_metadata_requirements_file": REQUIREMENTS_NAME,
        "required_repository_state": {
            "server_url": policy["required_server_url"],
            "default_branch": policy["required_default_branch"],
            "required_ref": policy["required_ref"],
            "required_event": policy["required_event"],
            "workflow_path": policy["required_workflow_path"],
            "workflow_name": policy["required_workflow_name"],
            "required_job": policy["required_job"],
        },
        "requested_release_version": release_version,
        "next_action": "Create the deterministic Git materialization package, push its exact main commit to the real GitHub repository, confirm remote HEAD, then create target binding with external_runtime_target.py bind-metadata.",
        "operator_rule": "This package is non-authorizing. Do not invent repository_id and do not treat onboarding completion as CI PASS or FINAL PASS.",
    }
    (output_dir / ONBOARDING_NAME).write_text(json.dumps(onboarding, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    secret_scan(output_dir)
    write_manifest(output_dir, onboarding)
    verified = verify_package(repo, output_dir)
    if output_zip is not None:
        source_freeze.deterministic_zip(output_dir, output_zip)
        verify_package(repo, output_zip)
    return verified


def safe_extract(source: Path, destination: Path) -> None:
    if not source.is_file() or source.is_symlink() or not zipfile.is_zipfile(source):
        raise ValueError("onboarding artifact must be a regular ZIP")
    seen: set[str] = set()
    total = 0
    with zipfile.ZipFile(source, "r") as zf:
        infos = zf.infolist()
        if len(infos) > MAX_FILES:
            raise ValueError("onboarding archive entry count exceeds safety limit")
        for info in infos:
            raw = info.filename.rstrip("/")
            if not raw:
                continue
            name = source_freeze.safe_name(raw)
            if name in seen:
                raise ValueError(f"duplicate onboarding archive member: {name}")
            seen.add(name)
            mode = (info.external_attr >> 16) & 0o170000
            if mode == stat.S_IFLNK:
                raise ValueError(f"onboarding archive symlink refused: {name}")
            if info.file_size > MAX_MEMBER:
                raise ValueError(f"onboarding archive member too large: {name}")
            total += info.file_size
            if total > MAX_TOTAL:
                raise ValueError("onboarding archive uncompressed size exceeds safety limit")
            target_path = destination / name
            target_path.parent.mkdir(parents=True, exist_ok=True)
            if info.is_dir():
                target_path.mkdir(parents=True, exist_ok=True)
            else:
                with zf.open(info, "r") as src, target_path.open("wb") as dst:
                    shutil.copyfileobj(src, dst, length=1024 * 1024)
    for path in destination.rglob("*"):
        if path.is_symlink():
            raise ValueError(f"onboarding extracted symlink refused: {path.relative_to(destination)}")


def verify_root(repo: Path, root: Path) -> dict[str, Any]:
    onboarding = load_json(root / ONBOARDING_NAME)
    if onboarding.get("schema") != SCHEMA or onboarding.get("status") != STATUS:
        raise ValueError("onboarding schema/status invalid")
    if onboarding.get("release_authorizing") is not False:
        raise ValueError("repository onboarding package must be non-authorizing")

    manifest = load_json(root / MANIFEST_NAME)
    if manifest.get("schema") != MANIFEST_SCHEMA or manifest.get("status") != "ONBOARDING_PACKAGE_READY" or manifest.get("closed_world") is not True:
        raise ValueError("onboarding manifest schema/status invalid")
    digest = root / DIGEST_NAME
    if not digest.is_file() or digest.is_symlink():
        raise ValueError("onboarding manifest digest missing/unsafe")
    if digest.read_text(encoding="utf-8").strip() != f"{sha256_file(root / MANIFEST_NAME)}  {MANIFEST_NAME}":
        raise ValueError("onboarding manifest digest mismatch")

    files = manifest.get("files")
    if not isinstance(files, list):
        raise ValueError("onboarding manifest files invalid")
    expected: dict[str, dict[str, Any]] = {}
    for row in files:
        if not isinstance(row, dict):
            raise ValueError("onboarding manifest entry invalid")
        rel = str(row.get("path") or "")
        source_freeze.safe_name(rel)
        if rel in expected or not isinstance(row.get("bytes"), int) or not isinstance(row.get("sha256"), str):
            raise ValueError(f"onboarding manifest entry invalid: {row}")
        expected[rel] = row
    actual = {
        p.relative_to(root).as_posix() for p in root.rglob("*")
        if p.is_file() and p.relative_to(root).as_posix() not in {MANIFEST_NAME, DIGEST_NAME}
    }
    if actual != set(expected):
        raise ValueError(f"onboarding closed-world mismatch: missing={sorted(set(expected)-actual)[:8]} extra={sorted(actual-set(expected))[:8]}")
    if manifest.get("file_count") != len(expected):
        raise ValueError("onboarding file_count mismatch")
    for rel, row in expected.items():
        path = root / rel
        if not path.is_file() or path.is_symlink():
            raise ValueError(f"onboarding file missing/unsafe: {rel}")
        if path.stat().st_size != row["bytes"] or sha256_file(path) != row["sha256"]:
            raise ValueError(f"onboarding file hash/size mismatch: {rel}")

    checkpoint = root / CHECKPOINT_NAME
    cp = target.checkpoint_verification(repo, checkpoint)
    for key in ("checkpoint_sha256", "source_manifest_sha256", "source_manifest_entries", "workflow_sha256"):
        if onboarding.get(key) != cp[key] or manifest.get(key) != cp[key]:
            raise ValueError(f"onboarding checkpoint {key} mismatch")

    request = load_json(root / REQUEST_NAME)
    if request.get("schema") != target.REQUEST_SCHEMA or request.get("status") != "WAITING_EXTERNAL_TARGET_BINDING" or request.get("release_authorizing") is not False:
        raise ValueError("onboarding target request invalid")
    if onboarding.get("target_request_sha256") != sha256_file(root / REQUEST_NAME):
        raise ValueError("onboarding target request digest mismatch")
    for key in ("checkpoint_sha256", "source_manifest_sha256", "source_manifest_entries", "workflow_sha256"):
        if request.get(key) != cp[key]:
            raise ValueError(f"onboarding target request {key} mismatch")

    policy = target.load_policy(repo)
    requirements = load_json(root / REQUIREMENTS_NAME)
    if requirements.get("schema") != METADATA_REQUIREMENTS_SCHEMA or requirements.get("required_server_url") != policy["required_server_url"]:
        raise ValueError("repository metadata requirements invalid")
    if sha256_file(root / TARGET_POLICY_NAME) != sha256_file(repo / target.POLICY):
        raise ValueError("onboarding target policy copy mismatch")
    if sha256_file(root / MATERIALIZATION_POLICY_NAME) != sha256_file(repo / "config/repository-materialization-policy.json"):
        raise ValueError("onboarding materialization policy copy mismatch")
    if sha256_file(root / MATERIALIZATION_RUNBOOK_NAME) != sha256_file(repo / "docs/GITHUB_REPOSITORY_MATERIALIZATION.md"):
        raise ValueError("onboarding materialization runbook copy mismatch")
    secret_scan(root)

    return {
        "schema": "nadi.github-repository-onboarding-verification.v1",
        "status": "PASS",
        "release_authorizing": False,
        "checkpoint_sha256": cp["checkpoint_sha256"],
        "source_manifest_sha256": cp["source_manifest_sha256"],
        "source_manifest_entries": cp["source_manifest_entries"],
        "workflow_sha256": cp["workflow_sha256"],
        "file_count": len(expected),
        "next_state": STATUS,
    }


def verify_package(repo: Path, artifact: Path) -> dict[str, Any]:
    if artifact.is_symlink():
        raise ValueError("onboarding artifact must not be a symlink")
    if artifact.is_dir():
        return verify_root(repo, artifact.resolve())
    if not artifact.is_file() or not zipfile.is_zipfile(artifact):
        raise ValueError("onboarding artifact must be a directory or ZIP")
    with tempfile.TemporaryDirectory(prefix="nadi-github-onboarding-") as td:
        root = Path(td)
        safe_extract(artifact.resolve(), root)
        return verify_root(repo, root)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)

    c = sub.add_parser("create")
    c.add_argument("--repo-root", default=".")
    c.add_argument("--checkpoint-zip", required=True)
    c.add_argument("--release-version")
    c.add_argument("--output-dir", required=True)
    c.add_argument("--output-zip")

    v = sub.add_parser("verify")
    v.add_argument("--repo-root", default=".")
    v.add_argument("--artifact", required=True)

    args = parser.parse_args()
    try:
        repo = Path(args.repo_root).resolve()
        if args.command == "create":
            result = create_package(
                repo,
                Path(args.checkpoint_zip).resolve(),
                args.release_version,
                Path(args.output_dir).resolve(),
                Path(args.output_zip).resolve() if args.output_zip else None,
            )
        else:
            result = verify_package(repo, Path(args.artifact).resolve())
        print(json.dumps(result, indent=2, sort_keys=True))
        return 0
    except (ValueError, OSError, json.JSONDecodeError, zipfile.BadZipFile) as exc:
        print(f"FAIL: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
