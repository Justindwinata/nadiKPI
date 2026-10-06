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

from production_deployment_intake import verify_release_tree

INTAKE_SCHEMA = "nadi.production-deployment-intake.v1"
POST_SCHEMA = "nadi.post-deploy-verification.v1"
POST_BUNDLE_SCHEMA = "nadi.post-deploy-evidence-bundle.v1"
DECISION_SCHEMA = "nadi.production-operational-acceptance.v1"
FINAL_DECISION_SCHEMA = "nadi.final-release-decision.v1"
CONSUMPTION_SCHEMA = "nadi.external-ci-consumption.v1"
MANIFEST_NAME = "POST_DEPLOY_EVIDENCE_MANIFEST.json"
POST_NAME = "POST_DEPLOY_VERIFICATION.json"
REQUIRED_EVIDENCE = {
    "intake.log",
    "release-check.log",
    "schedule.log",
    "up.headers",
    "up.status",
    "ready.headers",
    "ready.status",
    "spa.headers",
    "spa.status",
    POST_NAME,
}
MAX_ARCHIVE_FILES = 200
MAX_ARCHIVE_UNCOMPRESSED_BYTES = 100 * 1024 * 1024
MAX_MEMBER_UNCOMPRESSED_BYTES = 20 * 1024 * 1024


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def require_regular(path: Path, label: str) -> None:
    if not path.is_file() or path.is_symlink():
        raise ValueError(f"{label} missing/not a regular file: {path}")


def load_json(path: Path) -> dict[str, Any]:
    require_regular(path, "JSON file")
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise ValueError(f"expected JSON object: {path}")
    return data


def safe_member_name(raw: str) -> str:
    value = raw.replace("\\", "/")
    if not value or value.startswith("/") or "\x00" in value:
        raise ValueError(f"unsafe archive member: {raw!r}")
    parts = Path(value).parts
    if any(part in {"", ".", ".."} for part in parts):
        raise ValueError(f"unsafe archive member: {raw!r}")
    if parts and parts[0].endswith(":"):
        raise ValueError(f"unsafe archive member: {raw!r}")
    return Path(*parts).as_posix()


def reject_symlinks(root: Path) -> None:
    for p in root.rglob("*"):
        if p.is_symlink():
            raise ValueError(f"symlink refused: {p.relative_to(root)}")


def safe_extract_zip(archive_path: Path, destination: Path) -> str:
    require_regular(archive_path, "post-deploy evidence ZIP")
    archive_sha = sha256_file(archive_path)
    with zipfile.ZipFile(archive_path, "r") as archive:
        infos = archive.infolist()
        if len(infos) > MAX_ARCHIVE_FILES:
            raise ValueError(f"post-deploy archive has too many entries: {len(infos)}")
        seen: set[str] = set()
        total = 0
        normalized: list[tuple[zipfile.ZipInfo, str]] = []
        for info in infos:
            raw = info.filename.rstrip("/")
            if not raw:
                continue
            name = safe_member_name(raw)
            if name in seen:
                raise ValueError(f"duplicate post-deploy archive member: {name}")
            seen.add(name)
            mode = (info.external_attr >> 16) & 0o170000
            if mode == stat.S_IFLNK:
                raise ValueError(f"post-deploy archive symlink refused: {name}")
            if info.file_size > MAX_MEMBER_UNCOMPRESSED_BYTES:
                raise ValueError(f"post-deploy archive member too large: {name}")
            total += info.file_size
            if total > MAX_ARCHIVE_UNCOMPRESSED_BYTES:
                raise ValueError("post-deploy archive uncompressed size exceeds safety limit")
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
    candidates = [p.parent for p in root.rglob(MANIFEST_NAME) if p.is_file() and not p.is_symlink()]
    if len(candidates) != 1:
        raise ValueError(f"expected exactly one {MANIFEST_NAME}, found {len(candidates)}")
    bundle = candidates[0]
    outsiders = []
    for p in root.rglob("*"):
        if p.is_file() and bundle not in p.parents and p != bundle:
            outsiders.append(p.relative_to(root).as_posix())
    if outsiders:
        raise ValueError(f"unexpected files outside post-deploy evidence bundle: {outsiders[:5]}")
    return bundle


