#!/usr/bin/env python3
"""Create and verify deterministic Git materialization for the frozen NADI source.

This tool does not create a GitHub repository and does not grant release authority.
It creates one deterministic root commit on ``main`` from an exact source checkpoint,
exports that commit as a Git bundle, and can later verify GitHub ``main`` points to
that exact commit before an external runtime target binding is allowed.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import zipfile
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import source_freeze

POLICY = Path("config/repository-materialization-policy.json")
POLICY_SCHEMA = "nadi.repository-materialization-policy.v1"
MATERIALIZATION_SCHEMA = "nadi.repository-materialization.v1"
CONFIRMATION_SCHEMA = "nadi.repository-materialization-confirmation.v1"
MANIFEST_SCHEMA = "nadi.repository-materialization-manifest.v1"
MATERIALIZATION_NAME = "GITHUB_REPOSITORY_MATERIALIZATION.json"
CONFIRMATION_NAME = "GITHUB_REPOSITORY_MATERIALIZATION_CONFIRMATION.json"
MANIFEST_NAME = "GITHUB_REPOSITORY_MATERIALIZATION_MANIFEST.json"
MANIFEST_DIGEST_NAME = MANIFEST_NAME + ".sha256"
BUNDLE_NAME = "SOURCE_GIT_BUNDLE.bundle"
CHECKPOINT_NAME = "SOURCE_CHECKPOINT.zip"
RUNBOOK_NAME = "GITHUB_REPOSITORY_MATERIALIZATION.md"
POLICY_COPY = "REPOSITORY_MATERIALIZATION_POLICY.json"
HEX40 = re.compile(r"^[0-9a-f]{40}$")
REPO_RE = re.compile(r"^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$")
SENSITIVE_ASSIGNMENT_RE = re.compile(
    r"(?im)^\s*(APP_KEY|DB_PASSWORD|GITHUB_TOKEN|GH_TOKEN|GITHUB_PAT|GITHUB_ENTERPRISE_TOKEN|NADI_ACCEPTANCE_PASSWORD|NADI_BROWSER_FORCED_PASSWORD|NADI_BROWSER_VIEWER_PASSWORD)\s*=\s*(.+)$"
)
PRIVATE_KEY_MARKERS = (
    "-----BEGIN PRIVATE KEY-----",
    "-----BEGIN RSA PRIVATE KEY-----",
    "-----BEGIN OPENSSH PRIVATE KEY-----",
)


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


def run(cmd: list[str], cwd: Path | None = None, env: dict[str, str] | None = None) -> str:
    proc = subprocess.run(cmd, cwd=cwd, env=env, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    if proc.returncode != 0:
        raise ValueError(f"command failed ({' '.join(cmd)}): {proc.stdout.strip()}")
    return proc.stdout.strip()


def load_policy(repo: Path) -> tuple[dict[str, Any], str]:
    path = repo / POLICY
    policy = load_json(path)
    if policy.get("schema") != POLICY_SCHEMA or policy.get("version") != 1:
        raise ValueError("repository materialization policy schema/version invalid")
    if policy.get("materialization_iteration") != "15.18":
        raise ValueError("repository materialization iteration mismatch")
    if policy.get("provider") != "github" or policy.get("git_object_format") != "sha1":
        raise ValueError("repository materialization provider/object format invalid")
    if policy.get("required_branch") != "main" or policy.get("required_ref") != "refs/heads/main":
        raise ValueError("repository materialization branch/ref invalid")
    if policy.get("require_single_root_commit") is not True:
        raise ValueError("repository materialization must require a single root commit")
    if policy.get("remote_head_confirmation_required") is not True:
        raise ValueError("remote HEAD confirmation must be required")
    if policy.get("git_bundle_required") is not True or policy.get("source_checkpoint_required") is not True:
        raise ValueError("checkpoint and git bundle must be required")
    if policy.get("submodules_forbidden") is not True or policy.get("symlinks_forbidden") is not True:
        raise ValueError("submodules/symlinks must be forbidden")
    return policy, sha256_file(path)


def source_epoch() -> int:
    raw = os.environ.get("SOURCE_DATE_EPOCH", "").strip()
    if not raw:
        raise ValueError("SOURCE_DATE_EPOCH is required for deterministic repository materialization")
    try:
        value = int(raw)
    except ValueError as exc:
        raise ValueError("SOURCE_DATE_EPOCH must be an integer") from exc
    if value <= 0:
        raise ValueError("SOURCE_DATE_EPOCH must be positive")
    return value


def git_date(epoch: int) -> str:
    return datetime.fromtimestamp(epoch, tz=timezone.utc).strftime("%Y-%m-%dT%H:%M:%S+00:00")


def reject_source_git_artifacts(source: Path) -> None:
    forbidden = [source / ".git", source / ".gitmodules"]
    for path in forbidden:
        if path.exists() or path.is_symlink():
            raise ValueError(f"source checkpoint contains forbidden Git metadata: {path.name}")
    for path in source.rglob("*"):
        if path.is_symlink():
            raise ValueError(f"source symlink refused: {path.relative_to(source)}")


def build_git_repo(source: Path, policy: dict[str, Any], epoch: int, destination: Path) -> dict[str, str]:
    destination.mkdir(parents=True, exist_ok=False)
    run(["git", "init", "--initial-branch", policy["required_branch"]], cwd=destination)
    # Copy exact source after .git exists in destination.
    for item in source.iterdir():
        target = destination / item.name
        if item.is_dir():
            shutil.copytree(item, target)
        else:
            shutil.copy2(item, target)
    run(["git", "config", "user.name", policy["commit_author_name"]], cwd=destination)
    run(["git", "config", "user.email", policy["commit_author_email"]], cwd=destination)
    run(["git", "config", "commit.gpgsign", "false"], cwd=destination)
    run(["git", "add", "-A"], cwd=destination)
    env = os.environ.copy()
    stamp = git_date(epoch)
    env.update({
        "GIT_AUTHOR_NAME": policy["commit_author_name"],
        "GIT_AUTHOR_EMAIL": policy["commit_author_email"],
        "GIT_COMMITTER_NAME": policy["commit_author_name"],
        "GIT_COMMITTER_EMAIL": policy["commit_author_email"],
        "GIT_AUTHOR_DATE": stamp,
        "GIT_COMMITTER_DATE": stamp,
        "TZ": "UTC",
    })
    run(["git", "commit", "--no-gpg-sign", "-m", policy["commit_message"]], cwd=destination, env=env)
    if run(["git", "status", "--porcelain"], cwd=destination):
        raise ValueError("materialized Git worktree is not clean")
    count = run(["git", "rev-list", "--count", "HEAD"], cwd=destination)
    if count != "1":
        raise ValueError("materialized repository must contain exactly one commit")
    parents = run(["git", "rev-list", "--parents", "-n", "1", "HEAD"], cwd=destination).split()
    if len(parents) != 1:
        raise ValueError("materialized commit must be a root commit")
    commit_sha = run(["git", "rev-parse", "HEAD"], cwd=destination).lower()
    tree_sha = run(["git", "rev-parse", "HEAD^{tree}"], cwd=destination).lower()
    if not HEX40.fullmatch(commit_sha) or not HEX40.fullmatch(tree_sha):
        raise ValueError("materialized Git object SHA invalid")
    return {"commit_sha": commit_sha, "tree_sha": tree_sha}


def checkout_bundle(bundle: Path, branch: str, destination: Path) -> str:
    run(["git", "clone", "--branch", branch, str(bundle), str(destination)])
    # git bundle verify requires repository context; the fresh clone provides it.
    run(["git", "bundle", "verify", str(bundle)], cwd=destination)
    return run(["git", "rev-parse", "HEAD"], cwd=destination).lower()


def verify_git_worktree_source(worktree: Path) -> dict[str, Any]:
    with tempfile.TemporaryDirectory(prefix="nadi-materialization-source-") as td:
        clean = Path(td) / "source"
        shutil.copytree(worktree, clean, ignore=shutil.ignore_patterns(".git"))
        return source_freeze.verify_source(clean)


def verify_bundle_heads(bundle: Path, branch: str, expected_commit: str) -> None:
    output = run(["git", "bundle", "list-heads", str(bundle)])
    lines = [line.strip() for line in output.splitlines() if line.strip()]
    expected = f"{expected_commit} refs/heads/{branch}"
    if lines != [expected]:
        raise ValueError(f"Git bundle must advertise exactly {expected}; got {lines}")


def scan_secrets(root: Path) -> None:
    for path in root.rglob("*"):
        if path.is_symlink():
            raise ValueError(f"materialization symlink refused: {path.relative_to(root)}")
        if not path.is_file():
            continue
        if path.name in {CHECKPOINT_NAME, BUNDLE_NAME}:
            continue
        if path.name.startswith(".env") or path.suffix in {".pem", ".key", ".p12", ".pfx"}:
            raise ValueError(f"sensitive material refused: {path.relative_to(root)}")
        try:
            text = path.read_text(encoding="utf-8")
        except UnicodeDecodeError:
            continue
        if any(marker in text for marker in PRIVATE_KEY_MARKERS):
            raise ValueError(f"private key material refused: {path.relative_to(root)}")
        if SENSITIVE_ASSIGNMENT_RE.search(text):
            raise ValueError(f"credential assignment refused: {path.relative_to(root)}")


def manifest_entries(root: Path) -> list[dict[str, Any]]:
    skip = {MANIFEST_NAME, MANIFEST_DIGEST_NAME}
    result: list[dict[str, Any]] = []
    for path in sorted(root.rglob("*")):
        if path.is_symlink():
            raise ValueError(f"materialization symlink refused: {path.relative_to(root)}")
        if not path.is_file():
            continue
        rel = path.relative_to(root).as_posix()
        if rel in skip:
            continue
        result.append({"path": rel, "sha256": sha256_file(path), "bytes": path.stat().st_size})
    return result


def write_manifest(root: Path, materialization: dict[str, Any]) -> None:
    entries = manifest_entries(root)
    data = {
        "schema": MANIFEST_SCHEMA,
        "version": 1,
        "status": "MATERIALIZATION_PACKAGE_READY_NON_AUTHORIZING",
        "checkpoint_sha256": materialization["checkpoint_sha256"],
        "source_manifest_sha256": materialization["source_manifest_sha256"],
        "expected_commit_sha": materialization["expected_commit_sha"],
        "files": entries,
    }
    path = root / MANIFEST_NAME
    path.write_text(json.dumps(data, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    (root / MANIFEST_DIGEST_NAME).write_text(f"{sha256_file(path)}  {MANIFEST_NAME}\n", encoding="utf-8")


def verify_manifest(root: Path) -> dict[str, Any]:
    manifest = load_json(root / MANIFEST_NAME)
    if manifest.get("schema") != MANIFEST_SCHEMA or manifest.get("version") != 1:
        raise ValueError("materialization manifest schema/version invalid")
    digest_line = (root / MANIFEST_DIGEST_NAME).read_text(encoding="utf-8").strip().split()
    if len(digest_line) != 2 or digest_line[1] != MANIFEST_NAME or digest_line[0] != sha256_file(root / MANIFEST_NAME):
        raise ValueError("materialization manifest digest mismatch")
    files = manifest.get("files")
    if not isinstance(files, list):
        raise ValueError("materialization manifest files invalid")
    expected: dict[str, dict[str, Any]] = {}
    for item in files:
        if not isinstance(item, dict):
            raise ValueError("materialization manifest file entry invalid")
        rel = str(item.get("path") or "")
        if not rel or rel.startswith("/") or ".." in Path(rel).parts or rel in expected:
            raise ValueError("materialization manifest path invalid")
        expected[rel] = item
    actual = {
        p.relative_to(root).as_posix()
        for p in root.rglob("*")
        if p.is_file() and not p.is_symlink() and p.relative_to(root).as_posix() not in {MANIFEST_NAME, MANIFEST_DIGEST_NAME}
    }
    if actual != set(expected):
        raise ValueError(f"materialization closed-world mismatch: missing={sorted(set(expected)-actual)} extra={sorted(actual-set(expected))}")
    for rel, item in expected.items():
        path = root / rel
        if path.is_symlink() or not path.is_file():
            raise ValueError(f"materialization file missing/unsafe: {rel}")
        if path.stat().st_size != item.get("bytes") or sha256_file(path) != item.get("sha256"):
            raise ValueError(f"materialization file mismatch: {rel}")
    return manifest


def deterministic_zip(root: Path, output: Path) -> None:
    if output.exists() or output.is_symlink():
        raise ValueError(f"refusing to overwrite: {output}")
    epoch = source_epoch()
    dt = datetime.fromtimestamp(max(epoch, 315532800), tz=timezone.utc)
    zdt = (dt.year, dt.month, dt.day, dt.hour, dt.minute, dt.second - (dt.second % 2))
    output.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
        for path in sorted(p for p in root.rglob("*") if p.is_file()):
            rel = path.relative_to(root).as_posix()
            info = zipfile.ZipInfo(rel, zdt)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = (0o100644 & 0xFFFF) << 16
            zf.writestr(info, path.read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)


def create(args: argparse.Namespace) -> dict[str, Any]:
    checkpoint = Path(args.checkpoint_zip).resolve()
    output = Path(args.output_dir).resolve()
    if output.exists() or output.is_symlink():
        raise ValueError(f"refusing to overwrite output directory: {output}")
    repo = Path(args.repo_root).resolve()
    policy, policy_sha = load_policy(repo)
    epoch = source_epoch()
    with tempfile.TemporaryDirectory(prefix="nadi-materialize-") as td:
        tdroot = Path(td)
        source = tdroot / "source"
        source.mkdir()
        source_freeze.safe_extract(checkpoint, source)
        verification = source_freeze.verify_source(source)
        reject_source_git_artifacts(source)
        gitrepo = tdroot / "gitrepo"
        ids = build_git_repo(source, policy, epoch, gitrepo)
        bundle = tdroot / BUNDLE_NAME
        run(["git", "bundle", "create", str(bundle), policy["required_branch"]], cwd=gitrepo)
        verify_bundle_heads(bundle, policy["required_branch"], ids["commit_sha"])
        clone = tdroot / "clone"
        cloned_sha = checkout_bundle(bundle, policy["required_branch"], clone)
        if cloned_sha != ids["commit_sha"]:
            raise ValueError("Git bundle checkout commit mismatch")
        verify_git_worktree_source(clone)
        if (clone / ".gitmodules").exists():
            raise ValueError("submodules are forbidden")

        output.mkdir(parents=True)
        shutil.copy2(checkpoint, output / CHECKPOINT_NAME)
        shutil.copy2(bundle, output / BUNDLE_NAME)
        shutil.copy2(repo / POLICY, output / POLICY_COPY)
        runbook = repo / "docs/GITHUB_REPOSITORY_MATERIALIZATION.md"
        shutil.copy2(runbook, output / RUNBOOK_NAME)
        payload = {
            "schema": MATERIALIZATION_SCHEMA,
            "version": 1,
            "status": "MATERIALIZATION_READY_NON_AUTHORIZING",
            "materialization_iteration": policy["materialization_iteration"],
            "branch": policy["required_branch"],
            "ref": policy["required_ref"],
            "checkpoint_sha256": sha256_file(checkpoint),
            "source_manifest_sha256": verification["source_manifest_sha256"],
            "source_manifest_entries": verification["source_manifest_entries"],
            "source_freeze_policy_sha256": verification["source_freeze_policy_sha256"],
            "workflow_sha256": verification["authoritative_workflow_sha256"],
            "repository_materialization_policy_sha256": policy_sha,
            "source_date_epoch": epoch,
            "expected_commit_sha": ids["commit_sha"],
            "expected_tree_sha": ids["tree_sha"],
            "git_bundle_sha256": sha256_file(output / BUNDLE_NAME),
            "git_version": run(["git", "--version"]),
            "remote_head_confirmation_required": True,
            "release_authorizing": False,
        }
        (output / MATERIALIZATION_NAME).write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
        scan_secrets(output)
        write_manifest(output, payload)
        verify_root(output)
    if args.output_zip:
        deterministic_zip(output, Path(args.output_zip).resolve())
    return payload


def verify_root(root: Path) -> dict[str, Any]:
    scan_secrets(root)
    manifest = verify_manifest(root)
    materialization = load_json(root / MATERIALIZATION_NAME)
    if materialization.get("schema") != MATERIALIZATION_SCHEMA or materialization.get("status") != "MATERIALIZATION_READY_NON_AUTHORIZING":
        raise ValueError("repository materialization schema/status invalid")
    if materialization.get("release_authorizing") is not False:
        raise ValueError("repository materialization must be non-authorizing")
    checkpoint = root / CHECKPOINT_NAME
    bundle = root / BUNDLE_NAME
    if sha256_file(checkpoint) != materialization.get("checkpoint_sha256") or sha256_file(checkpoint) != manifest.get("checkpoint_sha256"):
        raise ValueError("materialization checkpoint mismatch")
    if sha256_file(bundle) != materialization.get("git_bundle_sha256"):
        raise ValueError("materialization Git bundle mismatch")
    with tempfile.TemporaryDirectory(prefix="nadi-materialization-verify-") as td:
        tdroot = Path(td)
        source = tdroot / "source"
        source.mkdir()
        source_freeze.safe_extract(checkpoint, source)
        v = source_freeze.verify_source(source)
        if v["source_manifest_sha256"] != materialization.get("source_manifest_sha256"):
            raise ValueError("materialization source fingerprint mismatch")
        if v["source_manifest_entries"] != materialization.get("source_manifest_entries"):
            raise ValueError("materialization source entry count mismatch")
        branch = str(materialization.get("branch"))
        expected_commit = str(materialization.get("expected_commit_sha"))
        verify_bundle_heads(bundle, branch, expected_commit)
        clone = tdroot / "clone"
        sha = checkout_bundle(bundle, branch, clone)
        if sha != expected_commit:
            raise ValueError("materialization bundle commit mismatch")
        if run(["git", "rev-list", "--count", "HEAD"], cwd=clone) != "1":
            raise ValueError("materialization bundle must contain one-commit history on main")
        if len(run(["git", "rev-list", "--parents", "-n", "1", "HEAD"], cwd=clone).split()) != 1:
            raise ValueError("materialization bundle main commit must be a root commit")
        verify_git_worktree_source(clone)
        tree = run(["git", "rev-parse", "HEAD^{tree}"], cwd=clone).lower()
        if tree != materialization.get("expected_tree_sha"):
            raise ValueError("materialization bundle tree mismatch")
    return {
        "schema": "nadi.repository-materialization-verification.v1",
        "status": "PASS",
        "checkpoint_sha256": materialization["checkpoint_sha256"],
        "source_manifest_sha256": materialization["source_manifest_sha256"],
        "expected_commit_sha": materialization["expected_commit_sha"],
        "expected_tree_sha": materialization["expected_tree_sha"],
        "git_bundle_sha256": materialization["git_bundle_sha256"],
    }


def verify_artifact(args: argparse.Namespace) -> dict[str, Any]:
    artifact = Path(args.artifact).resolve()
    if artifact.is_dir():
        return verify_root(artifact)
    if not artifact.is_file() or artifact.is_symlink():
        raise ValueError("materialization artifact missing/unsafe")
    with tempfile.TemporaryDirectory(prefix="nadi-materialization-artifact-") as td:
        root = Path(td) / "artifact"
        root.mkdir()
        source_freeze.safe_extract(artifact, root)
        return verify_root(root)


def validate_remote_ref(metadata: dict[str, Any], ref_data: dict[str, Any], materialization: dict[str, Any]) -> dict[str, str]:
    repository = str(metadata.get("full_name") or "")
    if not REPO_RE.fullmatch(repository):
        raise ValueError("repository metadata full_name invalid")
    if str(ref_data.get("ref") or "") != str(materialization.get("ref")):
        raise ValueError("GitHub remote ref mismatch")
    obj = ref_data.get("object")
    if not isinstance(obj, dict) or obj.get("type") != "commit":
        raise ValueError("GitHub remote ref object must be a commit")
    sha = str(obj.get("sha") or "").lower()
    if not HEX40.fullmatch(sha):
        raise ValueError("GitHub remote ref commit SHA invalid")
    if sha != materialization.get("expected_commit_sha"):
        raise ValueError("GitHub remote main does not match frozen materialized commit")
    return {"repository": repository, "remote_head_sha": sha}


def confirm_from_root(repo: Path, root: Path, metadata_path: Path, ref_path: Path, expected_repository: str | None, artifact_sha256: str | None) -> dict[str, Any]:
    verification = verify_root(root)
    materialization_path = root / MATERIALIZATION_NAME
    materialization = load_json(materialization_path)
    # Reuse authoritative repository metadata validation without granting authority here.
    import external_runtime_target
    normalized = external_runtime_target.validate_repository_metadata(repo, metadata_path, expected_repository)
    ref_data = load_json(ref_path)
    remote = validate_remote_ref(json.loads(metadata_path.read_text(encoding="utf-8")), ref_data, materialization)
    policy, policy_sha = load_policy(repo)
    confirmation = {
        "schema": CONFIRMATION_SCHEMA,
        "version": 1,
        "status": "REMOTE_MATERIALIZATION_CONFIRMED",
        "repository": normalized["repository"],
        "repository_id": normalized["repository_id"],
        "server_url": normalized["server_url"],
        "default_branch": normalized["default_branch"],
        "required_ref": policy["required_ref"],
        "expected_commit_sha": verification["expected_commit_sha"],
        "remote_head_sha": remote["remote_head_sha"],
        "checkpoint_sha256": verification["checkpoint_sha256"],
        "source_manifest_sha256": verification["source_manifest_sha256"],
        "workflow_sha256": materialization["workflow_sha256"],
        "git_bundle_sha256": verification["git_bundle_sha256"],
        "materialization_json_sha256": sha256_file(materialization_path),
        "materialization_manifest_sha256": sha256_file(root / MANIFEST_NAME),
        "materialization_artifact_sha256": artifact_sha256,
        "repository_metadata_sha256": sha256_file(metadata_path),
        "ref_metadata_sha256": sha256_file(ref_path),
        "repository_materialization_policy_sha256": policy_sha,
        "metadata_source": "github_rest_api_repository_object_and_git_ref",
        "release_authorizing_input": True,
    }
    return confirmation


def confirm(args: argparse.Namespace) -> dict[str, Any]:
    repo = Path(args.repo_root).resolve()
    artifact = Path(args.materialization_artifact).resolve()
    metadata_path = Path(args.repository_metadata).resolve()
    ref_path = Path(args.ref_metadata).resolve()
    output = Path(args.output).resolve()
    if output.exists() or output.is_symlink():
        raise ValueError(f"refusing to overwrite: {output}")
    if artifact.is_dir():
        confirmation = confirm_from_root(repo, artifact, metadata_path, ref_path, args.expected_repository, None)
    else:
        if not artifact.is_file() or artifact.is_symlink():
            raise ValueError("materialization artifact missing/unsafe")
        artifact_sha = sha256_file(artifact)
        with tempfile.TemporaryDirectory(prefix="nadi-materialization-confirm-") as td:
            root = Path(td) / "artifact"
            root.mkdir()
            source_freeze.safe_extract(artifact, root)
            confirmation = confirm_from_root(repo, root, metadata_path, ref_path, args.expected_repository, artifact_sha)
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(confirmation, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    return confirmation

def main() -> int:
    p = argparse.ArgumentParser(description=__doc__)
    sub = p.add_subparsers(dest="command", required=True)
    c = sub.add_parser("create")
    c.add_argument("--repo-root", default=".")
    c.add_argument("--checkpoint-zip", required=True)
    c.add_argument("--output-dir", required=True)
    c.add_argument("--output-zip")
    v = sub.add_parser("verify")
    v.add_argument("--artifact", required=True)
    cf = sub.add_parser("confirm-remote")
    cf.add_argument("--repo-root", default=".")
    cf.add_argument("--materialization-artifact", required=True)
    cf.add_argument("--repository-metadata", required=True)
    cf.add_argument("--ref-metadata", required=True)
    cf.add_argument("--expected-repository")
    cf.add_argument("--output", required=True)
    args = p.parse_args()
    try:
        if args.command == "create":
            result = create(args)
        elif args.command == "verify":
            result = verify_artifact(args)
        else:
            result = confirm(args)
        print(json.dumps(result, indent=2, sort_keys=True))
        return 0
    except Exception as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
