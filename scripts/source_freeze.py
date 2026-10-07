#!/usr/bin/env python3
"""NADI final source-freeze verification and external runtime execution handoff.

This tool never creates runtime/release PASS evidence. It binds the exact source
checkpoint to the external execution contract. Any source mutation requires a new
checkpoint and a new handoff.
"""
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

import release_closure_matrix as rcm

POLICY_SCHEMA = "nadi.source-freeze-policy.v1"
FREEZE_SCHEMA = "nadi.source-freeze-receipt.v1"
HANDOFF_SCHEMA = "nadi.external-runtime-handoff.v1"
MANIFEST_SCHEMA = "nadi.external-runtime-handoff-manifest.v1"
POLICY_PATH = Path("config/source-freeze-policy.json")
FREEZE_NAME = "SOURCE_FREEZE_RECEIPT.json"
HANDOFF_NAME = "EXTERNAL_RUNTIME_HANDOFF.json"
MANIFEST_NAME = "EXTERNAL_RUNTIME_HANDOFF_MANIFEST.json"
MANIFEST_DIGEST_NAME = "EXTERNAL_RUNTIME_HANDOFF_MANIFEST.json.sha256"
CHECKPOINT_NAME = "SOURCE_CHECKPOINT.zip"
RUNTIME_COPY = "RELEASE_ENVIRONMENT.json"
MATRIX_COPY = "RELEASE_CLOSURE_MATRIX.json"
POLICY_COPY = "SOURCE_FREEZE_POLICY.json"
RUNBOOK_COPY = "EXTERNAL_RUNTIME_EXECUTION.md"
TARGET_BINDING_COPY = "EXTERNAL_RUNTIME_TARGET_BINDING.json"
TARGET_BINDING_SCHEMA = "nadi.external-runtime-target-binding.v1"
TARGET_POLICY_PATH = Path("config/external-runtime-target-policy.json")
TARGET_POLICY_SCHEMA = "nadi.external-runtime-target-policy.v1"
VERSION_RE = re.compile(r"^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$")
REPO_RE = re.compile(r"^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$")
MAX_FILES = 2000
MAX_TOTAL = 2 * 1024 * 1024 * 1024
MAX_MEMBER = 1024 * 1024 * 1024
PRIVATE_KEY_MARKERS = (
    "-----BEGIN PRIVATE KEY-----",
    "-----BEGIN RSA PRIVATE KEY-----",
    "-----BEGIN OPENSSH PRIVATE KEY-----",
)
SENSITIVE_ASSIGNMENT_RE = re.compile(
    r"(?im)^\s*(APP_KEY|DB_PASSWORD|GITHUB_TOKEN|GH_TOKEN|GITHUB_PAT|GITHUB_ENTERPRISE_TOKEN|NADI_ACCEPTANCE_PASSWORD|NADI_BROWSER_FORCED_PASSWORD|NADI_BROWSER_VIEWER_PASSWORD)\s*=\s*(.+)$"
)


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def require_file(path: Path, label: str) -> None:
    if not path.is_file() or path.is_symlink():
        raise ValueError(f"{label} missing/not regular: {path}")


def load_json(path: Path) -> dict[str, Any]:
    require_file(path, "JSON file")
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise ValueError(f"JSON object required: {path}")
    return data


def evidence_now() -> datetime:
    raw = os.environ.get("SOURCE_DATE_EPOCH", "").strip()
    if raw:
        try:
            return datetime.fromtimestamp(int(raw), tz=timezone.utc)
        except ValueError as exc:
            raise ValueError("SOURCE_DATE_EPOCH must be integer") from exc
    return datetime.now(timezone.utc)


def safe_name(raw: str) -> str:
    value = raw.replace("\\", "/")
    if not value or value.startswith("/") or "\x00" in value:
        raise ValueError(f"unsafe archive member: {raw!r}")
    parts = Path(value).parts
    if any(p in {"", ".", ".."} for p in parts) or (parts and parts[0].endswith(":")):
        raise ValueError(f"unsafe archive member: {raw!r}")
    return Path(*parts).as_posix()