def verify_deployment_envelope(repo: Path, envelope: Path, expected_repository: str) -> dict[str, Any]:
    if not envelope.is_dir() or envelope.is_symlink():
        raise ValueError("deployment envelope must be a regular directory")
    reject_symlinks(envelope)
    receipt_path = envelope / "DEPLOYMENT_INTAKE.json"
    intake = load_json(receipt_path)
    if intake.get("schema") != INTAKE_SCHEMA or intake.get("status") != "AUTHORIZED_FOR_DEPLOYMENT":
        raise ValueError("deployment intake is not authorized")
    source_manifest = repo / "AUDITED_SOURCE_RC_MANIFEST.sha256"
    require_regular(source_manifest, "audited source manifest")
    source_sha = sha256_file(source_manifest)
    if intake.get("source_manifest_sha256") != source_sha:
        raise ValueError("deployment intake source manifest does not match this checkpoint")
    if intake.get("expected_repository") != expected_repository:
        raise ValueError("deployment intake expected repository mismatch")
    ci = intake.get("ci")
    if not isinstance(ci, dict) or ci.get("repository") != expected_repository:
        raise ValueError("deployment intake CI repository mismatch")
    if ci.get("event_name") != "workflow_dispatch" or ci.get("ref") != "refs/heads/main":
        raise ValueError("deployment intake provenance is not workflow_dispatch on main")

    allowed_root = {"DEPLOYMENT_INTAKE.json", "custody", "release"}
    if {p.name for p in envelope.iterdir()} != allowed_root:
        raise ValueError("deployment envelope root is not closed-world")

    custody = envelope / "custody"
    release_root = envelope / "release"
    if not custody.is_dir() or not release_root.is_dir():
        raise ValueError("deployment envelope custody/release directories missing")
    custody_files = intake.get("custody_files")
    if not isinstance(custody_files, list) or len(custody_files) != 4:
        raise ValueError("deployment custody file set must contain exactly four files")
    expected_custody: set[str] = set()
    for entry in custody_files:
        if not isinstance(entry, dict):
            raise ValueError("invalid deployment custody entry")
        rel = str(entry.get("path") or "")
        if not rel.startswith("custody/") or ".." in Path(rel).parts:
            raise ValueError(f"unsafe custody path: {rel}")
        path = envelope / rel
        require_regular(path, f"custody file {rel}")
        if path.stat().st_size != entry.get("bytes") or sha256_file(path) != entry.get("sha256"):
            raise ValueError(f"custody file mismatch: {rel}")
        expected_custody.add(Path(rel).name)
    actual_custody = {p.name for p in custody.iterdir() if p.is_file()}
    if actual_custody != expected_custody or any(p.is_dir() for p in custody.iterdir()):
        raise ValueError("deployment custody directory is not closed-world")

    decision_path = custody / "FINAL_RELEASE_DECISION.json"
    consumption_path = custody / "EXTERNAL_CI_CONSUMPTION.json"
    decision = load_json(decision_path)
    consumption = load_json(consumption_path)
    if decision.get("schema") != FINAL_DECISION_SCHEMA or decision.get("decision") != "FINAL_PASS":
        raise ValueError("custodied FINAL release decision is invalid")
    if consumption.get("schema") != CONSUMPTION_SCHEMA or consumption.get("status") != "FINAL_PASS":
        raise ValueError("custodied external CI consumption receipt is invalid")
    if decision.get("source_manifest_sha256") != source_sha or consumption.get("source_manifest_sha256") != source_sha:
        raise ValueError("custodied source binding mismatch")
    if decision.get("ci") != ci or consumption.get("ci") != ci:
        raise ValueError("custodied CI provenance mismatch")
    if sha256_file(decision_path) != intake.get("final_release_decision_sha256"):
        raise ValueError("FINAL release decision hash mismatch")
    if sha256_file(consumption_path) != intake.get("external_ci_consumption_sha256"):
        raise ValueError("external CI consumption hash mismatch")

    package = intake.get("release_package")
    if not isinstance(package, dict):
        raise ValueError("deployment intake release package missing")
    package_path = envelope / str(package.get("path") or "")
    require_regular(package_path, "custodied release package")
    if package_path.stat().st_size != package.get("bytes") or sha256_file(package_path) != package.get("sha256"):
        raise ValueError("custodied release package mismatch")

    release_manifest = verify_release_tree(release_root)
    manifest_path = release_root / "RELEASE_MANIFEST.json"
    if sha256_file(manifest_path) != intake.get("release_manifest_sha256"):
        raise ValueError("deployment release manifest hash mismatch")
    if release_manifest.get("version") != intake.get("version"):
        raise ValueError("deployment release version mismatch")
    return {"intake": intake, "intake_path": receipt_path, "release_manifest": release_manifest}


