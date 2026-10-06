#!/usr/bin/env python3
"""NADI long-term release archive, recovery rehearsal and operational closeout.

This tool is post-G08 custody tooling. It never creates release/runtime PASS evidence.
A real archive can only be created from an independently verified closure ZIP and a
verified operator handoff packet bound to the same source checkpoint/repository/version.
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

import release_closure_handoff as rch
import release_closure_matrix as rcm

ARCHIVE_SCHEMA = "nadi.release-archive.v1"
MANIFEST_SCHEMA = "nadi.release-archive-manifest.v1"
RECOVERY_SCHEMA = "nadi.release-archive-recovery-rehearsal.v1"
CLOSEOUT_SCHEMA = "nadi.operational-release-closeout.v1"
POLICY_SCHEMA = "nadi.release-archive-policy.v1"
ARCHIVE_NAME = "RELEASE_ARCHIVE.json"
MANIFEST_NAME = "RELEASE_ARCHIVE_MANIFEST.json"
MANIFEST_DIGEST_NAME = "RELEASE_ARCHIVE_MANIFEST.json.sha256"
RECOVERY_NAME = "RECOVERY_REHEARSAL.json"
CLOSEOUT_NAME = "OPERATIONAL_CLOSEOUT.json"
POLICY_COPY_NAME = "RELEASE_ARCHIVE_POLICY.json"
MAX_ARCHIVE_FILES = 500
MAX_ARCHIVE_UNCOMPRESSED_BYTES = 1024 * 1024 * 1024
MAX_MEMBER_UNCOMPRESSED_BYTES = 512 * 1024 * 1024
VERSION_RE = re.compile(r"^v?\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$")
PRIVATE_KEY_MARKERS = ("-----BEGIN PRIVATE KEY-----", "-----BEGIN RSA PRIVATE KEY-----", "-----BEGIN OPENSSH PRIVATE KEY-----")
SENSITIVE_ASSIGNMENT_RE = re.compile(r"(?im)^\s*(APP_KEY|DB_PASSWORD|NADI_ACCEPTANCE_PASSWORD|NADI_ACCEPTANCE_NEW_PASSWORD|NADI_ACCEPTANCE_VIEWER_PASSWORD)\s*=\s*(.+)$")


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
        raise ValueError(f"JSON object required: {path}")
    return data


def evidence_now() -> datetime:
    raw = os.environ.get("SOURCE_DATE_EPOCH", "").strip()
    if raw:
        try:
            epoch = int(raw)
        except ValueError as exc:
            raise ValueError("SOURCE_DATE_EPOCH must be an integer") from exc
        return datetime.fromtimestamp(epoch, tz=timezone.utc)
    return datetime.now(timezone.utc)


def parse_utc(value: str, label: str) -> datetime:
    normalized = value.strip().replace("Z", "+00:00")
    try:
        dt = datetime.fromisoformat(normalized)
    except ValueError as exc:
        raise ValueError(f"{label} must be ISO-8601") from exc
    if dt.tzinfo is None:
        raise ValueError(f"{label} must include timezone")
    return dt.astimezone(timezone.utc)


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
    for path in root.rglob("*"):
        if path.is_symlink():
            raise ValueError(f"symlink refused: {path.relative_to(root)}")


def safe_extract_zip(source: Path, destination: Path) -> None:
    require_regular(source, "archive ZIP")
    with zipfile.ZipFile(source, "r") as archive:
        infos = archive.infolist()
        if len(infos) > MAX_ARCHIVE_FILES:
            raise ValueError(f"archive has too many entries: {len(infos)}")
        total = 0
        seen: set[str] = set()
        normalized: list[tuple[zipfile.ZipInfo, str]] = []
        for info in infos:
            raw = info.filename.rstrip("/")
            if not raw:
                continue
            name = safe_member_name(raw)
            if name in seen:
                raise ValueError(f"duplicate archive member: {name}")
            seen.add(name)
            mode = (info.external_attr >> 16) & 0o170000
            if mode == stat.S_IFLNK:
                raise ValueError(f"archive symlink refused: {name}")
            if info.file_size > MAX_MEMBER_UNCOMPRESSED_BYTES:
                raise ValueError(f"archive member too large: {name}")
            total += info.file_size
            if total > MAX_ARCHIVE_UNCOMPRESSED_BYTES:
                raise ValueError("archive uncompressed size exceeds safety limit")
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


def scan_secret_leaks(root: Path) -> None:
    forbidden_names = {".env", ".env.production", ".env.local", "id_rsa", "id_ed25519"}
    for path in root.rglob("*"):
        if not path.is_file() or path.name in {MANIFEST_NAME, MANIFEST_DIGEST_NAME}:
            continue
        if path.name in forbidden_names or path.name.startswith(".env."):
            raise ValueError(f"release archive contains forbidden secret/runtime file: {path.relative_to(root)}")
        if path.stat().st_size > 5 * 1024 * 1024:
            continue
        try:
            text = path.read_text(encoding="utf-8")
        except (UnicodeDecodeError, OSError):
            continue
        if any(marker in text for marker in PRIVATE_KEY_MARKERS):
            raise ValueError(f"release archive contains private-key material: {path.relative_to(root)}")
        match = SENSITIVE_ASSIGNMENT_RE.search(text)
        if match:
            raise ValueError(f"release archive contains credential assignment {match.group(1)} in {path.relative_to(root)}")


def load_policy(repo: Path) -> tuple[dict[str, Any], str]:
    path = repo / "config" / "release-archive-policy.json"
    policy = load_json(path)
    if policy.get("schema") != POLICY_SCHEMA or policy.get("version") != 1:
        raise ValueError("release archive policy schema/version invalid")
    req = policy.get("requirements")
    if not isinstance(req, dict) or not req or any(value is not True for value in req.values()):
        raise ValueError("release archive policy requirements must all be true")
    if policy.get("retention_rule") != "operator_supplied_organization_policy":
        raise ValueError("release archive retention rule invalid")
    return policy, sha256_file(path)


def manifest_entries(root: Path) -> list[dict[str, Any]]:
    entries: list[dict[str, Any]] = []
    for path in sorted(root.rglob("*"), key=lambda p: p.relative_to(root).as_posix()):
        if not path.is_file():
            continue
        rel = path.relative_to(root).as_posix()
        if rel in {MANIFEST_NAME, MANIFEST_DIGEST_NAME}:
            continue
        entries.append({"path": rel, "bytes": path.stat().st_size, "sha256": sha256_file(path)})
    return entries


def write_manifest(root: Path, metadata: dict[str, Any]) -> None:
    entries = manifest_entries(root)
    payload = {
        "schema": MANIFEST_SCHEMA,
        "status": "ARCHIVE_READY",
        "version": metadata["version"],
        "expected_repository": metadata["expected_repository"],
        "source_manifest_sha256": metadata["source_manifest_sha256"],
        "archive_policy_sha256": metadata["archive_policy_sha256"],
        "file_count": len(entries),
        "files": entries,
    }
    path = root / MANIFEST_NAME
    path.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    (root / MANIFEST_DIGEST_NAME).write_text(f"{sha256_file(path)}  {MANIFEST_NAME}\n", encoding="utf-8")


def verify_manifest(root: Path) -> dict[str, Any]:
    manifest = load_json(root / MANIFEST_NAME)
    if manifest.get("schema") != MANIFEST_SCHEMA or manifest.get("status") != "ARCHIVE_READY":
        raise ValueError("release archive manifest schema/status invalid")
    expected_sidecar = f"{sha256_file(root / MANIFEST_NAME)}  {MANIFEST_NAME}"
    require_regular(root / MANIFEST_DIGEST_NAME, "archive manifest digest")
    if (root / MANIFEST_DIGEST_NAME).read_text(encoding="utf-8").strip() != expected_sidecar:
        raise ValueError("release archive manifest digest mismatch")
    files = manifest.get("files")
    if not isinstance(files, list):
        raise ValueError("release archive manifest files must be an array")
    expected: dict[str, dict[str, Any]] = {}
    for entry in files:
        if not isinstance(entry, dict):
            raise ValueError("invalid release archive manifest entry")
        rel = str(entry.get("path") or "")
        safe_member_name(rel)
        if rel in expected or not re.fullmatch(r"[0-9a-f]{64}", str(entry.get("sha256") or "")) or not isinstance(entry.get("bytes"), int):
            raise ValueError(f"invalid release archive manifest entry: {entry}")
        expected[rel] = entry
    actual = {
        p.relative_to(root).as_posix()
        for p in root.rglob("*")
        if p.is_file() and p.relative_to(root).as_posix() not in {MANIFEST_NAME, MANIFEST_DIGEST_NAME}
    }
    if actual != set(expected):
        raise ValueError(f"release archive closed-world mismatch: missing={sorted(set(expected)-actual)[:5]} extra={sorted(actual-set(expected))[:5]}")
    if manifest.get("file_count") != len(expected):
        raise ValueError("release archive manifest file_count mismatch")
    for rel, entry in expected.items():
        path = root / rel
        require_regular(path, f"release archive file {rel}")
        if path.stat().st_size != entry["bytes"] or sha256_file(path) != entry["sha256"]:
            raise ValueError(f"release archive file hash/size mismatch: {rel}")
    return manifest


def make_deterministic_zip(root: Path, output: Path) -> None:
    if output.exists() or output.is_symlink():
        raise ValueError(f"archive ZIP output already exists: {output}")
    output.parent.mkdir(parents=True, exist_ok=True)
    dt = evidence_now()
    # ZIP timestamps cannot represent dates before 1980 and have 2-second precision.
    year = max(dt.year, 1980)
    zdt = (year, dt.month, dt.day, dt.hour, dt.minute, dt.second - (dt.second % 2))
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
            zf.writestr(info, path.read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)


def archive_root(artifact: Path) -> tuple[Path, tempfile.TemporaryDirectory[str] | None]:
    if artifact.is_symlink():
        raise ValueError("release archive symlink refused")
    if artifact.is_dir():
        root = artifact.resolve()
        reject_symlinks(root)
        return root, None
    if artifact.is_file() and zipfile.is_zipfile(artifact):
        temp = tempfile.TemporaryDirectory(prefix="nadi-release-archive-")
        root = Path(temp.name)
        safe_extract_zip(artifact.resolve(), root)
        return root, temp
    raise ValueError("release archive must be a directory or ZIP")


def verify_archive(repo: Path, artifact: Path, expected_repository: str) -> dict[str, Any]:
    root, temp = archive_root(artifact)
    try:
        expected_root = {ARCHIVE_NAME, MANIFEST_NAME, MANIFEST_DIGEST_NAME, POLICY_COPY_NAME, "closure", "operator", "source"}
        if {p.name for p in root.iterdir()} != expected_root:
            raise ValueError("release archive root is not closed-world")
        reject_symlinks(root)
        scan_secret_leaks(root)
        manifest = verify_manifest(root)
        metadata = load_json(root / ARCHIVE_NAME)
        if metadata.get("schema") != ARCHIVE_SCHEMA or metadata.get("status") != "ARCHIVE_READY":
            raise ValueError("release archive schema/status invalid")
        policy, policy_sha = load_policy(repo)
        archived_policy = load_json(root / POLICY_COPY_NAME)
        if archived_policy != policy or metadata.get("archive_policy_sha256") != policy_sha or manifest.get("archive_policy_sha256") != policy_sha:
            raise ValueError("release archive policy binding mismatch")
        source_path = root / "source" / "AUDITED_SOURCE_RC_MANIFEST.sha256"
        matrix_path = root / "source" / "release-closure-verification-matrix.json"
        require_regular(source_path, "archived source manifest")
        require_regular(matrix_path, "archived closure matrix")
        current_source_sha = sha256_file(repo / "AUDITED_SOURCE_RC_MANIFEST.sha256")
        current_matrix_sha = sha256_file(repo / "config" / "release-closure-verification-matrix.json")
        if sha256_file(source_path) != current_source_sha or metadata.get("source_manifest_sha256") != current_source_sha or manifest.get("source_manifest_sha256") != current_source_sha:
            raise ValueError("release archive source checkpoint mismatch")
        if source_path.read_bytes() != (repo / "AUDITED_SOURCE_RC_MANIFEST.sha256").read_bytes():
            raise ValueError("archived source manifest contents differ from current checkpoint")
        if sha256_file(matrix_path) != current_matrix_sha or metadata.get("matrix_sha256") != current_matrix_sha:
            raise ValueError("release archive closure matrix mismatch")
        if matrix_path.read_bytes() != (repo / "config" / "release-closure-verification-matrix.json").read_bytes():
            raise ValueError("archived closure matrix contents differ from current checkpoint")
        if metadata.get("expected_repository") != expected_repository or manifest.get("expected_repository") != expected_repository:
            raise ValueError("release archive expected repository mismatch")
        version = str(metadata.get("version") or "")
        if not VERSION_RE.fullmatch(version) or manifest.get("version") != version:
            raise ValueError("release archive version invalid/mismatched")
        archived_at = parse_utc(str(metadata.get("archived_at_utc") or ""), "archived_at_utc")
        retention_until = parse_utc(str(metadata.get("retention_until_utc") or ""), "retention_until_utc")
        if retention_until <= archived_at:
            raise ValueError("retention_until_utc must be later than archived_at_utc")
        closure_files = list((root / "closure").iterdir())
        if len(closure_files) != 1 or not closure_files[0].is_file() or closure_files[0].is_symlink() or not zipfile.is_zipfile(closure_files[0]):
            raise ValueError("release archive must contain exactly one closure ZIP")
        closure_zip = closure_files[0]
        closure_meta = metadata.get("closure_artifact")
        if not isinstance(closure_meta, dict) or closure_meta.get("path") != f"closure/{closure_zip.name}" or closure_meta.get("sha256") != sha256_file(closure_zip) or closure_meta.get("bytes") != closure_zip.stat().st_size:
            raise ValueError("release archive closure artifact metadata mismatch")
        closure = rch.verify_handoff(repo, closure_zip, expected_repository)
        if closure.get("version") != version:
            raise ValueError("release archive closure version mismatch")
        operator_dir = root / "operator"
        snapshot = rcm.verify_handoff_export(repo, operator_dir)
        if snapshot.get("status") != "RELEASE_CLOSURE_VERIFIED" or snapshot.get("release_closure_verified") is not True:
            raise ValueError("archived operator handoff is not closure-verified")
        if snapshot.get("expected_repository") != expected_repository or snapshot.get("release_version") != version:
            raise ValueError("archived operator handoff repository/version mismatch")
        if snapshot.get("closure_artifact_sha256") != sha256_file(closure_zip):
            raise ValueError("archived operator handoff closure artifact binding mismatch")
        operator_manifest_sha = sha256_file(operator_dir / "OPERATOR_HANDOFF_MANIFEST.json")
        if metadata.get("operator_handoff_manifest_sha256") != operator_manifest_sha:
            raise ValueError("release archive operator handoff manifest binding mismatch")
        return {
            "schema": ARCHIVE_SCHEMA,
            "status": "ARCHIVE_VERIFIED",
            "version": version,
            "expected_repository": expected_repository,
            "source_manifest_sha256": current_source_sha,
            "matrix_sha256": current_matrix_sha,
            "archive_policy_sha256": policy_sha,
            "retention_until_utc": retention_until.isoformat(),
            "retention_state": "ACTIVE" if evidence_now() <= retention_until else "EXPIRED",
            "closure_sha256": sha256_file(closure_zip),
            "operator_handoff_manifest_sha256": operator_manifest_sha,
        }
    finally:
        if temp is not None:
            temp.cleanup()


def create_archive(repo: Path, closure_zip: Path, operator_dir: Path, expected_repository: str, retention_until_utc: str, output_dir: Path, zip_output: Path | None) -> tuple[Path, Path | None]:
    require_regular(closure_zip, "release closure ZIP")
    if not zipfile.is_zipfile(closure_zip):
        raise ValueError("release closure artifact must be a ZIP for long-term archival")
    closure = rch.verify_handoff(repo, closure_zip, expected_repository)
    snapshot = rcm.verify_handoff_export(repo, operator_dir)
    if snapshot.get("status") != "RELEASE_CLOSURE_VERIFIED" or snapshot.get("release_closure_verified") is not True:
        raise ValueError("operator handoff must be RELEASE_CLOSURE_VERIFIED before archival")
    if snapshot.get("closure_artifact_sha256") != sha256_file(closure_zip):
        raise ValueError("operator handoff does not bind the supplied closure ZIP")
    version = str(closure.get("version") or "")
    if not VERSION_RE.fullmatch(version) or snapshot.get("release_version") != version:
        raise ValueError("closure/operator semantic version mismatch")
    if snapshot.get("expected_repository") != expected_repository:
        raise ValueError("closure/operator repository mismatch")
    now = evidence_now()
    retention_until = parse_utc(retention_until_utc, "retention_until_utc")
    if retention_until <= now:
        raise ValueError("retention_until_utc must be in the future relative to archive creation")
    policy, policy_sha = load_policy(repo)
    if output_dir.exists() or output_dir.is_symlink():
        raise ValueError(f"release archive output already exists: {output_dir}")
    output_dir.parent.mkdir(parents=True, exist_ok=True)
    stage = Path(tempfile.mkdtemp(prefix=f".{output_dir.name}.staging-", dir=output_dir.parent))
    keep = False
    try:
        (stage / "closure").mkdir()
        shutil.copyfile(closure_zip, stage / "closure" / closure_zip.name)
        shutil.copytree(operator_dir, stage / "operator", dirs_exist_ok=False)
        (stage / "source").mkdir()
        shutil.copyfile(repo / "AUDITED_SOURCE_RC_MANIFEST.sha256", stage / "source" / "AUDITED_SOURCE_RC_MANIFEST.sha256")
        shutil.copyfile(repo / "config" / "release-closure-verification-matrix.json", stage / "source" / "release-closure-verification-matrix.json")
        (stage / POLICY_COPY_NAME).write_text(json.dumps(policy, indent=2, sort_keys=True) + "\n", encoding="utf-8")
        metadata = {
            "schema": ARCHIVE_SCHEMA,
            "status": "ARCHIVE_READY",
            "archived_at_utc": now.isoformat(),
            "retention_until_utc": retention_until.isoformat(),
            "retention_authority": "operator_supplied_organization_policy",
            "version": version,
            "expected_repository": expected_repository,
            "source_manifest_sha256": sha256_file(repo / "AUDITED_SOURCE_RC_MANIFEST.sha256"),
            "matrix_sha256": sha256_file(repo / "config" / "release-closure-verification-matrix.json"),
            "archive_policy_sha256": policy_sha,
            "closure_artifact": {
                "path": f"closure/{closure_zip.name}",
                "sha256": sha256_file(closure_zip),
                "bytes": closure_zip.stat().st_size,
            },
            "operator_handoff_manifest_sha256": sha256_file(operator_dir / "OPERATOR_HANDOFF_MANIFEST.json"),
            "archive_policy": policy["requirements"],
        }
        (stage / ARCHIVE_NAME).write_text(json.dumps(metadata, indent=2, sort_keys=True) + "\n", encoding="utf-8")
        scan_secret_leaks(stage)
        write_manifest(stage, metadata)
        os.replace(stage, output_dir)
        keep = True
    finally:
        if not keep:
            shutil.rmtree(stage, ignore_errors=True)
    verify_archive(repo, output_dir, expected_repository)
    if zip_output is not None:
        make_deterministic_zip(output_dir, zip_output)
        verify_archive(repo, zip_output, expected_repository)
    return output_dir / ARCHIVE_NAME, zip_output


def recovery_rehearsal(repo: Path, artifact: Path, expected_repository: str, receipt_output: Path | None) -> dict[str, Any]:
    verified = verify_archive(repo, artifact, expected_repository)
    root, temp = archive_root(artifact)
    try:
        closure_zip = next((root / "closure").iterdir())
        closure = rch.verify_handoff(repo, closure_zip, expected_repository)
        snapshot = rcm.verify_handoff_export(repo, root / "operator")
        payload = {
            "schema": RECOVERY_SCHEMA,
            "status": "PASS",
            "rehearsed_at_utc": evidence_now().isoformat(),
            "version": verified["version"],
            "expected_repository": expected_repository,
            "source_manifest_sha256": verified["source_manifest_sha256"],
            "archive_artifact": {
                "kind": "zip" if artifact.is_file() else "directory",
                "sha256": sha256_file(artifact) if artifact.is_file() else sha256_file(root / MANIFEST_NAME),
            },
            "retention_until_utc": verified["retention_until_utc"],
            "retention_state": verified["retention_state"],
            "checks": {
                "archive_closed_world_integrity": "pass",
                "source_checkpoint_binding": "pass",
                "release_closure_deep_verify": "pass",
                "final_zip_recovery_and_release_tree_match": "pass",
                "operator_handoff_reverify": "pass",
                "repository_version_provenance": "pass",
                "secret_leak_guard": "pass",
            },
            "closure": {
                "sha256": sha256_file(closure_zip),
                "status": closure.get("status"),
            },
            "operator_handoff": {
                "status": snapshot.get("status"),
                "manifest_sha256": sha256_file(root / "operator" / "OPERATOR_HANDOFF_MANIFEST.json"),
            },
            "note": "Recovery rehearsal verifies release/evidence recoverability from the retained archive; it does not perform a production database restore rehearsal.",
        }
        if receipt_output is not None:
            if receipt_output.exists() or receipt_output.is_symlink():
                raise ValueError(f"recovery receipt output already exists: {receipt_output}")
            receipt_output.parent.mkdir(parents=True, exist_ok=True)
            receipt_output.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
        return payload
    finally:
        if temp is not None:
            temp.cleanup()


def operational_closeout(repo: Path, artifact: Path, expected_repository: str, recovery_receipt: Path, output: Path) -> dict[str, Any]:
    verified = verify_archive(repo, artifact, expected_repository)
    recovery = load_json(recovery_receipt)
    if recovery.get("schema") != RECOVERY_SCHEMA or recovery.get("status") != "PASS":
        raise ValueError("recovery rehearsal receipt is not PASS")
    if recovery.get("source_manifest_sha256") != verified["source_manifest_sha256"] or recovery.get("version") != verified["version"] or recovery.get("expected_repository") != expected_repository:
        raise ValueError("recovery rehearsal receipt provenance mismatch")
    archive_identity = recovery.get("archive_artifact")
    if not isinstance(archive_identity, dict):
        raise ValueError("recovery rehearsal archive identity missing")
    expected_archive_sha = sha256_file(artifact) if artifact.is_file() else sha256_file(artifact / MANIFEST_NAME)
    if archive_identity.get("sha256") != expected_archive_sha:
        raise ValueError("recovery rehearsal receipt does not bind the archive being closed out")
    if recovery.get("retention_until_utc") != verified["retention_until_utc"]:
        raise ValueError("recovery rehearsal retention metadata mismatch")
    if any(value != "pass" for value in (recovery.get("checks") or {}).values()):
        raise ValueError("recovery rehearsal check matrix incomplete/non-pass")
    if verified["retention_state"] != "ACTIVE":
        raise ValueError("operational closeout requires an ACTIVE archive retention window")
    if output.exists() or output.is_symlink():
        raise ValueError(f"operational closeout output already exists: {output}")
    payload = {
        "schema": CLOSEOUT_SCHEMA,
        "status": "OPERATIONAL_CLOSEOUT_READY",
        "closed_out_at_utc": evidence_now().isoformat(),
        "version": verified["version"],
        "expected_repository": expected_repository,
        "source_manifest_sha256": verified["source_manifest_sha256"],
        "archive_verification": "PASS",
        "recovery_rehearsal": "PASS",
        "retention_state": verified["retention_state"],
        "retention_until_utc": verified["retention_until_utc"],
        "archive_sha256": sha256_file(artifact) if artifact.is_file() else sha256_file(artifact / MANIFEST_NAME),
        "recovery_receipt_sha256": sha256_file(recovery_receipt),
        "semantics": "Post-release operational custody is closed out. This record does not create CI/runtime/production PASS evidence; those must already exist in the archived closure chain.",
    }
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    return payload


def main() -> int:
    parser = argparse.ArgumentParser(description="Create, verify, rehearse and operationally close out NADI release archives.")
    sub = parser.add_subparsers(dest="command", required=True)

    create = sub.add_parser("create", help="Create a closed-world long-term archive from a verified closure ZIP and operator handoff packet.")
    create.add_argument("--repo-root", default=".")
    create.add_argument("--closure-artifact", required=True)
    create.add_argument("--operator-handoff-dir", required=True)
    create.add_argument("--expected-repository", default=os.environ.get("NADI_EXPECTED_GITHUB_REPOSITORY"))
    create.add_argument("--retention-until", required=True)
    create.add_argument("--output-dir", required=True)
    create.add_argument("--zip-output")

    verify = sub.add_parser("verify", help="Verify a retained release archive directory or ZIP.")
    verify.add_argument("--repo-root", default=".")
    verify.add_argument("--artifact", required=True)
    verify.add_argument("--expected-repository", default=os.environ.get("NADI_EXPECTED_GITHUB_REPOSITORY"))

    rehearse = sub.add_parser("rehearse", help="Perform a recovery rehearsal entirely from the retained archive.")
    rehearse.add_argument("--repo-root", default=".")
    rehearse.add_argument("--artifact", required=True)
    rehearse.add_argument("--expected-repository", default=os.environ.get("NADI_EXPECTED_GITHUB_REPOSITORY"))
    rehearse.add_argument("--receipt-output")

    closeout = sub.add_parser("closeout", help="Emit operational closeout only after archive verification and recovery rehearsal PASS.")
    closeout.add_argument("--repo-root", default=".")
    closeout.add_argument("--artifact", required=True)
    closeout.add_argument("--expected-repository", default=os.environ.get("NADI_EXPECTED_GITHUB_REPOSITORY"))
    closeout.add_argument("--recovery-receipt", required=True)
    closeout.add_argument("--output", required=True)

    args = parser.parse_args()
    if not args.expected_repository:
        print("FAIL: --expected-repository (or NADI_EXPECTED_GITHUB_REPOSITORY) is required", file=sys.stderr)
        return 2
    repo = Path(args.repo_root).resolve()
    try:
        if args.command == "create":
            receipt, zipped = create_archive(
                repo,
                Path(args.closure_artifact).resolve(),
                Path(args.operator_handoff_dir).resolve(),
                args.expected_repository,
                args.retention_until,
                Path(args.output_dir).resolve(),
                Path(args.zip_output).resolve() if args.zip_output else None,
            )
            print(f"PASS release archive creation: {receipt}")
            if zipped:
                print(f"PASS release archive ZIP: {zipped} SHA-256={sha256_file(zipped)}")
        elif args.command == "verify":
            result = verify_archive(repo, Path(args.artifact).resolve(), args.expected_repository)
            print(f"PASS release archive verification: version={result['version']} retention={result['retention_state']}")
        elif args.command == "rehearse":
            result = recovery_rehearsal(repo, Path(args.artifact).resolve(), args.expected_repository, Path(args.receipt_output).resolve() if args.receipt_output else None)
            print(f"PASS release archive recovery rehearsal: version={result['version']} retention={result['retention_state']}")
        else:
            result = operational_closeout(repo, Path(args.artifact).resolve(), args.expected_repository, Path(args.recovery_receipt).resolve(), Path(args.output).resolve())
            print(f"PASS operational release closeout: version={result['version']} status={result['status']}")
        return 0
    except (ValueError, OSError, json.JSONDecodeError, zipfile.BadZipFile) as exc:
        print(f"FAIL: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