def safe_extract(source: Path, destination: Path) -> None:
    require_file(source, "ZIP")
    if not zipfile.is_zipfile(source):
        raise ValueError(f"not a ZIP archive: {source}")
    total = 0
    seen: set[str] = set()
    with zipfile.ZipFile(source, "r") as zf:
        infos = zf.infolist()
        if len(infos) > MAX_FILES:
            raise ValueError("archive entry count exceeds safety limit")
        normalized: list[tuple[zipfile.ZipInfo, str]] = []
        for info in infos:
            raw = info.filename.rstrip("/")
            if not raw:
                continue
            name = safe_name(raw)
            if name in seen:
                raise ValueError(f"duplicate archive member: {name}")
            seen.add(name)
            mode = (info.external_attr >> 16) & 0o170000
            if mode == stat.S_IFLNK:
                raise ValueError(f"archive symlink refused: {name}")
            if info.file_size > MAX_MEMBER:
                raise ValueError(f"archive member too large: {name}")
            total += info.file_size
            if total > MAX_TOTAL:
                raise ValueError("archive uncompressed size exceeds safety limit")
            normalized.append((info, name))
        for info, name in normalized:
            target = destination / name
            target.parent.mkdir(parents=True, exist_ok=True)
            if info.is_dir():
                target.mkdir(parents=True, exist_ok=True)
            else:
                with zf.open(info, "r") as src, target.open("wb") as dst:
                    shutil.copyfileobj(src, dst, length=1024 * 1024)
    for path in destination.rglob("*"):
        if path.is_symlink():
            raise ValueError(f"extracted symlink refused: {path.relative_to(destination)}")


def load_policy(repo: Path) -> tuple[dict[str, Any], str]:
    path = repo / POLICY_PATH
    policy = load_json(path)
    if policy.get("schema") != POLICY_SCHEMA or policy.get("version") != 1:
        raise ValueError("source freeze policy schema/version invalid")
    if policy.get("freeze_iteration") != "15.18":
        raise ValueError("source freeze iteration mismatch")
    if policy.get("authoritative_gate") != "scripts/final-gate.sh":
        raise ValueError("source freeze authoritative gate invalid")
    if policy.get("authoritative_workflow") != ".github/workflows/release-gates.yml":
        raise ValueError("source freeze workflow invalid")
    if policy.get("required_event") != "workflow_dispatch" or policy.get("required_ref") != "refs/heads/main":
        raise ValueError("source freeze external release authority invalid")
    if policy.get("mutation_rule") != "new_iteration_and_refreeze_required":
        raise ValueError("source freeze mutation rule invalid")
    if policy.get("closed_world_source_required") is not True:
        raise ValueError("source freeze must require closed-world source verification")
    paths = policy.get("required_source_bound_paths")
    if not isinstance(paths, list) or not paths or len(paths) != len(set(paths)):
        raise ValueError("source freeze required paths invalid")
    return policy, sha256_file(path)


def source_manifest_paths(repo: Path) -> set[str]:
    manifest = repo / "AUDITED_SOURCE_RC_MANIFEST.sha256"
    require_file(manifest, "source manifest")
    paths: set[str] = set()
    for raw in manifest.read_text(encoding="utf-8").splitlines():
        if not raw.strip():
            continue
        m = re.fullmatch(r"[0-9a-f]{64}  (.+)", raw)
        if not m:
            raise ValueError(f"invalid source manifest line: {raw[:120]}")
        rel = m.group(1)
        parts = Path(rel).parts
        if rel in paths:
            raise ValueError(f"duplicate source manifest path: {rel}")
        if "__pycache__" in parts or rel.endswith(".pyc") or any(part in {"vendor", "node_modules", "artifacts"} for part in parts):
            raise ValueError(f"transient/dependency path must not be source-manifest-bound: {rel}")
        paths.add(rel)
    return paths