def verify_post_deploy_bundle(bundle: Path, intake_info: dict[str, Any], expected_repository: str) -> dict[str, Any]:
    reject_symlinks(bundle)
    manifest_path = bundle / MANIFEST_NAME
    manifest = load_json(manifest_path)
    if manifest.get("schema") != POST_BUNDLE_SCHEMA or manifest.get("status") != "PASS":
        raise ValueError("post-deploy evidence manifest is not PASS")
    entries = manifest.get("files")
    if not isinstance(entries, list):
        raise ValueError("post-deploy evidence manifest files must be an array")
    expected: dict[str, dict[str, Any]] = {}
    for entry in entries:
        if not isinstance(entry, dict):
            raise ValueError("invalid post-deploy evidence entry")
        rel = str(entry.get("path") or "")
        digest = str(entry.get("sha256") or "")
        size = entry.get("bytes")
        if not rel or "/" in rel or rel in expected or not re.fullmatch(r"[0-9a-f]{64}", digest) or not isinstance(size, int):
            raise ValueError(f"invalid post-deploy evidence entry: {entry}")
        expected[rel] = entry
    if set(expected) != REQUIRED_EVIDENCE:
        raise ValueError(f"post-deploy evidence file set mismatch: {sorted(expected)}")
    actual = {p.name for p in bundle.iterdir() if p.is_file() and p.name != MANIFEST_NAME}
    dirs = [p.name for p in bundle.iterdir() if p.is_dir()]
    if actual != REQUIRED_EVIDENCE or dirs:
        raise ValueError(f"post-deploy bundle is not closed-world: files={sorted(actual)} dirs={dirs}")
    for rel, entry in expected.items():
        path = bundle / rel
        require_regular(path, f"post-deploy evidence {rel}")
        if path.stat().st_size != entry["bytes"] or sha256_file(path) != entry["sha256"]:
            raise ValueError(f"post-deploy evidence hash/size mismatch: {rel}")

    post = load_json(bundle / POST_NAME)
    if post.get("schema") != POST_SCHEMA or post.get("status") != "PASS":
        raise ValueError("post-deploy verification is not PASS")
    intake = intake_info["intake"]
    if post.get("deployment_intake_sha256") != sha256_file(intake_info["intake_path"]):
        raise ValueError("post-deploy verification deployment intake binding mismatch")
    if post.get("release_manifest_sha256") != intake.get("release_manifest_sha256"):
        raise ValueError("post-deploy verification release manifest binding mismatch")
    if post.get("version") != intake.get("version"):
        raise ValueError("post-deploy verification version mismatch")
    if post.get("expected_repository") != expected_repository or manifest.get("expected_repository") != expected_repository:
        raise ValueError("post-deploy expected repository mismatch")
    if post.get("ci") != intake.get("ci") or manifest.get("ci") != intake.get("ci"):
        raise ValueError("post-deploy CI provenance mismatch")
    if manifest.get("deployment_intake_sha256") != post.get("deployment_intake_sha256"):
        raise ValueError("post-deploy manifest intake binding mismatch")
    if manifest.get("post_deploy_verification_sha256") != sha256_file(bundle / POST_NAME):
        raise ValueError("post-deploy manifest does not bind verification JSON")
    if not str(post.get("base_url") or "").startswith("https://"):
        raise ValueError("post-deploy base URL is not HTTPS")

    required_checks = {
        "deployment_intake",
        "production_release_check",
        "scheduler_nadi_monitor_risks",
        "liveness_http_200",
        "readiness_http_200",
        "spa_http_200",
        "hsts",
        "csp",
        "x_content_type_options_nosniff",
    }
    checks = post.get("checks")
    if not isinstance(checks, dict) or set(checks) != required_checks or any(v != "pass" for v in checks.values()):
        raise ValueError("post-deploy verification check matrix is incomplete/non-pass")

    for name in ("up", "ready", "spa"):
        if (bundle / f"{name}.status").read_text(encoding="utf-8").strip() != "200":
            raise ValueError(f"post-deploy {name} status is not 200")
    spa_headers = (bundle / "spa.headers").read_text(encoding="utf-8", errors="replace").lower()
    if "strict-transport-security:" not in spa_headers:
        raise ValueError("post-deploy evidence missing HSTS")
    if "content-security-policy:" not in spa_headers:
        raise ValueError("post-deploy evidence missing CSP")
    if not re.search(r"^x-content-type-options:\s*nosniff\s*$", spa_headers, flags=re.MULTILINE):
        raise ValueError("post-deploy evidence missing X-Content-Type-Options nosniff")
    schedule = (bundle / "schedule.log").read_text(encoding="utf-8", errors="replace")
    if "nadi:monitor-risks" not in schedule:
        raise ValueError("post-deploy schedule evidence missing nadi:monitor-risks")

    evidence_sha = post.get("evidence_sha256")
    if not isinstance(evidence_sha, dict):
        raise ValueError("post-deploy verification evidence_sha256 missing")
    expected_hash_keys = {
        "intake_log": "intake.log",
        "release_check_log": "release-check.log",
        "schedule_log": "schedule.log",
        "up_headers": "up.headers",
        "up_status": "up.status",
        "ready_headers": "ready.headers",
        "ready_status": "ready.status",
        "spa_headers": "spa.headers",
        "spa_status": "spa.status",
    }
    if set(evidence_sha) != set(expected_hash_keys):
        raise ValueError("post-deploy verification evidence hash matrix mismatch")
    for key, rel in expected_hash_keys.items():
        if evidence_sha.get(key) != sha256_file(bundle / rel):
            raise ValueError(f"post-deploy evidence SHA mismatch: {key}")
    return {"manifest": manifest, "manifest_path": manifest_path, "post": post}


