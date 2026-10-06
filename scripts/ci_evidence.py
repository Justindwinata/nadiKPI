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
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

SCHEMA = "nadi.ci-evidence-bundle.v1"
INGEST_SCHEMA = "nadi.ci-evidence-ingestion.v1"
MANIFEST = "CI_EVIDENCE_BUNDLE.json"
INGEST_RESULT = "CI_EVIDENCE_INGESTION.json"
SOURCE_BINDINGS = (
    "AUDITED_SOURCE_RC_MANIFEST.sha256",
    "composer.lock",
    "package-lock.json",
    "config/release-environment.json",
    "scripts/final-gate.sh",
    "scripts/acceptance_evidence.py",
    "scripts/ci_evidence.py",
    "scripts/release_decision.py",
    "scripts/consume_external_ci.py",
    "scripts/external_runtime_target.py",
    "config/external-runtime-target-policy.json",
    ".github/workflows/release-gates.yml",
)


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def load_json(path: Path) -> dict[str, Any]:
    try:
        raw = json.loads(path.read_text(encoding="utf-8"))
    except Exception as exc:
        raise ValueError(f"invalid JSON {path}: {exc}") from exc
    if not isinstance(raw, dict):
        raise ValueError(f"expected JSON object: {path}")
    return raw


def require_regular(path: Path, label: str) -> None:
    if not path.is_file() or path.is_symlink():
        raise ValueError(f"{label} missing/not a regular file: {path}")


def rel_to(path: Path, root: Path) -> str:
    return path.resolve().relative_to(root.resolve()).as_posix()


def file_entry(path: Path, root: Path) -> dict[str, Any]:
    require_regular(path, "evidence file")
    return {
        "path": rel_to(path, root),
        "sha256": sha256_file(path),
        "bytes": path.stat().st_size,
    }


def copy_tree_files(src: Path, dst: Path) -> None:
    if not src.exists():
        return
    if src.is_symlink():
        raise ValueError(f"symlink evidence root refused: {src}")
    for path in sorted(src.rglob("*")):
        if path.is_symlink():
            raise ValueError(f"symlink evidence refused: {path}")
        if path.is_dir():
            continue
        target = dst / path.relative_to(src)
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(path, target)


def source_fingerprints(repo: Path) -> list[dict[str, str]]:
    out: list[dict[str, str]] = []
    for raw in SOURCE_BINDINGS:
        path = repo / raw
        require_regular(path, "source binding file")
        out.append({"path": raw, "sha256": sha256_file(path)})
    return out


def ci_metadata() -> dict[str, str | None]:
    env = os.environ
    in_github = env.get("GITHUB_ACTIONS", "").lower() == "true"
    data = {
        "context": "github-actions" if in_github else "local-or-external",
        "repository": env.get("GITHUB_REPOSITORY"),
        "repository_id": env.get("GITHUB_REPOSITORY_ID"),
        "server_url": env.get("GITHUB_SERVER_URL"),
        "commit_sha": env.get("GITHUB_SHA"),
        "ref": env.get("GITHUB_REF"),
        "event_name": env.get("GITHUB_EVENT_NAME"),
        "actor": env.get("GITHUB_ACTOR"),
        "run_number": env.get("GITHUB_RUN_NUMBER"),
        "run_id": env.get("GITHUB_RUN_ID"),
        "run_attempt": env.get("GITHUB_RUN_ATTEMPT"),
        "workflow": env.get("GITHUB_WORKFLOW"),
        "workflow_ref": env.get("GITHUB_WORKFLOW_REF"),
        "workflow_sha": env.get("GITHUB_WORKFLOW_SHA"),
        "job": env.get("GITHUB_JOB"),
        "runner_os": env.get("RUNNER_OS"),
        "runner_arch": env.get("RUNNER_ARCH"),
    }
    if in_github:
        required = ("repository", "repository_id", "server_url", "commit_sha", "ref", "event_name", "run_id", "run_attempt", "workflow", "workflow_ref", "workflow_sha", "job", "runner_os", "runner_arch")
        missing = [key for key in required if not data.get(key)]
        if missing:
            raise ValueError(f"GitHub Actions provenance incomplete: {missing}")
    return data