def verify_source(repo: Path) -> dict[str, Any]:
    repo = repo.resolve()
    entries, source_sha = rcm.verify_source_manifest(repo)
    rcm.load_matrix(repo)
    policy, policy_sha = load_policy(repo)
    listed = source_manifest_paths(repo)
    actual: set[str] = set()
    for path in repo.rglob("*"):
        if not path.is_file() or path.is_symlink():
            continue
        rel = path.relative_to(repo).as_posix()
        parts = Path(rel).parts
        if rel == "AUDITED_SOURCE_RC_MANIFEST.sha256":
            continue
        if "__pycache__" in parts or ".git" in parts or rel.endswith(".pyc") or any(part in {"vendor", "node_modules", "artifacts"} for part in parts):
            continue
        if parts and parts[0] in {"dist-gate-a", "dist-gate-b", "dist-final"}:
            continue
        actual.add(rel)
    if actual != listed:
        raise ValueError(
            f"source checkpoint closed-world mismatch: missing={sorted(listed-actual)[:8]} extra={sorted(actual-listed)[:8]}"
        )
    missing = [p for p in policy["required_source_bound_paths"] if p not in listed]
    if missing:
        raise ValueError(f"release-critical source files are not source-manifest-bound: {missing}")
    for rel in policy["required_source_bound_paths"]:
        require_file(repo / rel, f"required source-bound path {rel}")

    workflow = (repo / policy["authoritative_workflow"]).read_text(encoding="utf-8")
    required_workflow_markers = (
        "workflow_dispatch:",
        "release_version:",
        "branches: [main]",
        "bash scripts/final-gate.sh",
        "scripts/ci_evidence.py bundle",
    )
    for marker in required_workflow_markers:
        if marker not in workflow:
            raise ValueError(f"authoritative workflow missing required marker: {marker}")

    gate = (repo / policy["authoritative_gate"]).read_text(encoding="utf-8")
    if "scripts/source_freeze.py verify-source" not in gate:
        raise ValueError("authoritative final gate does not verify source-freeze policy")
    if "scripts/release_closure_matrix.py validate" not in gate:
        raise ValueError("authoritative final gate no longer validates release matrix")

    result = {
        "schema": "nadi.source-freeze-verification.v1",
        "status": "PASS",
        "freeze_iteration": policy["freeze_iteration"],
        "source_manifest_entries": entries,
        "source_manifest_sha256": source_sha,
        "source_freeze_policy_sha256": policy_sha,
        "authoritative_gate_sha256": sha256_file(repo / policy["authoritative_gate"]),
        "authoritative_workflow_sha256": sha256_file(repo / policy["authoritative_workflow"]),
        "release_environment_sha256": sha256_file(repo / "config/release-environment.json"),
        "release_matrix_sha256": sha256_file(repo / "config/release-closure-verification-matrix.json"),
        "mutation_rule": policy["mutation_rule"],
        "closed_world_source": True,
    }
    return result


def scan_handoff_secrets(root: Path) -> None:
    forbidden = {".env", ".env.production", ".env.local", "id_rsa", "id_ed25519"}
    for path in root.rglob("*"):
        if not path.is_file() or path.name in {MANIFEST_NAME, MANIFEST_DIGEST_NAME, CHECKPOINT_NAME}:
            continue
        if path.name in forbidden or path.name.startswith(".env."):
            raise ValueError(f"handoff contains forbidden secret/runtime file: {path.relative_to(root)}")
        if path.stat().st_size > 5 * 1024 * 1024:
            continue
        try:
            text = path.read_text(encoding="utf-8")
        except (UnicodeDecodeError, OSError):
            continue
        if any(marker in text for marker in PRIVATE_KEY_MARKERS):
            raise ValueError(f"handoff contains private-key material: {path.relative_to(root)}")
        match = SENSITIVE_ASSIGNMENT_RE.search(text)
        if match:
            raise ValueError(f"handoff contains credential assignment {match.group(1)} in {path.relative_to(root)}")


def manifest_entries(root: Path) -> list[dict[str, Any]]:
    out: list[dict[str, Any]] = []
    for path in sorted(root.rglob("*"), key=lambda p: p.relative_to(root).as_posix()):
        if not path.is_file():
            continue
        rel = path.relative_to(root).as_posix()
        if rel in {MANIFEST_NAME, MANIFEST_DIGEST_NAME}:
            continue
        out.append({"path": rel, "bytes": path.stat().st_size, "sha256": sha256_file(path)})
    return out


