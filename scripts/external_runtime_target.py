#!/usr/bin/env python3
"""Bind and verify the exact external GitHub Actions target for NADI release execution.

This tool creates no runtime PASS evidence. A binding only identifies the repository/runtime
identity that is allowed to produce evidence for the frozen source checkpoint.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import shutil
import stat
import sys
import tempfile
import zipfile
from pathlib import Path
from typing import Any

import source_freeze

SCHEMA = "nadi.external-runtime-target-binding.v1"
REQUEST_SCHEMA = "nadi.external-runtime-target-request.v1"
POLICY_SCHEMA = "nadi.external-runtime-target-policy.v1"
POLICY = Path("config/external-runtime-target-policy.json")
BINDING_NAME = "EXTERNAL_RUNTIME_TARGET_BINDING.json"
REQUEST_NAME = "EXTERNAL_RUNTIME_TARGET_REQUEST.json"
REPO_RE = re.compile(r"^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$")
VERSION_RE = re.compile(r"^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$")
HEX40_RE = re.compile(r"^[0-9a-f]{40}$", re.I)
HEX64_RE = re.compile(r"^[0-9a-f]{64}$", re.I)
MATERIALIZATION_CONFIRMATION_SCHEMA = "nadi.repository-materialization-confirmation.v1"


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


def load_policy(repo: Path) -> dict[str, Any]:
    policy = load_json(repo / POLICY)
    if policy.get("schema") != POLICY_SCHEMA or policy.get("version") != 1:
        raise ValueError("external runtime target policy schema/version invalid")
    if policy.get("provider") != "github-actions":
        raise ValueError("unsupported external runtime provider")
    if policy.get("binding_iteration") != "15.18":
        raise ValueError("external runtime target binding iteration mismatch")
    if policy.get("required_default_branch") != "main" or policy.get("required_ref") != "refs/heads/main":
        raise ValueError("external runtime target branch/ref policy invalid")
    if policy.get("required_event") != "workflow_dispatch":
        raise ValueError("external runtime target event policy invalid")
    if policy.get("target_binding_required_for_external_ci_consumption") is not True:
        raise ValueError("external runtime target binding must be required for CI consumption")
    if policy.get("unbound_target_is_release_authorizing") is not False:
        raise ValueError("unbound target must never be release-authorizing")
    if policy.get("repository_metadata_required") is not True:
        raise ValueError("real target binding must require GitHub repository metadata")
    if policy.get("required_binding_source") != "github_repository_api_metadata":
        raise ValueError("external runtime target binding source policy invalid")
    if policy.get("manual_binding_release_authorizing") is not False:
        raise ValueError("manual repository identity must not be release-authorizing")
    if policy.get("repository_materialization_required") is not True or policy.get("materialized_commit_sha_required") is not True:
        raise ValueError("repository materialization/commit binding must be required")
    if policy.get("required_materialization_confirmation_schema") != MATERIALIZATION_CONFIRMATION_SCHEMA:
        raise ValueError("repository materialization confirmation schema policy invalid")
    return policy


def validate_repository_metadata(repo: Path, metadata_path: Path, expected_repository: str | None = None) -> dict[str, Any]:
    policy = load_policy(repo)
    raw = load_json(metadata_path)
    repository_id = raw.get("id")
    if isinstance(repository_id, bool) or not isinstance(repository_id, int) or repository_id <= 0:
        raise ValueError("GitHub repository metadata id must be a positive integer")
    full_name = str(raw.get("full_name") or "")
    if not REPO_RE.fullmatch(full_name):
        raise ValueError("GitHub repository metadata full_name invalid")
    if expected_repository and full_name != expected_repository:
        raise ValueError("GitHub repository metadata full_name does not match expected repository")
    owner = raw.get("owner")
    if not isinstance(owner, dict) or str(owner.get("login") or "") != full_name.split("/", 1)[0]:
        raise ValueError("GitHub repository metadata owner.login mismatch")
    default_branch = str(raw.get("default_branch") or "")
    if default_branch != policy["required_default_branch"]:
        raise ValueError("GitHub repository default branch does not match target policy")
    server = policy["required_server_url"].rstrip("/")
    html_url = str(raw.get("html_url") or "").rstrip("/")
    expected_html = f"{server}/{full_name}"
    if html_url != expected_html:
        raise ValueError("GitHub repository metadata html_url/server identity mismatch")
    api_url = str(raw.get("url") or "").rstrip("/")
    if server == "https://github.com":
        expected_api = f"https://api.github.com/repos/{full_name}"
        if api_url != expected_api:
            raise ValueError("GitHub repository metadata REST API URL mismatch")
    if raw.get("archived") is not False:
        raise ValueError("GitHub repository must not be archived")
    if raw.get("disabled") is not False:
        raise ValueError("GitHub repository must not be disabled")
    visibility = raw.get("visibility")
    if visibility is not None and visibility not in {"private", "public", "internal"}:
        raise ValueError("GitHub repository visibility value invalid")
    return {
        "repository": full_name,
        "repository_id": str(repository_id),
        "server_url": policy["required_server_url"],
        "default_branch": default_branch,
        "html_url": html_url,
        "api_url": api_url,
        "owner_login": str(owner["login"]),
        "visibility": visibility,
        "metadata_sha256": sha256_file(metadata_path),
        "metadata_source": "github_rest_api_repository_object",
    }


def bind_from_metadata(repo: Path, checkpoint: Path, metadata_path: Path, materialization_confirmation_path: Path, output: Path,
                       release_version: str | None, confirm_api_metadata: bool) -> dict[str, Any]:
    if output.exists() or output.is_symlink():
        raise ValueError(f"refusing to overwrite: {output}")
    if not confirm_api_metadata:
        raise ValueError("metadata binding requires explicit --confirm-api-metadata acknowledgement")
    policy = load_policy(repo)
    cp = checkpoint_verification(repo, checkpoint)
    metadata = validate_repository_metadata(repo, metadata_path)
    confirmation = load_json(materialization_confirmation_path)
    if confirmation.get("schema") != MATERIALIZATION_CONFIRMATION_SCHEMA or confirmation.get("status") != "REMOTE_MATERIALIZATION_CONFIRMED":
        raise ValueError("repository materialization confirmation schema/status invalid")
    if confirmation.get("release_authorizing_input") is not True:
        raise ValueError("repository materialization confirmation is not release-authorizing input")
    expected_confirmation = {
        "repository": metadata["repository"],
        "repository_id": metadata["repository_id"],
        "server_url": metadata["server_url"],
        "default_branch": metadata["default_branch"],
        "required_ref": policy["required_ref"],
        "checkpoint_sha256": cp["checkpoint_sha256"],
        "source_manifest_sha256": cp["source_manifest_sha256"],
        "workflow_sha256": cp["workflow_sha256"],
    }
    for key, expected in expected_confirmation.items():
        if str(confirmation.get(key)) != str(expected):
            raise ValueError(f"repository materialization confirmation {key} mismatch")
    if str(confirmation.get("repository_metadata_sha256") or "") != sha256_file(metadata_path):
        raise ValueError("repository materialization confirmation repository metadata hash mismatch")
    commit_sha = str(confirmation.get("expected_commit_sha") or "").lower()
    if not HEX40_RE.fullmatch(commit_sha) or str(confirmation.get("remote_head_sha") or "").lower() != commit_sha:
        raise ValueError("repository materialization confirmed commit SHA invalid/mismatched")
    for key in ("materialization_json_sha256", "materialization_manifest_sha256", "git_bundle_sha256", "ref_metadata_sha256"):
        if not HEX64_RE.fullmatch(str(confirmation.get(key) or "")):
            raise ValueError(f"repository materialization confirmation {key} invalid")
    if release_version and not VERSION_RE.fullmatch(release_version):
        raise ValueError("release version must be semantic version")
    payload = {
        "schema": SCHEMA,
        "status": "TARGET_BOUND",
        "bound_at_utc": source_freeze.evidence_now().isoformat(),
        "provider": policy["provider"],
        "server_url": metadata["server_url"],
        "repository": metadata["repository"],
        "repository_id": metadata["repository_id"],
        "default_branch": metadata["default_branch"],
        "required_ref": policy["required_ref"],
        "required_event": policy["required_event"],
        "workflow_path": policy["required_workflow_path"],
        "workflow_name": policy["required_workflow_name"],
        "required_job": policy["required_job"],
        "workflow_sha256": cp["workflow_sha256"],
        "checkpoint_sha256": cp["checkpoint_sha256"],
        "source_manifest_sha256": cp["source_manifest_sha256"],
        "source_manifest_entries": cp["source_manifest_entries"],
        "release_version": release_version,
        "release_authorizing": True,
        "binding_source": policy["required_binding_source"],
        "repository_metadata_sha256": metadata["metadata_sha256"],
        "repository_metadata_api_url": metadata["api_url"],
        "repository_metadata_html_url": metadata["html_url"],
        "repository_owner_login": metadata["owner_login"],
        "repository_visibility": metadata["visibility"],
        "repository_metadata_provenance_confirmed": True,
        "repository_materialization_confirmed": True,
        "materialized_commit_sha": commit_sha,
        "materialization_confirmation_sha256": sha256_file(materialization_confirmation_path),
        "materialization_json_sha256": confirmation["materialization_json_sha256"],
        "materialization_manifest_sha256": confirmation["materialization_manifest_sha256"],
        "git_bundle_sha256": confirmation["git_bundle_sha256"],
        "remote_ref_metadata_sha256": confirmation["ref_metadata_sha256"],
        "operator_real_target_confirmation": True,
    }
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    return payload


def checkpoint_verification(repo: Path, checkpoint: Path) -> dict[str, Any]:
    if not checkpoint.is_file() or checkpoint.is_symlink() or not zipfile.is_zipfile(checkpoint):
        raise ValueError("checkpoint must be a regular ZIP")
    with tempfile.TemporaryDirectory(prefix="nadi-target-checkpoint-") as td:
        root = Path(td) / "source"
        root.mkdir()
        source_freeze.safe_extract(checkpoint, root)
        verified = source_freeze.verify_source(root)
        workflow = root / ".github/workflows/release-gates.yml"
        return {
            "checkpoint_sha256": sha256_file(checkpoint),
            "source_manifest_sha256": verified["source_manifest_sha256"],
            "source_manifest_entries": verified["source_manifest_entries"],
            "workflow_sha256": sha256_file(workflow),
        }


def make_request(repo: Path, checkpoint: Path, output: Path, release_version: str | None) -> dict[str, Any]:
    if output.exists() or output.is_symlink():
        raise ValueError(f"refusing to overwrite: {output}")
    policy = load_policy(repo)
    cp = checkpoint_verification(repo, checkpoint)
    if release_version and not VERSION_RE.fullmatch(release_version):
        raise ValueError("release version must be semantic version")
    payload = {
        "schema": REQUEST_SCHEMA,
        "status": "WAITING_EXTERNAL_TARGET_BINDING",
        "created_at_utc": source_freeze.evidence_now().isoformat(),
        "provider": policy["provider"],
        "required_server_url": policy["required_server_url"],
        "required_repository_fields": ["repository", "repository_id", "default_branch"],
        "required_default_branch": policy["required_default_branch"],
        "required_ref": policy["required_ref"],
        "required_event": policy["required_event"],
        "required_workflow_path": policy["required_workflow_path"],
        "required_workflow_name": policy["required_workflow_name"],
        "required_job": policy["required_job"],
        "requested_release_version": release_version,
        "repository_materialization_required": True,
        "remote_head_confirmation_required": True,
        **cp,
        "release_authorizing": False,
        "operator_rule": "Materialize the exact frozen Git commit, confirm GitHub main HEAD from REST metadata, then bind repository identity; do not invent repository_id, commit SHA, or workflow provenance.",
    }
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    return payload


def bind(repo: Path, checkpoint: Path, output: Path, repository: str, repository_id: str, server_url: str,
         default_branch: str, release_version: str | None, confirm_real_target: bool) -> dict[str, Any]:
    if output.exists() or output.is_symlink():
        raise ValueError(f"refusing to overwrite: {output}")
    policy = load_policy(repo)
    cp = checkpoint_verification(repo, checkpoint)
    if not confirm_real_target:
        raise ValueError("real target binding requires explicit --confirm-real-target acknowledgement")
    if not REPO_RE.fullmatch(repository):
        raise ValueError("repository must be owner/name")
    if not repository_id.isdigit() or int(repository_id) <= 0:
        raise ValueError("repository_id must be a positive GitHub numeric repository id")
    if server_url.rstrip("/") != policy["required_server_url"].rstrip("/"):
        raise ValueError("GitHub server URL does not match target policy")
    if default_branch != policy["required_default_branch"]:
        raise ValueError("default branch does not match target policy")
    if release_version and not VERSION_RE.fullmatch(release_version):
        raise ValueError("release version must be semantic version")
    payload = {
        "schema": SCHEMA,
        "status": "TARGET_BOUND_MANUAL_NON_AUTHORIZING",
        "bound_at_utc": source_freeze.evidence_now().isoformat(),
        "provider": policy["provider"],
        "server_url": policy["required_server_url"],
        "repository": repository,
        "repository_id": str(int(repository_id)),
        "default_branch": default_branch,
        "required_ref": policy["required_ref"],
        "required_event": policy["required_event"],
        "workflow_path": policy["required_workflow_path"],
        "workflow_name": policy["required_workflow_name"],
        "required_job": policy["required_job"],
        "workflow_sha256": cp["workflow_sha256"],
        "checkpoint_sha256": cp["checkpoint_sha256"],
        "source_manifest_sha256": cp["source_manifest_sha256"],
        "source_manifest_entries": cp["source_manifest_entries"],
        "release_version": release_version,
        "release_authorizing": False,
        "binding_source": "manual_operator_confirmation_non_authorizing",
        "operator_real_target_confirmation": True,
    }
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    return payload


def verify(repo: Path, binding_path: Path, checkpoint: Path | None = None) -> dict[str, Any]:
    policy = load_policy(repo)
    b = load_json(binding_path)
    if b.get("schema") != SCHEMA or b.get("status") != "TARGET_BOUND" or b.get("release_authorizing") is not True:
        raise ValueError("external runtime target binding schema/status invalid")
    if b.get("operator_real_target_confirmation") is not True:
        raise ValueError("external runtime target binding lacks explicit real-target confirmation")
    if b.get("binding_source") != policy["required_binding_source"]:
        raise ValueError("external runtime target binding is not backed by required GitHub repository metadata")
    if b.get("repository_metadata_provenance_confirmed") is not True:
        raise ValueError("external runtime target binding repository metadata provenance not confirmed")
    if b.get("repository_materialization_confirmed") is not True:
        raise ValueError("external runtime target binding repository materialization not confirmed")
    commit_sha = str(b.get("materialized_commit_sha") or "").lower()
    if not HEX40_RE.fullmatch(commit_sha):
        raise ValueError("external runtime target binding materialized commit SHA invalid")
    for key in ("materialization_confirmation_sha256", "materialization_json_sha256", "materialization_manifest_sha256", "git_bundle_sha256", "remote_ref_metadata_sha256"):
        if not HEX64_RE.fullmatch(str(b.get(key) or "")):
            raise ValueError(f"external runtime target binding {key} invalid")
    metadata_sha = str(b.get("repository_metadata_sha256") or "")
    if not re.fullmatch(r"[0-9a-f]{64}", metadata_sha):
        raise ValueError("external runtime target binding repository metadata SHA-256 invalid")
    repository = str(b.get("repository") or "")
    if not REPO_RE.fullmatch(repository):
        raise ValueError("bound repository invalid")
    repository_id = str(b.get("repository_id") or "")
    if not repository_id.isdigit() or int(repository_id) <= 0:
        raise ValueError("bound repository_id invalid")
    expected_html = f"{policy['required_server_url'].rstrip('/')}/{repository}"
    if str(b.get("repository_metadata_html_url") or "").rstrip("/") != expected_html:
        raise ValueError("bound repository metadata html_url mismatch")
    if policy["required_server_url"].rstrip("/") == "https://github.com":
        expected_api = f"https://api.github.com/repos/{repository}"
        if str(b.get("repository_metadata_api_url") or "").rstrip("/") != expected_api:
            raise ValueError("bound repository metadata API URL mismatch")
    if b.get("repository_owner_login") != repository.split("/", 1)[0]:
        raise ValueError("bound repository owner metadata mismatch")
    required = {
        "provider": policy["provider"],
        "server_url": policy["required_server_url"],
        "default_branch": policy["required_default_branch"],
        "required_ref": policy["required_ref"],
        "required_event": policy["required_event"],
        "workflow_path": policy["required_workflow_path"],
        "workflow_name": policy["required_workflow_name"],
        "required_job": policy["required_job"],
    }
    for key, expected in required.items():
        if b.get(key) != expected:
            raise ValueError(f"target binding {key} mismatch")
    current = source_freeze.verify_source(repo)
    if b.get("source_manifest_sha256") != current["source_manifest_sha256"]:
        raise ValueError("target binding source manifest mismatch")
    if b.get("source_manifest_entries") != current["source_manifest_entries"]:
        raise ValueError("target binding source entry count mismatch")
    if b.get("workflow_sha256") != sha256_file(repo / policy["required_workflow_path"]):
        raise ValueError("target binding workflow hash mismatch")
    if checkpoint is not None:
        cp = checkpoint_verification(repo, checkpoint)
        for key in ("checkpoint_sha256", "source_manifest_sha256", "source_manifest_entries", "workflow_sha256"):
            if b.get(key) != cp[key]:
                raise ValueError(f"target binding checkpoint {key} mismatch")
    version = b.get("release_version")
    if version is not None and not VERSION_RE.fullmatch(str(version)):
        raise ValueError("target binding release version invalid")
    return {
        "schema": "nadi.external-runtime-target-verification.v1",
        "status": "PASS",
        "repository": repository,
        "repository_id": repository_id,
        "server_url": b["server_url"],
        "source_manifest_sha256": b["source_manifest_sha256"],
        "checkpoint_sha256": b["checkpoint_sha256"],
        "workflow_sha256": b["workflow_sha256"],
        "materialized_commit_sha": commit_sha,
        "materialization_confirmation_sha256": b["materialization_confirmation_sha256"],
        "release_version": version,
    }


def verify_ci(binding: dict[str, Any], ci: dict[str, Any]) -> None:
    checks = {
        "repository": binding.get("repository"),
        "repository_id": str(binding.get("repository_id")),
        "server_url": binding.get("server_url"),
        "ref": binding.get("required_ref"),
        "event_name": binding.get("required_event"),
        "workflow": binding.get("workflow_name"),
        "job": binding.get("required_job"),
    }
    for key, expected in checks.items():
        actual = ci.get(key)
        if actual is not None:
            actual = str(actual)
        if actual != str(expected):
            raise ValueError(f"CI provenance {key} mismatch: expected {expected}, got {actual}")
    workflow_ref = str(ci.get("workflow_ref") or "")
    expected_fragment = f"{binding['repository']}/{binding['workflow_path']}@{binding['required_ref']}"
    if workflow_ref != expected_fragment:
        raise ValueError(f"CI workflow_ref mismatch: expected {expected_fragment}, got {workflow_ref}")
    workflow_sha = str(ci.get("workflow_sha") or "")
    if not HEX40_RE.fullmatch(workflow_sha):
        raise ValueError("CI workflow_sha missing/invalid")
    commit_sha = str(ci.get("commit_sha") or "").lower()
    expected_commit = str(binding.get("materialized_commit_sha") or "").lower()
    if not HEX40_RE.fullmatch(commit_sha) or commit_sha != expected_commit:
        raise ValueError(f"CI commit_sha mismatch: expected {expected_commit}, got {commit_sha}")



def dispatch_readiness(repo: Path, binding_path: Path, checkpoint: Path, release_version: str) -> dict[str, Any]:
    if not VERSION_RE.fullmatch(release_version):
        raise ValueError("release version must be semantic version")
    verified = verify(repo, binding_path, checkpoint)
    binding = load_json(binding_path)
    bound_version = binding.get("release_version")
    if bound_version is not None and str(bound_version) != release_version:
        raise ValueError(f"dispatch release version mismatch: binding={bound_version} requested={release_version}")
    return {
        "schema": "nadi.authoritative-workflow-dispatch-readiness.v1",
        "status": "DISPATCH_READY_NON_PASS",
        "release_authorizing_evidence": False,
        "repository": verified["repository"],
        "repository_id": verified["repository_id"],
        "server_url": verified["server_url"],
        "ref": binding["required_ref"],
        "event": binding["required_event"],
        "workflow_path": binding["workflow_path"],
        "workflow_name": binding["workflow_name"],
        "job": binding["required_job"],
        "materialized_commit_sha": verified["materialized_commit_sha"],
        "checkpoint_sha256": verified["checkpoint_sha256"],
        "source_manifest_sha256": verified["source_manifest_sha256"],
        "release_version": release_version,
        "operator_command": [
            "gh", "workflow", "run", binding["workflow_path"], "--repo", verified["repository"],
            "--ref", "main", "-f", f"release_version={release_version}"
        ],
        "rule": "This readiness result is not CI PASS. Consume only the resulting GitHub Actions evidence bundle through consume_external_ci.py.",
    }

def main() -> int:
    p = argparse.ArgumentParser(description=__doc__)
    sub = p.add_subparsers(dest="command", required=True)
    req = sub.add_parser("request")
    req.add_argument("--repo-root", default=".")
    req.add_argument("--checkpoint-zip", required=True)
    req.add_argument("--release-version")
    req.add_argument("--output", required=True)
    m = sub.add_parser("metadata-verify")
    m.add_argument("--repo-root", default=".")
    m.add_argument("--metadata", required=True)
    m.add_argument("--expected-repository")
    bm = sub.add_parser("bind-metadata")
    bm.add_argument("--repo-root", default=".")
    bm.add_argument("--checkpoint-zip", required=True)
    bm.add_argument("--metadata", required=True)
    bm.add_argument("--materialization-confirmation", required=True)
    bm.add_argument("--release-version")
    bm.add_argument("--confirm-api-metadata", action="store_true")
    bm.add_argument("--output", required=True)
    b = sub.add_parser("bind")
    b.add_argument("--repo-root", default=".")
    b.add_argument("--checkpoint-zip", required=True)
    b.add_argument("--repository", required=True)
    b.add_argument("--repository-id", required=True)
    b.add_argument("--server-url", default="https://github.com")
    b.add_argument("--default-branch", default="main")
    b.add_argument("--release-version")
    b.add_argument("--confirm-real-target", action="store_true")
    b.add_argument("--output", required=True)
    v = sub.add_parser("verify")
    v.add_argument("--repo-root", default=".")
    v.add_argument("--binding", required=True)
    v.add_argument("--checkpoint-zip")
    dr = sub.add_parser("dispatch-readiness")
    dr.add_argument("--repo-root", default=".")
    dr.add_argument("--binding", required=True)
    dr.add_argument("--checkpoint-zip", required=True)
    dr.add_argument("--release-version", required=True)
    args = p.parse_args()
    try:
        repo = Path(args.repo_root).resolve()
        if args.command == "request":
            result = make_request(repo, Path(args.checkpoint_zip).resolve(), Path(args.output).resolve(), args.release_version)
        elif args.command == "metadata-verify":
            result = validate_repository_metadata(repo, Path(args.metadata).resolve(), args.expected_repository)
        elif args.command == "bind-metadata":
            result = bind_from_metadata(
                repo, Path(args.checkpoint_zip).resolve(), Path(args.metadata).resolve(), Path(args.materialization_confirmation).resolve(), Path(args.output).resolve(),
                args.release_version, args.confirm_api_metadata
            )
        elif args.command == "bind":
            result = bind(repo, Path(args.checkpoint_zip).resolve(), Path(args.output).resolve(), args.repository, args.repository_id,
                          args.server_url, args.default_branch, args.release_version, args.confirm_real_target)
        elif args.command == "dispatch-readiness":
            result = dispatch_readiness(repo, Path(args.binding).resolve(), Path(args.checkpoint_zip).resolve(), args.release_version)
        else:
            cp = Path(args.checkpoint_zip).resolve() if args.checkpoint_zip else None
            result = verify(repo, Path(args.binding).resolve(), cp)
        print(json.dumps(result, indent=2, sort_keys=True))
        return 0
    except (ValueError, OSError, json.JSONDecodeError, zipfile.BadZipFile) as exc:
        print(f"FAIL: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