def ingest_and_decide(
    repo: Path,
    deployment_envelope: Path,
    evidence_input: Path,
    expected_repository: str,
    ingestion_root: Path,
) -> Path:
    intake_info = verify_deployment_envelope(repo, deployment_envelope, expected_repository)
    temp_parent: tempfile.TemporaryDirectory[str] | None = None
    external_artifact: dict[str, Any]
    try:
        if evidence_input.is_symlink():
            raise ValueError("post-deploy evidence input must not be a symlink")
        if evidence_input.is_dir():
            root = evidence_input.resolve()
            reject_symlinks(root)
            bundle = root if (root / MANIFEST_NAME).is_file() else locate_bundle_root(root)
            external_artifact = {
                "kind": "directory",
                "name": evidence_input.name,
                "bundle_manifest_sha256": sha256_file(bundle / MANIFEST_NAME),
            }
        elif evidence_input.is_file():
            if not zipfile.is_zipfile(evidence_input):
                raise ValueError("post-deploy evidence artifact must be a ZIP or directory")
            temp_parent = tempfile.TemporaryDirectory(prefix="nadi-post-deploy-")
            root = Path(temp_parent.name)
            archive_sha = safe_extract_zip(evidence_input.resolve(), root)
            bundle = locate_bundle_root(root)
            external_artifact = {
                "kind": "zip",
                "name": evidence_input.name,
                "sha256": archive_sha,
                "bytes": evidence_input.stat().st_size,
            }
        else:
            raise ValueError("post-deploy evidence input does not exist")

        verified = verify_post_deploy_bundle(bundle, intake_info, expected_repository)
        intake = intake_info["intake"]
        ci = intake["ci"]
        version = str(intake["version"])
        run_id = str(ci.get("run_id") or "unknown")
        run_attempt = str(ci.get("run_attempt") or "unknown")
        destination = ingestion_root / f"{version}-{run_id}-{run_attempt}"
        if destination.exists() or destination.is_symlink():
            raise ValueError(f"operational acceptance ingestion destination already exists: {destination}")
        ingestion_root.mkdir(parents=True, exist_ok=True)
        stage = Path(tempfile.mkdtemp(prefix=f".{destination.name}.staging-", dir=ingestion_root))
        keep = False
        try:
            shutil.copytree(bundle, stage / "post-deploy", dirs_exist_ok=False)
            shutil.copyfile(intake_info["intake_path"], stage / "DEPLOYMENT_INTAKE.json")
            decision = {
                "schema": DECISION_SCHEMA,
                "decision": "ACCEPTED",
                "accepted_at_utc": datetime.now(timezone.utc).isoformat(),
                "version": version,
                "expected_repository": expected_repository,
                "source_manifest_sha256": intake["source_manifest_sha256"],
                "deployment_intake_sha256": sha256_file(stage / "DEPLOYMENT_INTAKE.json"),
                "post_deploy_evidence_manifest_sha256": sha256_file(stage / "post-deploy" / MANIFEST_NAME),
                "post_deploy_verification_sha256": sha256_file(stage / "post-deploy" / POST_NAME),
                "release_manifest_sha256": intake["release_manifest_sha256"],
                "release_package": intake["release_package"],
                "base_url": verified["post"]["base_url"],
                "ci": ci,
                "external_artifact": external_artifact,
                "acceptance_policy": {
                    "authorized_deployment_intake_required": True,
                    "source_checkpoint_binding_required": True,
                    "post_deploy_closed_world_evidence_required": True,
                    "all_post_deploy_checks_required": True,
                    "https_required": True,
                    "no_synthetic_promotion": True,
                },
            }
            decision_path = stage / "PRODUCTION_OPERATIONAL_ACCEPTANCE.json"
            decision_path.write_text(json.dumps(decision, indent=2, sort_keys=True) + "\n", encoding="utf-8")
            os.replace(stage, destination)
            keep = True
        finally:
            if not keep:
                shutil.rmtree(stage, ignore_errors=True)
        return destination / "PRODUCTION_OPERATIONAL_ACCEPTANCE.json"
    finally:
        if temp_parent is not None:
            temp_parent.cleanup()