def write_manifest(root: Path, meta: dict[str, Any]) -> None:
    files = manifest_entries(root)
    payload = {
        "schema": MANIFEST_SCHEMA,
        "status": "HANDOFF_READY",
        "expected_repository": meta["expected_repository"],
        "checkpoint_sha256": meta["checkpoint_sha256"],
        "source_manifest_sha256": meta["source_manifest_sha256"],
        "file_count": len(files),
        "files": files,
    }
    path = root / MANIFEST_NAME
    path.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    (root / MANIFEST_DIGEST_NAME).write_text(f"{sha256_file(path)}  {MANIFEST_NAME}\n", encoding="utf-8")


def verify_manifest(root: Path) -> dict[str, Any]:
    manifest = load_json(root / MANIFEST_NAME)
    if manifest.get("schema") != MANIFEST_SCHEMA or manifest.get("status") != "HANDOFF_READY":
        raise ValueError("external handoff manifest schema/status invalid")
    digest = (root / MANIFEST_DIGEST_NAME)
    require_file(digest, "handoff manifest digest")
    expected_sidecar = f"{sha256_file(root / MANIFEST_NAME)}  {MANIFEST_NAME}"
    if digest.read_text(encoding="utf-8").strip() != expected_sidecar:
        raise ValueError("external handoff manifest digest mismatch")
    files = manifest.get("files")
    if not isinstance(files, list):
        raise ValueError("external handoff manifest files invalid")
    expected: dict[str, dict[str, Any]] = {}
    for row in files:
        if not isinstance(row, dict):
            raise ValueError("external handoff manifest entry invalid")
        rel = str(row.get("path") or "")
        safe_name(rel)
        if rel in expected or not re.fullmatch(r"[0-9a-f]{64}", str(row.get("sha256") or "")) or not isinstance(row.get("bytes"), int):
            raise ValueError(f"external handoff manifest entry invalid: {row}")
        expected[rel] = row
    actual = {
        p.relative_to(root).as_posix() for p in root.rglob("*")
        if p.is_file() and p.relative_to(root).as_posix() not in {MANIFEST_NAME, MANIFEST_DIGEST_NAME}
    }
    if actual != set(expected):
        raise ValueError(f"external handoff closed-world mismatch: missing={sorted(set(expected)-actual)} extra={sorted(actual-set(expected))}")
    if manifest.get("file_count") != len(expected):
        raise ValueError("external handoff file_count mismatch")
    for rel, row in expected.items():
        path = root / rel
        require_file(path, f"handoff file {rel}")
        if path.stat().st_size != row["bytes"] or sha256_file(path) != row["sha256"]:
            raise ValueError(f"external handoff file hash/size mismatch: {rel}")
    return manifest


def deterministic_zip(root: Path, output: Path) -> None:
    if output.exists() or output.is_symlink():
        raise ValueError(f"output ZIP already exists: {output}")
    output.parent.mkdir(parents=True, exist_ok=True)
    dt = evidence_now()
    year = max(dt.year, 1980)
    zdt = (year, dt.month, dt.day, dt.hour, dt.minute, dt.second - dt.second % 2)
    with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
        for path in sorted(root.rglob("*"), key=lambda p: p.relative_to(root).as_posix()):
            if not path.is_file():
                continue
            rel = path.relative_to(root).as_posix()
            info = zipfile.ZipInfo(rel, zdt)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.create_system = 3
            mode = 0o755 if (path.stat().st_mode & 0o111) else 0o644
            info.external_attr = (stat.S_IFREG | mode) << 16
            zf.writestr(info, path.read_bytes())


def copy_runbook(repo: Path, dest: Path) -> None:
    source = repo / "docs" / "EXTERNAL_RUNTIME_EXECUTION_HANDOFF.md"
    require_file(source, "external runtime execution handoff runbook")
    shutil.copy2(source, dest / RUNBOOK_COPY)


