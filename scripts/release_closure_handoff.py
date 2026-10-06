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

import production_operational_acceptance as poa
import production_deployment_intake as pdi

SCHEMA = "nadi.release-closure-handoff.v1"
MANIFEST_SCHEMA = "nadi.release-closure-evidence-bundle.v1"
CLOSURE_NAME = "RELEASE_CLOSURE.json"
MANIFEST_NAME = "RELEASE_CLOSURE_MANIFEST.json"
MANIFEST_DIGEST_NAME = f"{MANIFEST_NAME}.sha256"
ACCEPTANCE_NAME = "PRODUCTION_OPERATIONAL_ACCEPTANCE.json"
VERSION_RE = re.compile(r"^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$")
MAX_ARCHIVE_FILES = 50_000
MAX_ARCHIVE_UNCOMPRESSED_BYTES = 4 * 1024 * 1024 * 1024
MAX_MEMBER_UNCOMPRESSED_BYTES = 1024 * 1024 * 1024
SENSITIVE_ASSIGNMENT_RE = re.compile(
    r"(?im)^\s*(APP_KEY|DB_PASSWORD|NADI_ACCEPTANCE_PASSWORD|NADI_ACCEPTANCE_VIEWER_PASSWORD|NADI_ACCEPTANCE_FORCE_PASSWORD|MAIL_PASSWORD|AWS_SECRET_ACCESS_KEY)\s*=\s*(?!\[?REDACTED\]?\s*$|<redacted>\s*$|$).+\s*$"
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
        raise ValueError(f"JSON file missing/not regular: {path}")
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise ValueError(f"expected JSON object: {path}")
    return data


def require_regular(path: Path, label: str) -> None:
    if not path.is_file() or path.is_symlink():
        raise ValueError(f"{label} missing/not regular file: {path}")


def reject_symlinks(root: Path) -> None:
    if root.is_symlink():
        raise ValueError(f"symlink root refused: {root}")
    for path in root.rglob("*"):
        if path.is_symlink():
            raise ValueError(f"symlink refused: {path.relative_to(root)}")


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


def safe_extract_zip(source: Path, destination: Path) -> str:
    require_regular(source, "closure ZIP")
    archive_sha = sha256_file(source)
    with zipfile.ZipFile(source, "r") as archive:
        infos = archive.infolist()
        if len(infos) > MAX_ARCHIVE_FILES:
            raise ValueError(f"closure ZIP has too many entries: {len(infos)}")
        seen: set[str] = set()
        total = 0
        normalized: list[tuple[zipfile.ZipInfo, str]] = []
        for info in infos:
            raw = info.filename.rstrip("/")
            if not raw:
                continue
            name = safe_member_name(raw)
            if name in seen:
                raise ValueError(f"duplicate closure ZIP member: {name}")
            seen.add(name)
            mode = (info.external_attr >> 16) & 0o170000
            if mode == stat.S_IFLNK:
                raise ValueError(f"closure ZIP symlink refused: {name}")
            if info.file_size > MAX_MEMBER_UNCOMPRESSED_BYTES:
                raise ValueError(f"closure ZIP member too large: {name}")
            total += info.file_size
            if total > MAX_ARCHIVE_UNCOMPRESSED_BYTES:
                raise ValueError("closure ZIP uncompressed size exceeds safety limit")
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


def verify_operational_acceptance(
    repo: Path,
    deployment_envelope: Path,
    acceptance_dir: Path,
    expected_repository: str,
) -> dict[str, Any]:
    intake_info = poa.verify_deployment_envelope(repo, deployment_envelope, expected_repository)
    if not acceptance_dir.is_dir() or acceptance_dir.is_symlink():
        raise ValueError("operational acceptance directory missing/not regular")
    reject_symlinks(acceptance_dir)
    if {p.name for p in acceptance_dir.iterdir()} != {"DEPLOYMENT_INTAKE.json", "post-deploy", ACCEPTANCE_NAME}:
        raise ValueError("operational acceptance directory is not closed-world")
    accepted_intake = acceptance_dir / "DEPLOYMENT_INTAKE.json"
    require_regular(accepted_intake, "accepted deployment intake")
    if sha256_file(accepted_intake) != sha256_file(intake_info["intake_path"]):
        raise ValueError("operational acceptance deployment intake differs from deployment envelope")

    verified_post = poa.verify_post_deploy_bundle(acceptance_dir / "post-deploy", intake_info, expected_repository)
    decision_path = acceptance_dir / ACCEPTANCE_NAME
    decision = load_json(decision_path)
    if decision.get("schema") != poa.DECISION_SCHEMA or decision.get("decision") != "ACCEPTED":
        raise ValueError("production operational acceptance decision is not ACCEPTED")
    intake = intake_info["intake"]
    source_sha = sha256_file(repo / "AUDITED_SOURCE_RC_MANIFEST.sha256")
    if decision.get("source_manifest_sha256") != source_sha:
        raise ValueError("operational acceptance source checkpoint mismatch")
    if decision.get("expected_repository") != expected_repository:
        raise ValueError("operational acceptance repository mismatch")
    if decision.get("version") != intake.get("version"):
        raise ValueError("operational acceptance version mismatch")
    if decision.get("ci") != intake.get("ci"):
        raise ValueError("operational acceptance CI provenance mismatch")
    if decision.get("deployment_intake_sha256") != sha256_file(accepted_intake):
        raise ValueError("operational acceptance intake SHA mismatch")
    if decision.get("post_deploy_evidence_manifest_sha256") != sha256_file(acceptance_dir / "post-deploy" / poa.MANIFEST_NAME):
        raise ValueError("operational acceptance post-deploy manifest SHA mismatch")
    if decision.get("post_deploy_verification_sha256") != sha256_file(acceptance_dir / "post-deploy" / poa.POST_NAME):
        raise ValueError("operational acceptance post-deploy verification SHA mismatch")
    if decision.get("release_manifest_sha256") != intake.get("release_manifest_sha256"):
        raise ValueError("operational acceptance release manifest SHA mismatch")
    if decision.get("release_package") != intake.get("release_package"):
        raise ValueError("operational acceptance release package metadata mismatch")
    policy = decision.get("acceptance_policy")
    if not isinstance(policy, dict) or any(value is not True for value in policy.values()):
        raise ValueError("operational acceptance policy matrix is incomplete/non-pass")
    return {
        "intake_info": intake_info,
        "post": verified_post,
        "decision": decision,
        "decision_path": decision_path,
    }


def scan_secret_leaks(root: Path) -> None:
    forbidden_names = {".env", ".env.production", ".env.local", "id_rsa", "id_ed25519"}
    for path in root.rglob("*"):
        if not path.is_file() or path.name in {MANIFEST_NAME, MANIFEST_DIGEST_NAME}:
            continue
        if path.name in forbidden_names or path.name.startswith(".env."):
            raise ValueError(f"closure export contains forbidden secret/runtime file: {path.relative_to(root)}")
        if path.stat().st_size > 5 * 1024 * 1024:
            continue
        try:
            text = path.read_text(encoding="utf-8")
        except (UnicodeDecodeError, OSError):
            continue
        if any(marker in text for marker in PRIVATE_KEY_MARKERS):
            raise ValueError(f"closure export contains private-key material: {path.relative_to(root)}")
        match = SENSITIVE_ASSIGNMENT_RE.search(text)
        if match:
            raise ValueError(f"closure export contains credential assignment {match.group(1)} in {path.relative_to(root)}")


def manifest_entries(root: Path) -> list[dict[str, Any]]:
    entries: list[dict[str, Any]] = []
    for path in sorted(root.rglob("*"), key=lambda p: p.relative_to(root).as_posix()):
        if not path.is_file():
            continue
        rel = path.relative_to(root).as_posix()
        if rel in {MANIFEST_NAME, MANIFEST_DIGEST_NAME}:
            continue
        entries.append({"path": rel, "sha256": sha256_file(path), "bytes": path.stat().st_size})
    return entries


def write_manifest(root: Path, closure: dict[str, Any]) -> Path:
    entries = manifest_entries(root)
    manifest = {
        "schema": MANIFEST_SCHEMA,
        "status": "HANDOFF_READY",
        "version": closure["version"],
        "expected_repository": closure["expected_repository"],
        "source_manifest_sha256": closure["source_manifest_sha256"],
        "file_count": len(entries),
        "files": entries,
    }
    manifest_path = root / MANIFEST_NAME
    manifest_path.write_text(json.dumps(manifest, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    digest = sha256_file(manifest_path)
    (root / MANIFEST_DIGEST_NAME).write_text(f"{digest}  {MANIFEST_NAME}\n", encoding="utf-8")
    return manifest_path


def zip_epoch() -> int:
    raw = os.environ.get("SOURCE_DATE_EPOCH", "946684800").strip()
    try:
        epoch = int(raw)
    except ValueError as exc:
        raise ValueError("SOURCE_DATE_EPOCH must be an integer") from exc
    if epoch < 315532800:
        raise ValueError("SOURCE_DATE_EPOCH must be >= 315532800")
    return epoch


def make_deterministic_zip(root: Path, output: Path) -> None:
    if output.exists() or output.is_symlink():
        raise ValueError(f"closure ZIP output already exists: {output}")
    output.parent.mkdir(parents=True, exist_ok=True)
    epoch = zip_epoch()
    dt = datetime.fromtimestamp(epoch, tz=timezone.utc)
    with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
        for path in sorted(root.rglob("*"), key=lambda p: p.relative_to(root).as_posix()):
            if not path.is_file():
                continue
            rel = path.relative_to(root).as_posix()
            info = zipfile.ZipInfo(rel, (dt.year, dt.month, dt.day, dt.hour, dt.minute, dt.second))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.create_system = 3
            mode = 0o755 if (path.stat().st_mode & 0o111) else 0o644
            info.external_attr = (stat.S_IFREG | mode) << 16
            zf.writestr(info, path.read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)


def export_handoff(
    repo: Path,
    deployment_envelope: Path,
    acceptance_dir: Path,
    expected_repository: str,
    output_dir: Path,
    zip_output: Path | None,
) -> tuple[Path, Path | None]:
    require_regular(repo / "AUDITED_SOURCE_RC_MANIFEST.sha256", "audited source manifest")
    verified = verify_operational_acceptance(repo, deployment_envelope, acceptance_dir, expected_repository)
    intake = verified["intake_info"]["intake"]
    decision = verified["decision"]
    version = str(intake.get("version") or "")
    if not VERSION_RE.fullmatch(version):
        raise ValueError(f"invalid semantic release version: {version}")
    if output_dir.exists() or output_dir.is_symlink():
        raise ValueError(f"closure output already exists: {output_dir}")

    output_dir.parent.mkdir(parents=True, exist_ok=True)
    stage = Path(tempfile.mkdtemp(prefix=f".{output_dir.name}.staging-", dir=output_dir.parent))
    keep = False
    try:
        shutil.copytree(deployment_envelope, stage / "deployment", dirs_exist_ok=False)
        shutil.copytree(acceptance_dir, stage / "acceptance", dirs_exist_ok=False)
        closure = {
            "schema": SCHEMA,
            "status": "HANDOFF_READY",
            "version": version,
            "expected_repository": expected_repository,
            "source_manifest_sha256": sha256_file(repo / "AUDITED_SOURCE_RC_MANIFEST.sha256"),
            "ci": intake["ci"],
            "release_package": intake["release_package"],
            "release_manifest_sha256": intake["release_manifest_sha256"],
            "deployment_intake_sha256": sha256_file(deployment_envelope / "DEPLOYMENT_INTAKE.json"),
            "production_operational_acceptance_sha256": sha256_file(acceptance_dir / ACCEPTANCE_NAME),
            "post_deploy_evidence_manifest_sha256": sha256_file(acceptance_dir / "post-deploy" / poa.MANIFEST_NAME),
            "post_deploy_verification_sha256": sha256_file(acceptance_dir / "post-deploy" / poa.POST_NAME),
            "accepted_at_utc": decision.get("accepted_at_utc"),
            "base_url": decision.get("base_url"),
            "closure_policy": {
                "authorized_external_ci_final_required": True,
                "authorized_deployment_intake_required": True,
                "production_operational_acceptance_required": True,
                "closed_world_post_deploy_evidence_required": True,
                "source_checkpoint_binding_required": True,
                "expected_repository_binding_required": True,
                "secret_leak_guard_required": True,
                "self_contained_handoff_required": True,
                "no_synthetic_promotion": True,
            },
        }
        (stage / CLOSURE_NAME).write_text(json.dumps(closure, indent=2, sort_keys=True) + "\n", encoding="utf-8")
        scan_secret_leaks(stage)
        write_manifest(stage, closure)
        verify_handoff(repo, stage, expected_repository)
        os.replace(stage, output_dir)
        keep = True
    finally:
        if not keep:
            shutil.rmtree(stage, ignore_errors=True)

    if zip_output is not None:
        make_deterministic_zip(output_dir, zip_output)
        verify_handoff(repo, zip_output, expected_repository)
    return output_dir / CLOSURE_NAME, zip_output


def verify_manifest(root: Path) -> dict[str, Any]:
    manifest_path = root / MANIFEST_NAME
    digest_path = root / MANIFEST_DIGEST_NAME
    manifest = load_json(manifest_path)
    require_regular(digest_path, "closure manifest digest")
    expected_digest = f"{sha256_file(manifest_path)}  {MANIFEST_NAME}"
    if digest_path.read_text(encoding="utf-8").strip() != expected_digest:
        raise ValueError("closure manifest digest sidecar mismatch")
    if manifest.get("schema") != MANIFEST_SCHEMA or manifest.get("status") != "HANDOFF_READY":
        raise ValueError("closure evidence manifest schema/status invalid")
    files = manifest.get("files")
    if not isinstance(files, list):
        raise ValueError("closure evidence manifest files must be an array")
    expected: dict[str, dict[str, Any]] = {}
    for entry in files:
        if not isinstance(entry, dict):
            raise ValueError("invalid closure manifest entry")
        rel = str(entry.get("path") or "")
        digest = str(entry.get("sha256") or "")
        size = entry.get("bytes")
        safe_member_name(rel)
        if rel in expected or not re.fullmatch(r"[0-9a-f]{64}", digest) or not isinstance(size, int):
            raise ValueError(f"invalid closure manifest entry: {entry}")
        expected[rel] = entry
    actual = {
        p.relative_to(root).as_posix()
        for p in root.rglob("*")
        if p.is_file() and p.relative_to(root).as_posix() not in {MANIFEST_NAME, MANIFEST_DIGEST_NAME}
    }
    if actual != set(expected):
        raise ValueError(f"closure bundle closed-world mismatch: missing={sorted(set(expected)-actual)[:5]} extra={sorted(actual-set(expected))[:5]}")
    if manifest.get("file_count") != len(expected):
        raise ValueError("closure manifest file_count mismatch")
    for rel, entry in expected.items():
        path = root / rel
        require_regular(path, f"closure file {rel}")
        if path.stat().st_size != entry["bytes"] or sha256_file(path) != entry["sha256"]:
            raise ValueError(f"closure file hash/size mismatch: {rel}")
    return manifest


def verify_handoff(repo: Path, artifact: Path, expected_repository: str) -> dict[str, Any]:
    temp: tempfile.TemporaryDirectory[str] | None = None
    try:
        if artifact.is_symlink():
            raise ValueError("closure artifact symlink refused")
        if artifact.is_dir():
            root = artifact.resolve()
            reject_symlinks(root)
        elif artifact.is_file():
            if not zipfile.is_zipfile(artifact):
                raise ValueError("closure artifact file must be a ZIP")
            temp = tempfile.TemporaryDirectory(prefix="nadi-release-closure-")
            root = Path(temp.name)
            safe_extract_zip(artifact.resolve(), root)
        else:
            raise ValueError("closure artifact does not exist")

        if {p.name for p in root.iterdir()} != {"deployment", "acceptance", CLOSURE_NAME, MANIFEST_NAME, MANIFEST_DIGEST_NAME}:
            raise ValueError("closure bundle root is not closed-world")
        scan_secret_leaks(root)
        manifest = verify_manifest(root)
        closure = load_json(root / CLOSURE_NAME)
        if closure.get("schema") != SCHEMA or closure.get("status") != "HANDOFF_READY":
            raise ValueError("release closure schema/status invalid")
        source_sha = sha256_file(repo / "AUDITED_SOURCE_RC_MANIFEST.sha256")
        if closure.get("source_manifest_sha256") != source_sha or manifest.get("source_manifest_sha256") != source_sha:
            raise ValueError("release closure source checkpoint mismatch")
        if closure.get("expected_repository") != expected_repository or manifest.get("expected_repository") != expected_repository:
            raise ValueError("release closure expected repository mismatch")
        if closure.get("version") != manifest.get("version"):
            raise ValueError("release closure version/manifest mismatch")
        policy = closure.get("closure_policy")
        if not isinstance(policy, dict) or any(value is not True for value in policy.values()):
            raise ValueError("release closure policy matrix incomplete/non-pass")

        verified = verify_operational_acceptance(repo, root / "deployment", root / "acceptance", expected_repository)
        intake = verified["intake_info"]["intake"]
        decision = verified["decision"]

        # Independently re-extract the custodied FINAL ZIP and prove that it is
        # byte/content-equivalent to the already verified deployment release tree.
        package_meta = intake.get("release_package")
        if not isinstance(package_meta, dict):
            raise ValueError("release closure deployment intake package metadata missing")
        package_path = root / "deployment" / str(package_meta.get("path") or "")
        require_regular(package_path, "release closure FINAL ZIP")
        if package_path.stat().st_size != package_meta.get("bytes") or sha256_file(package_path) != package_meta.get("sha256"):
            raise ValueError("release closure FINAL ZIP differs from deployment intake metadata")
        with tempfile.TemporaryDirectory(prefix="nadi-closure-release-") as td:
            extracted = Path(td)
            pdi.safe_extract_release(package_path, extracted)
            extracted_manifest = pdi.verify_release_tree(extracted)
            deployed_manifest = verified["intake_info"]["release_manifest"]
            if extracted_manifest != deployed_manifest:
                raise ValueError("custodied FINAL ZIP release manifest differs from deployment release tree")
            deployed_files = {
                path.relative_to(root / "deployment" / "release").as_posix(): sha256_file(path)
                for path in (root / "deployment" / "release").rglob("*")
                if path.is_file()
            }
            extracted_files = {
                path.relative_to(extracted).as_posix(): sha256_file(path)
                for path in extracted.rglob("*")
                if path.is_file()
            }
            if extracted_files != deployed_files:
                raise ValueError("custodied FINAL ZIP contents differ from deployment release tree")
        if closure.get("version") != intake.get("version") or closure.get("ci") != intake.get("ci"):
            raise ValueError("release closure provenance differs from deployment intake")
        if closure.get("release_package") != intake.get("release_package"):
            raise ValueError("release closure package metadata mismatch")
        if closure.get("release_manifest_sha256") != intake.get("release_manifest_sha256"):
            raise ValueError("release closure release manifest mismatch")
        if closure.get("deployment_intake_sha256") != sha256_file(root / "deployment" / "DEPLOYMENT_INTAKE.json"):
            raise ValueError("release closure deployment intake hash mismatch")
        if closure.get("production_operational_acceptance_sha256") != sha256_file(root / "acceptance" / ACCEPTANCE_NAME):
            raise ValueError("release closure acceptance hash mismatch")
        if closure.get("post_deploy_evidence_manifest_sha256") != sha256_file(root / "acceptance" / "post-deploy" / poa.MANIFEST_NAME):
            raise ValueError("release closure post-deploy manifest hash mismatch")
        if closure.get("post_deploy_verification_sha256") != sha256_file(root / "acceptance" / "post-deploy" / poa.POST_NAME):
            raise ValueError("release closure post-deploy verification hash mismatch")
        if closure.get("accepted_at_utc") != decision.get("accepted_at_utc") or closure.get("base_url") != decision.get("base_url"):
            raise ValueError("release closure acceptance metadata mismatch")
        return closure
    finally:
        if temp is not None:
            temp.cleanup()


def main() -> int:
    parser = argparse.ArgumentParser(description="Export or verify the self-contained NADI production release-closure handoff evidence.")
    sub = parser.add_subparsers(dest="command", required=True)

    export = sub.add_parser("export", help="Create a self-contained closure handoff only from accepted production evidence.")
    export.add_argument("--repo-root", default=".")
    export.add_argument("--deployment-envelope", required=True)
    export.add_argument("--operational-acceptance-dir", required=True)
    export.add_argument("--expected-repository", default=os.environ.get("NADI_EXPECTED_GITHUB_REPOSITORY"))
    export.add_argument("--output-dir", required=True)
    export.add_argument("--zip-output")

    verify = sub.add_parser("verify", help="Re-verify a closure handoff directory or ZIP against this source checkpoint.")
    verify.add_argument("--repo-root", default=".")
    verify.add_argument("--artifact", required=True)
    verify.add_argument("--expected-repository", default=os.environ.get("NADI_EXPECTED_GITHUB_REPOSITORY"))

    args = parser.parse_args()
    expected_repository = args.expected_repository
    if not expected_repository:
        print("FAIL: --expected-repository (or NADI_EXPECTED_GITHUB_REPOSITORY) is required", file=sys.stderr)
        return 2
    repo = Path(args.repo_root).resolve()
    try:
        if args.command == "export":
            receipt, zipped = export_handoff(
                repo,
                Path(args.deployment_envelope).resolve(),
                Path(args.operational_acceptance_dir).resolve(),
                expected_repository,
                Path(args.output_dir).resolve(),
                Path(args.zip_output).resolve() if args.zip_output else None,
            )
            print(f"PASS release closure handoff export: {receipt}")
            if zipped:
                print(f"PASS release closure ZIP: {zipped} SHA-256={sha256_file(zipped)}")
        else:
            closure = verify_handoff(repo, Path(args.artifact).resolve(), expected_repository)
            print(f"PASS release closure handoff verification: version={closure.get('version')} repository={expected_repository}")
    except (ValueError, OSError, json.JSONDecodeError, zipfile.BadZipFile) as exc:
        print(f"FAIL: {exc}", file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