def main() -> int:
    parser = argparse.ArgumentParser(description="Verify and ingest production post-deploy evidence, then issue a source-bound operational acceptance decision.")
    parser.add_argument("--repo-root", default=".")
    parser.add_argument("--deployment-envelope", required=True)
    parser.add_argument("--post-deploy-evidence", required=True, help="Closed-world evidence directory or ZIP from the production target")
    parser.add_argument("--expected-repository", default=os.environ.get("NADI_EXPECTED_GITHUB_REPOSITORY"))
    parser.add_argument("--ingestion-root", default="artifacts/production-acceptance")
    args = parser.parse_args()
    if not args.expected_repository:
        print("FAIL: --expected-repository (or NADI_EXPECTED_GITHUB_REPOSITORY) is required", file=sys.stderr)
        return 2
    repo = Path(args.repo_root).resolve()
    envelope = Path(args.deployment_envelope).resolve()
    evidence = Path(args.post_deploy_evidence).resolve()
    root = Path(args.ingestion_root)
    ingestion_root = (repo / root).resolve() if not root.is_absolute() else root.resolve()
    try:
        decision = ingest_and_decide(repo, envelope, evidence, args.expected_repository, ingestion_root)
    except (ValueError, OSError, json.JSONDecodeError, zipfile.BadZipFile) as exc:
        print(f"FAIL: {exc}", file=sys.stderr)
        return 2
    print(f"PASS production operational acceptance: {decision}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