def verify_target_binding_contract(repo: Path, binding: dict[str, Any], expected_repo: str, checkpoint_sha: str, verification: dict[str, Any]) -> None:
    target_policy = load_json(repo / TARGET_POLICY_PATH)
    if target_policy.get("schema") != TARGET_POLICY_SCHEMA or target_policy.get("version") != 1:
        raise ValueError("external runtime target policy schema/version invalid")
    if target_policy.get("binding_iteration") != "15.18":
        raise ValueError("external runtime target policy iteration mismatch")
    if target_policy.get("required_materialization_confirmation_schema") != "nadi.repository-materialization-confirmation.v1":
        raise ValueError("external runtime target materialization confirmation policy invalid")
    if binding.get("schema") != TARGET_BINDING_SCHEMA or binding.get("status") != "TARGET_BOUND":
        raise ValueError("external runtime target binding schema/status invalid")
    if binding.get("release_authorizing") is not True or binding.get("operator_real_target_confirmation") is not True:
        raise ValueError("external runtime target binding is not operator-confirmed/release-authorizing")
    if binding.get("binding_source") != target_policy.get("required_binding_source"):
        raise ValueError("external runtime target binding is not backed by required GitHub repository metadata")
    if binding.get("repository_metadata_provenance_confirmed") is not True:
        raise ValueError("external runtime target binding repository metadata provenance not confirmed")
    if target_policy.get("repository_materialization_required") is not True or target_policy.get("materialized_commit_sha_required") is not True:
        raise ValueError("external runtime target policy no longer requires repository materialization")
    if binding.get("repository_materialization_confirmed") is not True:
        raise ValueError("external runtime target binding repository materialization not confirmed")
    materialized_commit = str(binding.get("materialized_commit_sha") or "").lower()
    if not re.fullmatch(r"[0-9a-f]{40}", materialized_commit):
        raise ValueError("external runtime target binding materialized commit SHA invalid")
    for key in ("materialization_confirmation_sha256", "materialization_json_sha256", "materialization_manifest_sha256", "git_bundle_sha256", "remote_ref_metadata_sha256"):
        if not re.fullmatch(r"[0-9a-f]{64}", str(binding.get(key) or "")):
            raise ValueError(f"external runtime target binding {key} invalid")
    metadata_sha = str(binding.get("repository_metadata_sha256") or "")
    if not re.fullmatch(r"[0-9a-f]{64}", metadata_sha):
        raise ValueError("external runtime target binding repository metadata SHA-256 invalid")
    if binding.get("repository") != expected_repo:
        raise ValueError("external runtime target binding repository mismatch")
    repo_id = str(binding.get("repository_id") or "")
    if not repo_id.isdigit() or int(repo_id) <= 0:
        raise ValueError("external runtime target binding repository ID invalid")
    expected_fields = {
        "provider": target_policy.get("provider"),
        "server_url": target_policy.get("required_server_url"),
        "default_branch": target_policy.get("required_default_branch"),
        "required_ref": target_policy.get("required_ref"),
        "required_event": target_policy.get("required_event"),
        "workflow_path": target_policy.get("required_workflow_path"),
        "workflow_name": target_policy.get("required_workflow_name"),
        "required_job": target_policy.get("required_job"),
    }
    for key, expected in expected_fields.items():
        if binding.get(key) != expected:
            raise ValueError(f"external runtime target binding {key} mismatch")
    if binding.get("checkpoint_sha256") != checkpoint_sha:
        raise ValueError("external runtime target binding checkpoint SHA-256 mismatch")
    if binding.get("source_manifest_sha256") != verification["source_manifest_sha256"]:
        raise ValueError("external runtime target binding source manifest mismatch")
    if binding.get("source_manifest_entries") != verification["source_manifest_entries"]:
        raise ValueError("external runtime target binding source entry count mismatch")
    if binding.get("workflow_sha256") != verification["authoritative_workflow_sha256"]:
        raise ValueError("external runtime target binding workflow fingerprint mismatch")