EXPECTED_PASS_GATES = {
    "clean_composer_install",
    "clean_npm_ci",
    "real_vite_build",
    "mysql8_migrate_fresh",
    "phpunit_mysql",
    "pint",
    "production_release_check",
    "http_acceptance",
    "browser_acceptance",
    "acceptance_isolation_cleanup",
    "deterministic_packaging",
    "extracted_closed_world_verification",
}


def validate_runtime_gate_matrix(gate: dict[str, Any]) -> None:
    matrix = gate.get("runtime_gates")
    if not isinstance(matrix, dict):
        raise ValueError("PASS final gate missing runtime_gates matrix")
    if set(matrix) != EXPECTED_PASS_GATES:
        missing = sorted(EXPECTED_PASS_GATES - set(matrix))
        extra = sorted(set(matrix) - EXPECTED_PASS_GATES)
        raise ValueError(f"PASS final gate runtime_gates mismatch missing={missing} extra={extra}")
    bad = sorted(key for key, value in matrix.items() if value != "pass")
    if bad:
        raise ValueError(f"PASS final gate contains non-pass runtime gates: {bad}")


def verify_acceptance_semantics(repo: Path, browser_dir: Path) -> None:
    cmd = [
        sys.executable,
        str(repo / "scripts/acceptance_evidence.py"),
        "verify",
        "--repo",
        str(repo),
        "--artifact-dir",
        str(browser_dir),
    ]
    proc = subprocess.run(cmd, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    if proc.returncode != 0:
        raise ValueError(f"acceptance evidence semantic verification failed: {proc.stdout.strip()}")


def _gate_required_binding(gate: dict[str, Any], key: str, expected: str) -> None:
    actual = gate.get(key)
    if actual != expected:
        raise ValueError(f"final gate {key} mismatch: expected {expected}, got {actual}")


def validate_gate_links(repo: Path, gate_dir: Path, browser_dir: Path, package_path: Path | None) -> dict[str, Any]:
    final_gate_path = gate_dir / "final_gate.json"
    require_regular(final_gate_path, "final gate evidence")
    gate = load_json(final_gate_path)
    if gate.get("schema") != "nadi.final-gate.v1":
        raise ValueError("unsupported final gate schema")
    status = gate.get("status")
    if status not in {"pass", "fail"}:
        raise ValueError("final gate status must be pass/fail")

    source_sha = sha256_file(repo / "AUDITED_SOURCE_RC_MANIFEST.sha256")
    details: dict[str, Any] = {"status": status, "source_manifest_sha256": source_sha}

    if status == "pass":
        validate_runtime_gate_matrix(gate)
        _gate_required_binding(gate, "source_manifest_sha256", source_sha)

        runtime_contract = gate_dir / "runtime_contract.json"
        runtime_environment = gate_dir / "runtime_environment.json"
        require_regular(runtime_contract, "runtime contract evidence")
        require_regular(runtime_environment, "runtime environment evidence")
        _gate_required_binding(gate, "runtime_environment_contract_sha256", sha256_file(runtime_contract))
        _gate_required_binding(gate, "runtime_environment_evidence_sha256", sha256_file(runtime_environment))

        acceptance_manifest = browser_dir / "ACCEPTANCE_EVIDENCE_MANIFEST.json"
        require_regular(acceptance_manifest, "acceptance evidence manifest")
        _gate_required_binding(gate, "acceptance_evidence_manifest_sha256", sha256_file(acceptance_manifest))
        verify_acceptance_semantics(repo, browser_dir)

        gate_package = gate.get("gate_package")
        if not isinstance(gate_package, dict):
            raise ValueError("PASS final gate missing gate_package")
        if package_path is None:
            raw = gate_package.get("path")
            if not isinstance(raw, str) or not raw:
                raise ValueError("PASS final gate package path missing")
            package_path = repo / raw
        require_regular(package_path, "final gate package")
        expected_pkg_sha = gate_package.get("sha256")
        if sha256_file(package_path) != expected_pkg_sha:
            raise ValueError("final gate package SHA-256 mismatch")
        details["package"] = {"name": package_path.name, "sha256": expected_pkg_sha}
    else:
        related = gate.get("related_evidence", {})
        if related is not None and not isinstance(related, dict):
            raise ValueError("final gate related_evidence must be an object")
        for label, item in (related or {}).items():
            if not isinstance(item, dict):
                raise ValueError(f"invalid related_evidence entry: {label}")
            raw = item.get("path")
            expected = item.get("sha256")
            if not isinstance(raw, str) or not isinstance(expected, str):
                raise ValueError(f"invalid related evidence binding: {label}")
            candidate = repo / raw
            if not candidate.is_file():
                candidate = gate_dir / Path(raw).name
            require_regular(candidate, f"related evidence {label}")
            if sha256_file(candidate) != expected:
                raise ValueError(f"related evidence SHA mismatch: {label}")
    return details


def bundle(repo: Path, gate_dir: Path, browser_dir: Path, package_path: Path | None, out: Path) -> None:
    details = validate_gate_links(repo, gate_dir, browser_dir, package_path)
    if out.exists():
        shutil.rmtree(out)
    payload = out / "payload"
    (payload / "release-gate").mkdir(parents=True, exist_ok=True)
    copy_tree_files(gate_dir, payload / "release-gate")
    if browser_dir.exists():
        (payload / "browser-acceptance").mkdir(parents=True, exist_ok=True)
        copy_tree_files(browser_dir, payload / "browser-acceptance")

    gate = load_json(gate_dir / "final_gate.json")
    if gate.get("status") == "pass":
        gate_pkg = gate.get("gate_package", {})
        if package_path is None:
            package_path = repo / str(gate_pkg["path"])
        (payload / "release").mkdir(parents=True, exist_ok=True)
        shutil.copy2(package_path, payload / "release" / package_path.name)

    files = [file_entry(p, out) for p in sorted(payload.rglob("*")) if p.is_file()]
    manifest = {
        "schema": SCHEMA,
        "created_at_utc": datetime.now(timezone.utc).isoformat(),
        "closed_world": True,
        "gate_status": gate["status"],
        "gate_details": details,
        "ci": ci_metadata(),
        "source_fingerprints": source_fingerprints(repo),
        "files": files,
    }
    out.mkdir(parents=True, exist_ok=True)
    manifest_path = out / MANIFEST
    manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    (out / f"{MANIFEST}.sha256").write_text(f"{sha256_file(manifest_path)}  {MANIFEST}\n", encoding="utf-8")
    print(f"PASS CI evidence bundle: status={gate['status']} files={len(files)} path={out}")


def verify(repo: Path, bundle_dir: Path, require_pass: bool = False) -> dict[str, Any]:
    manifest_path = bundle_dir / MANIFEST
    digest_path = bundle_dir / f"{MANIFEST}.sha256"
    require_regular(manifest_path, "CI evidence bundle manifest")
    require_regular(digest_path, "CI evidence bundle digest")
    digest_line = digest_path.read_text(encoding="utf-8").strip().split()
    if len(digest_line) < 1 or digest_line[0] != sha256_file(manifest_path):
        raise ValueError("CI evidence bundle manifest SHA-256 mismatch")
    manifest = load_json(manifest_path)
    if manifest.get("schema") != SCHEMA or manifest.get("closed_world") is not True:
        raise ValueError("CI evidence bundle schema/closed_world assertion invalid")
    status = manifest.get("gate_status")
    if status not in {"pass", "fail"}:
        raise ValueError("CI evidence gate status invalid")
    if require_pass and status != "pass":
        raise ValueError("CI evidence is not a PASS gate run")

    expected_sources = source_fingerprints(repo)
    if manifest.get("source_fingerprints") != expected_sources:
        raise ValueError("CI evidence source fingerprints do not match current repository")

    payload = bundle_dir / "payload"
    ignored_root = {MANIFEST, f"{MANIFEST}.sha256", INGEST_RESULT}
    actual_paths = sorted(
        p.relative_to(bundle_dir).as_posix()
        for p in bundle_dir.rglob("*")
        if p.is_file() and p.relative_to(bundle_dir).as_posix() not in ignored_root
    )
    entries = manifest.get("files")
    if not isinstance(entries, list):
        raise ValueError("CI evidence files list invalid")
    expected_paths = sorted(str(item.get("path")) for item in entries if isinstance(item, dict))
    if actual_paths != expected_paths:
        missing = sorted(set(expected_paths) - set(actual_paths))
        extra = sorted(set(actual_paths) - set(expected_paths))
        raise ValueError(f"CI evidence closed-world mismatch missing={missing[:5]} extra={extra[:5]}")
    receipt = bundle_dir / INGEST_RESULT
    if receipt.exists():
        require_regular(receipt, "CI evidence ingestion receipt")
        receipt_payload = load_json(receipt)
        if receipt_payload.get("schema") != INGEST_SCHEMA:
            raise ValueError("CI evidence ingestion receipt schema invalid")
        if receipt_payload.get("bundle_manifest_sha256") != sha256_file(manifest_path):
            raise ValueError("CI evidence ingestion receipt bundle hash mismatch")
    for item in entries:
        if not isinstance(item, dict):
            raise ValueError("CI evidence file entry invalid")
        path = bundle_dir / str(item.get("path"))
        require_regular(path, "CI evidence payload")
        if path.stat().st_size != item.get("bytes") or sha256_file(path) != item.get("sha256"):
            raise ValueError(f"CI evidence payload mismatch: {item.get('path')}")

    gate_dir = payload / "release-gate"
    browser_dir = payload / "browser-acceptance"
    final_gate_path = gate_dir / "final_gate.json"
    require_regular(final_gate_path, "bundled final gate")
    final_gate = load_json(final_gate_path)
    if final_gate.get("status") != status:
        raise ValueError("bundle gate_status disagrees with final_gate.json")

    if status == "pass":
        validate_runtime_gate_matrix(final_gate)
        source_sha = sha256_file(repo / "AUDITED_SOURCE_RC_MANIFEST.sha256")
        _gate_required_binding(final_gate, "source_manifest_sha256", source_sha)
        rc = gate_dir / "runtime_contract.json"
        re = gate_dir / "runtime_environment.json"
        ae = browser_dir / "ACCEPTANCE_EVIDENCE_MANIFEST.json"
        for p, label in ((rc, "runtime contract"), (re, "runtime environment"), (ae, "acceptance manifest")):
            require_regular(p, label)
        _gate_required_binding(final_gate, "runtime_environment_contract_sha256", sha256_file(rc))
        _gate_required_binding(final_gate, "runtime_environment_evidence_sha256", sha256_file(re))
        _gate_required_binding(final_gate, "acceptance_evidence_manifest_sha256", sha256_file(ae))
        verify_acceptance_semantics(repo, browser_dir)
        release_dir = payload / "release"
        packages = [p for p in release_dir.glob("*.zip") if p.is_file()] if release_dir.exists() else []
        if len(packages) != 1:
            raise ValueError("PASS CI evidence must contain exactly one release ZIP")
        gate_package = final_gate.get("gate_package", {})
        if sha256_file(packages[0]) != gate_package.get("sha256"):
            raise ValueError("bundled release ZIP does not match final gate SHA-256")
    return manifest




def production_release_authorizable(manifest: dict[str, Any]) -> tuple[bool, list[str]]:
    reasons: list[str] = []
    if manifest.get("gate_status") != "pass":
        reasons.append("gate_status_not_pass")

    ci = manifest.get("ci")
    if not isinstance(ci, dict):
        reasons.append("ci_provenance_missing")
        return False, reasons
    if ci.get("context") != "github-actions":
        reasons.append("not_github_actions")
    if ci.get("event_name") != "workflow_dispatch":
        reasons.append("not_manual_workflow_dispatch")
    if ci.get("ref") != "refs/heads/main":
        reasons.append("not_main_branch")
    if ci.get("workflow") != "NADI Release Gates":
        reasons.append("unexpected_workflow")
    for key in ("repository", "repository_id", "server_url", "commit_sha", "run_id", "run_attempt", "workflow", "workflow_ref", "workflow_sha", "job", "runner_os", "runner_arch"):
        if not ci.get(key):
            reasons.append(f"missing_ci_{key}")

    gate_details = manifest.get("gate_details")
    package = gate_details.get("package") if isinstance(gate_details, dict) else None
    package_name = package.get("name") if isinstance(package, dict) else None
    if not isinstance(package_name, str) or not package_name.endswith(".zip"):
        reasons.append("release_package_missing")
    elif package_name == "NADI-LSP-MIGAS-ci-gate.zip" or "ci-gate" in package_name.lower():
        reasons.append("placeholder_release_version")
    else:
        prefix, suffix = "NADI-LSP-MIGAS-", ".zip"
        version = package_name[len(prefix):-len(suffix)] if package_name.startswith(prefix) and package_name.endswith(suffix) else ""
        if not re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?", version):
            reasons.append("non_semantic_release_version")

    return not reasons, reasons

def ingest(repo: Path, bundle_dir: Path, dest: Path, require_pass: bool) -> None:
    manifest = verify(repo, bundle_dir, require_pass=require_pass)
    if dest.exists():
        raise ValueError(f"ingestion destination already exists: {dest}")
    shutil.copytree(bundle_dir, dest)
    result = {
        "schema": INGEST_SCHEMA,
        "ingested_at_utc": datetime.now(timezone.utc).isoformat(),
        "gate_status": manifest["gate_status"],
        "bundle_manifest_sha256": sha256_file(dest / MANIFEST),
        "source_manifest_sha256": sha256_file(repo / "AUDITED_SOURCE_RC_MANIFEST.sha256"),
        "ci": manifest.get("ci", {}),
        "release_authorizable": production_release_authorizable(manifest)[0],
        "release_authorization_blockers": production_release_authorizable(manifest)[1],
    }
    (dest / INGEST_RESULT).write_text(json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(f"PASS CI evidence ingestion: status={manifest['gate_status']} destination={dest}")


def main() -> int:
    parser = argparse.ArgumentParser(description="Bundle, verify, and ingest source-bound NADI CI release-gate evidence.")
    parser.add_argument("mode", choices=["bundle", "verify", "ingest"])
    parser.add_argument("--repo-root", default=".")
    parser.add_argument("--gate-dir", default="artifacts/release-gate")
    parser.add_argument("--browser-dir", default="artifacts/browser-acceptance")
    parser.add_argument("--package", default=None)
    parser.add_argument("--bundle-dir", default="artifacts/ci-evidence")
    parser.add_argument("--destination", default=None)
    parser.add_argument("--require-pass", action="store_true")
    args = parser.parse_args()

    repo = Path(args.repo_root).resolve()
    try:
        if args.mode == "bundle":
            package = Path(args.package).resolve() if args.package else None
            bundle(repo, (repo / args.gate_dir).resolve(), (repo / args.browser_dir).resolve(), package, (repo / args.bundle_dir).resolve())
        elif args.mode == "verify":
            manifest = verify(repo, Path(args.bundle_dir).resolve(), require_pass=args.require_pass)
            print(f"PASS CI evidence verification: status={manifest['gate_status']} source_bound=yes closed_world=yes")
        else:
            if not args.destination:
                raise ValueError("--destination is required for ingest")
            ingest(repo, Path(args.bundle_dir).resolve(), Path(args.destination).resolve(), args.require_pass)
    except ValueError as exc:
        print(f"FAIL: {exc}", file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