def create_handoff(args: argparse.Namespace) -> dict[str, Any]:
    checkpoint = Path(args.checkpoint_zip).resolve()
    require_file(checkpoint, "source checkpoint ZIP")
    if not zipfile.is_zipfile(checkpoint):
        raise ValueError("source checkpoint must be a ZIP")
    expected_repo = args.expected_repository.strip()
    if not REPO_RE.fullmatch(expected_repo):
        raise ValueError("expected repository must be owner/repository")
    target_binding_path = Path(args.target_binding).resolve()
    target_binding = load_json(target_binding_path)
    release_version = args.release_version.strip() if args.release_version else None
    if release_version and not VERSION_RE.fullmatch(release_version):
        raise ValueError("release version must be semantic version")
    output_dir = Path(args.output_dir).resolve()
    if output_dir.exists() or output_dir.is_symlink():
        raise ValueError("handoff output directory already exists")

    with tempfile.TemporaryDirectory(prefix="nadi-source-freeze-") as td:
        extracted = Path(td) / "source"
        extracted.mkdir()
        safe_extract(checkpoint, extracted)
        verification = verify_source(extracted)
        policy, policy_sha = load_policy(extracted)
        checkpoint_sha_input = sha256_file(checkpoint)
        verify_target_binding_contract(extracted, target_binding, expected_repo, checkpoint_sha_input, verification)
        output_dir.mkdir(parents=True)
        shutil.copy2(checkpoint, output_dir / CHECKPOINT_NAME)
        shutil.copy2(extracted / "config/release-environment.json", output_dir / RUNTIME_COPY)
        shutil.copy2(extracted / "config/release-closure-verification-matrix.json", output_dir / MATRIX_COPY)
        shutil.copy2(extracted / POLICY_PATH, output_dir / POLICY_COPY)
        copy_runbook(extracted, output_dir)
        shutil.copy2(target_binding_path, output_dir / TARGET_BINDING_COPY)
        checkpoint_sha = sha256_file(output_dir / CHECKPOINT_NAME)
        created = evidence_now().isoformat()
        freeze = {
            "schema": FREEZE_SCHEMA,
            "status": "SOURCE_FROZEN",
            "freeze_iteration": policy["freeze_iteration"],
            "checkpoint_filename": checkpoint.name,
            "checkpoint_sha256": checkpoint_sha,
            "expected_repository": expected_repo,
            "repository_id": str(target_binding.get("repository_id")),
            "materialized_commit_sha": str(target_binding.get("materialized_commit_sha")),
            "target_binding_sha256": sha256_file(output_dir / TARGET_BINDING_COPY),
            "source_manifest_entries": verification["source_manifest_entries"],
            "source_manifest_sha256": verification["source_manifest_sha256"],
            "source_freeze_policy_sha256": policy_sha,
            "authoritative_gate_sha256": verification["authoritative_gate_sha256"],
            "authoritative_workflow_sha256": verification["authoritative_workflow_sha256"],
            "release_environment_sha256": verification["release_environment_sha256"],
            "release_matrix_sha256": verification["release_matrix_sha256"],
            "mutation_rule": policy["mutation_rule"],
            "created_at_utc": created,
        }
        (output_dir / FREEZE_NAME).write_text(json.dumps(freeze, indent=2, sort_keys=True) + "\n", encoding="utf-8")
        handoff = {
            "schema": HANDOFF_SCHEMA,
            "status": "EXECUTION_HANDOFF_READY",
            "freeze_iteration": policy["freeze_iteration"],
            "expected_repository": expected_repo,
            "repository_id": str(target_binding.get("repository_id")),
            "materialized_commit_sha": str(target_binding.get("materialized_commit_sha")),
            "target_binding_file": TARGET_BINDING_COPY,
            "target_binding_sha256": sha256_file(output_dir / TARGET_BINDING_COPY),
            "required_event": policy["required_event"],
            "required_ref": policy["required_ref"],
            "release_version_input": policy["release_version_input"],
            "requested_release_version": release_version,
            "release_version_mode": "fixed_in_handoff" if release_version else "operator_supplied_at_workflow_dispatch",
            "checkpoint_file": CHECKPOINT_NAME,
            "checkpoint_sha256": checkpoint_sha,
            "source_manifest_sha256": verification["source_manifest_sha256"],
            "source_manifest_entries": verification["source_manifest_entries"],
            "authoritative_gate": policy["authoritative_gate"],
            "authoritative_workflow": policy["authoritative_workflow"],
            "expected_ci_artifact_pattern": "nadi-ci-release-evidence-<run-id>-<run-attempt>",
            "next_consumer": "scripts/consume_external_ci.py",
            "source_mutation_after_handoff": "prohibited_new_iteration_and_refreeze_required",
            "created_at_utc": created,
        }
        (output_dir / HANDOFF_NAME).write_text(json.dumps(handoff, indent=2, sort_keys=True) + "\n", encoding="utf-8")
        scan_handoff_secrets(output_dir)
        write_manifest(output_dir, handoff)
        verify_handoff_root(output_dir, expected_repo)

    if args.output_zip:
        deterministic_zip(output_dir, Path(args.output_zip).resolve())
    return handoff


def verify_handoff_root(root: Path, expected_repository: str | None) -> dict[str, Any]:
    root = root.resolve()
    scan_handoff_secrets(root)
    manifest = verify_manifest(root)
    freeze = load_json(root / FREEZE_NAME)
    handoff = load_json(root / HANDOFF_NAME)
    target_binding = load_json(root / TARGET_BINDING_COPY)
    if freeze.get("schema") != FREEZE_SCHEMA or freeze.get("status") != "SOURCE_FROZEN":
        raise ValueError("source freeze receipt schema/status invalid")
    if handoff.get("schema") != HANDOFF_SCHEMA or handoff.get("status") != "EXECUTION_HANDOFF_READY":
        raise ValueError("external runtime handoff schema/status invalid")
    if target_binding.get("schema") != TARGET_BINDING_SCHEMA or target_binding.get("status") != "TARGET_BOUND":
        raise ValueError("external runtime target binding schema/status invalid")
    if target_binding.get("release_authorizing") is not True or target_binding.get("operator_real_target_confirmation") is not True:
        raise ValueError("external runtime target binding is not release-authorizing")
    expected_repo = expected_repository or str(handoff.get("expected_repository") or "")
    if not REPO_RE.fullmatch(expected_repo):
        raise ValueError("expected repository must be owner/repository")
    if handoff.get("expected_repository") != expected_repo or freeze.get("expected_repository") != expected_repo or manifest.get("expected_repository") != expected_repo:
        raise ValueError("external runtime handoff repository mismatch")
    if target_binding.get("repository") != expected_repo:
        raise ValueError("external runtime target binding repository mismatch")
    if str(target_binding.get("repository_id")) != str(handoff.get("repository_id")) or str(target_binding.get("repository_id")) != str(freeze.get("repository_id")):
        raise ValueError("external runtime handoff repository ID mismatch")
    commit_sha = str(target_binding.get("materialized_commit_sha") or "").lower()
    if commit_sha != str(handoff.get("materialized_commit_sha") or "").lower() or commit_sha != str(freeze.get("materialized_commit_sha") or "").lower():
        raise ValueError("external runtime handoff materialized commit mismatch")
    binding_sha = sha256_file(root / TARGET_BINDING_COPY)
    if binding_sha != handoff.get("target_binding_sha256") or binding_sha != freeze.get("target_binding_sha256"):
        raise ValueError("external runtime target binding hash mismatch")
    checkpoint = root / CHECKPOINT_NAME
    require_file(checkpoint, "source checkpoint")
    checkpoint_sha = sha256_file(checkpoint)
    if checkpoint_sha != freeze.get("checkpoint_sha256") or checkpoint_sha != handoff.get("checkpoint_sha256") or checkpoint_sha != manifest.get("checkpoint_sha256"):
        raise ValueError("external runtime handoff checkpoint hash mismatch")

    with tempfile.TemporaryDirectory(prefix="nadi-handoff-verify-") as td:
        extracted = Path(td) / "source"
        extracted.mkdir()
        safe_extract(checkpoint, extracted)
        verification = verify_source(extracted)
        policy, policy_sha = load_policy(extracted)
        verify_target_binding_contract(extracted, target_binding, expected_repo, checkpoint_sha, verification)
        if verification["source_manifest_sha256"] != freeze.get("source_manifest_sha256") or verification["source_manifest_sha256"] != handoff.get("source_manifest_sha256") or verification["source_manifest_sha256"] != manifest.get("source_manifest_sha256"):
            raise ValueError("external runtime handoff source fingerprint mismatch")
        if target_binding.get("source_manifest_sha256") != verification["source_manifest_sha256"]:
            raise ValueError("external runtime target binding source fingerprint mismatch")
        if target_binding.get("checkpoint_sha256") != checkpoint_sha:
            raise ValueError("external runtime target binding checkpoint hash mismatch")
        if target_binding.get("workflow_sha256") != verification["authoritative_workflow_sha256"]:
            raise ValueError("external runtime target binding workflow hash mismatch")
        if verification["source_manifest_entries"] != freeze.get("source_manifest_entries") or verification["source_manifest_entries"] != handoff.get("source_manifest_entries"):
            raise ValueError("external runtime handoff source entry count mismatch")
        comparisons = {
            RUNTIME_COPY: extracted / "config/release-environment.json",
            MATRIX_COPY: extracted / "config/release-closure-verification-matrix.json",
            POLICY_COPY: extracted / POLICY_PATH,
            RUNBOOK_COPY: extracted / "docs/EXTERNAL_RUNTIME_EXECUTION_HANDOFF.md",
        }
        for copied, source in comparisons.items():
            if sha256_file(root / copied) != sha256_file(source):
                raise ValueError(f"external runtime handoff copied contract mismatch: {copied}")
        if freeze.get("source_freeze_policy_sha256") != policy_sha:
            raise ValueError("source freeze policy fingerprint mismatch")
        if handoff.get("required_event") != policy["required_event"] or handoff.get("required_ref") != policy["required_ref"]:
            raise ValueError("external runtime authority contract mismatch")
        version = handoff.get("requested_release_version")
        if version is not None and not VERSION_RE.fullmatch(str(version)):
            raise ValueError("external runtime handoff semantic version invalid")
    return {
        "schema": "nadi.external-runtime-handoff-verification.v1",
        "status": "PASS",
        "expected_repository": expected_repo,
        "repository_id": str(target_binding.get("repository_id")),
        "materialized_commit_sha": str(target_binding.get("materialized_commit_sha")),
        "checkpoint_sha256": checkpoint_sha,
        "source_manifest_sha256": handoff["source_manifest_sha256"],
        "source_manifest_entries": handoff["source_manifest_entries"],
        "release_version_mode": handoff["release_version_mode"],
    }


def verify_handoff(args: argparse.Namespace) -> dict[str, Any]:
    artifact = Path(args.artifact).resolve()
    expected = args.expected_repository.strip() if args.expected_repository else None
    if artifact.is_dir():
        return verify_handoff_root(artifact, expected)
    require_file(artifact, "handoff artifact")
    with tempfile.TemporaryDirectory(prefix="nadi-handoff-artifact-") as td:
        root = Path(td) / "handoff"
        root.mkdir()
        safe_extract(artifact, root)
        return verify_handoff_root(root, expected)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)

    p_verify_source = sub.add_parser("verify-source", help="Verify the frozen source checkpoint contract")
    p_verify_source.add_argument("--repo-root", default=".")

    p_create = sub.add_parser("create-handoff", help="Create closed-world external runtime execution handoff")
    p_create.add_argument("--checkpoint-zip", required=True)
    p_create.add_argument("--expected-repository", required=True)
    p_create.add_argument("--target-binding", required=True)
    p_create.add_argument("--release-version")
    p_create.add_argument("--output-dir", required=True)
    p_create.add_argument("--output-zip")

    p_verify = sub.add_parser("verify-handoff", help="Verify external runtime execution handoff directory/ZIP")
    p_verify.add_argument("--artifact", required=True)
    p_verify.add_argument("--expected-repository")

    args = parser.parse_args()
    try:
        if args.command == "verify-source":
            result = verify_source(Path(args.repo_root))
        elif args.command == "create-handoff":
            result = create_handoff(args)
        else:
            result = verify_handoff(args)
        print(json.dumps(result, indent=2, sort_keys=True))
        return 0
    except (ValueError, OSError, json.JSONDecodeError, zipfile.BadZipFile) as exc:
        print(f"FAIL: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
