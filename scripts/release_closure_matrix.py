#!/usr/bin/env python3
"""NADI release closure verification matrix and operator handoff evaluator.

This tool does not create runtime PASS evidence. It maps already-existing evidence
onto a fixed decision matrix and fails closed if a closure artifact is supplied but
cannot be independently verified against the current source checkpoint.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import sys
import tempfile
import zipfile
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import release_closure_handoff as rch

MATRIX_SCHEMA = "nadi.release-closure-verification-matrix.v1"
SNAPSHOT_SCHEMA = "nadi.operator-release-handoff.v1"
HANDOFF_MANIFEST_SCHEMA = "nadi.operator-release-handoff-manifest.v1"
HANDOFF_FILES = {"OPERATOR_HANDOFF.json", "OPERATOR_HANDOFF.md", "RELEASE_CLOSURE_VERIFICATION_MATRIX.json"}
VERSION_RE = re.compile(r"^v?\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$")


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def load_json(path: Path) -> dict[str, Any]:
    with path.open("r", encoding="utf-8") as handle:
        data = json.load(handle)
    if not isinstance(data, dict):
        raise ValueError(f"JSON object required: {path}")
    return data


def load_matrix(repo: Path) -> dict[str, Any]:
    path = repo / "config" / "release-closure-verification-matrix.json"
    matrix = load_json(path)
    if matrix.get("schema") != MATRIX_SCHEMA or matrix.get("version") != 1:
        raise ValueError("release closure verification matrix schema/version invalid")
    gates = matrix.get("gates")
    if not isinstance(gates, list) or len(gates) != 8:
        raise ValueError("release closure verification matrix must contain exactly eight gates")
    ids: set[str] = set()
    for index, gate in enumerate(gates, 1):
        if not isinstance(gate, dict):
            raise ValueError(f"matrix gate {index} must be an object")
        gate_id = str(gate.get("id") or "")
        if not re.fullmatch(r"G\d{2}_[A-Z0-9_]+", gate_id) or gate_id in ids:
            raise ValueError(f"invalid/duplicate gate id: {gate_id}")
        ids.add(gate_id)
        if gate.get("required_state") in (None, ""):
            raise ValueError(f"matrix gate missing required_state: {gate_id}")
        evidence = gate.get("required_evidence")
        if not isinstance(evidence, list) or not evidence or any(not isinstance(x, str) or not x for x in evidence):
            raise ValueError(f"matrix gate required_evidence invalid: {gate_id}")
        if gate.get("synthetic_authoritative") is not False:
            raise ValueError(f"synthetic evidence must never be authoritative: {gate_id}")
        if gate.get("blocks_downstream") is not True:
            raise ValueError(f"all release matrix gates must fail closed: {gate_id}")
    expected = [f"G{i:02d}_" for i in range(1, 9)]
    for prefix, gate in zip(expected, gates):
        if not str(gate["id"]).startswith(prefix):
            raise ValueError("release closure matrix gate order is not canonical")
    return matrix


def verify_source_manifest(repo: Path) -> tuple[int, str]:
    manifest = repo / "AUDITED_SOURCE_RC_MANIFEST.sha256"
    if not manifest.is_file() or manifest.is_symlink():
        raise ValueError("AUDITED_SOURCE_RC_MANIFEST.sha256 missing or unsafe")
    entries = 0
    for raw in manifest.read_text(encoding="utf-8").splitlines():
        if not raw.strip():
            continue
        match = re.fullmatch(r"([0-9a-f]{64})  (.+)", raw)
        if not match:
            raise ValueError(f"invalid source manifest line: {raw[:120]}")
        expected, rel = match.groups()
        if rel == "AUDITED_SOURCE_RC_MANIFEST.sha256" or rel.startswith("/") or ".." in Path(rel).parts:
            raise ValueError(f"unsafe source manifest path: {rel}")
        path = repo / rel
        if not path.is_file() or path.is_symlink():
            raise ValueError(f"source manifest entry missing/unsafe: {rel}")
        if sha256_file(path) != expected:
            raise ValueError(f"source manifest hash mismatch: {rel}")
        entries += 1
    if entries == 0:
        raise ValueError("source manifest is empty")
    return entries, sha256_file(manifest)


def read_closure_json(artifact: Path) -> dict[str, Any]:
    if artifact.is_dir():
        return load_json(artifact / rch.CLOSURE_NAME)
    if not artifact.is_file() or not zipfile.is_zipfile(artifact):
        raise ValueError("closure artifact must be a directory or ZIP")
    with zipfile.ZipFile(artifact, "r") as archive:
        names = [n.rstrip("/") for n in archive.namelist() if n.rstrip("/")]
        matches = [n for n in names if n == rch.CLOSURE_NAME]
        if len(matches) != 1:
            raise ValueError("closure ZIP must contain RELEASE_CLOSURE.json at root")
        data = json.loads(archive.read(matches[0]).decode("utf-8"))
    if not isinstance(data, dict):
        raise ValueError("closure metadata must be a JSON object")
    return data


def waiting_gate_rows(matrix: dict[str, Any]) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    for index, gate in enumerate(matrix["gates"]):
        status = "PASS" if index == 0 else "WAITING_EXTERNAL_EVIDENCE"
        rows.append({
            "id": gate["id"],
            "title": gate["title"],
            "phase": gate["phase"],
            "authority": gate["authority"],
            "status": status,
            "required_state": gate["required_state"],
            "required_evidence": gate["required_evidence"],
            "operator_action": gate["operator_action"],
        })
    return rows


def verified_gate_rows(matrix: dict[str, Any]) -> list[dict[str, Any]]:
    return [{
        "id": gate["id"],
        "title": gate["title"],
        "phase": gate["phase"],
        "authority": gate["authority"],
        "status": "PASS",
        "required_state": gate["required_state"],
        "required_evidence": gate["required_evidence"],
        "operator_action": gate["operator_action"],
    } for gate in matrix["gates"]]


def build_snapshot(repo: Path, expected_repository: str | None, artifact: Path | None) -> dict[str, Any]:
    matrix = load_matrix(repo)
    entries, source_sha = verify_source_manifest(repo)
    artifact_sha: str | None = None
    closure: dict[str, Any] | None = None

    if artifact is None:
        rows = waiting_gate_rows(matrix)
        overall = "WAITING_EXTERNAL_EVIDENCE"
        release_authorized = False
        operationally_accepted = False
        closure_verified = False
        version = None
        repository = expected_repository
    else:
        if not expected_repository:
            raise ValueError("expected repository is required when verifying a closure artifact")
        closure = rch.verify_handoff(repo, artifact.resolve(), expected_repository)
        if artifact.is_file():
            artifact_sha = sha256_file(artifact)
        rows = verified_gate_rows(matrix)
        overall = "RELEASE_CLOSURE_VERIFIED"
        release_authorized = True
        operationally_accepted = True
        closure_verified = True
        version = closure.get("version")
        repository = closure.get("expected_repository")
        if not isinstance(version, str) or not VERSION_RE.fullmatch(version):
            raise ValueError("verified closure semantic version invalid")
        if repository != expected_repository:
            raise ValueError("verified closure repository mismatch")

    return {
        "schema": SNAPSHOT_SCHEMA,
        "generated_at_utc": datetime.now(timezone.utc).isoformat(),
        "status": overall,
        "release_authorized": release_authorized,
        "production_operationally_accepted": operationally_accepted,
        "release_closure_verified": closure_verified,
        "expected_repository": repository,
        "release_version": version,
        "source_manifest": {
            "path": "AUDITED_SOURCE_RC_MANIFEST.sha256",
            "entries": entries,
            "sha256": source_sha,
            "verification": "PASS",
        },
        "closure_artifact_sha256": artifact_sha,
        "matrix_schema": matrix["schema"],
        "matrix_sha256": sha256_file(repo / "config" / "release-closure-verification-matrix.json"),
        "gates": rows,
        "operator_rule": "Do not promote WAITING_EXTERNAL_EVIDENCE, mock, synthetic, local-demo, push, or pull-request evidence to FINAL/production acceptance.",
    }


def write_json(path: Path, payload: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    if path.exists() or path.is_symlink():
        raise ValueError(f"refusing to overwrite operator handoff output: {path}")
    path.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def write_handoff_manifest(output: Path, snapshot: dict[str, Any]) -> None:
    files = []
    for name in sorted(HANDOFF_FILES):
        path = output / name
        if not path.is_file() or path.is_symlink():
            raise ValueError(f"operator handoff file missing/unsafe: {name}")
        files.append({"path": name, "bytes": path.stat().st_size, "sha256": sha256_file(path)})
    payload = {
        "schema": HANDOFF_MANIFEST_SCHEMA,
        "status": snapshot["status"],
        "source_manifest_sha256": snapshot["source_manifest"]["sha256"],
        "matrix_sha256": snapshot["matrix_sha256"],
        "files": files,
        "file_count": len(files),
    }
    manifest = output / "OPERATOR_HANDOFF_MANIFEST.json"
    manifest.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    digest = output / "OPERATOR_HANDOFF_MANIFEST.json.sha256"
    digest.write_text(f"{sha256_file(manifest)}  OPERATOR_HANDOFF_MANIFEST.json\n", encoding="utf-8")


def verify_handoff_export(repo: Path, output: Path) -> dict[str, Any]:
    if not output.is_dir() or output.is_symlink():
        raise ValueError("operator handoff export must be a regular directory")
    expected_root = HANDOFF_FILES | {"OPERATOR_HANDOFF_MANIFEST.json", "OPERATOR_HANDOFF_MANIFEST.json.sha256"}
    actual_root = {p.name for p in output.iterdir()}
    if actual_root != expected_root:
        raise ValueError(f"operator handoff export is not closed-world: missing={sorted(expected_root-actual_root)} extra={sorted(actual_root-expected_root)}")
    for path in output.iterdir():
        if not path.is_file() or path.is_symlink():
            raise ValueError(f"operator handoff entry unsafe: {path.name}")
    manifest = load_json(output / "OPERATOR_HANDOFF_MANIFEST.json")
    if manifest.get("schema") != HANDOFF_MANIFEST_SCHEMA:
        raise ValueError("operator handoff manifest schema invalid")
    digest_expected = f"{sha256_file(output / 'OPERATOR_HANDOFF_MANIFEST.json')}  OPERATOR_HANDOFF_MANIFEST.json"
    if (output / "OPERATOR_HANDOFF_MANIFEST.json.sha256").read_text(encoding="utf-8").strip() != digest_expected:
        raise ValueError("operator handoff manifest digest sidecar mismatch")
    entries = manifest.get("files")
    if not isinstance(entries, list) or manifest.get("file_count") != len(HANDOFF_FILES):
        raise ValueError("operator handoff manifest file list invalid")
    listed = set()
    for entry in entries:
        if not isinstance(entry, dict):
            raise ValueError("operator handoff manifest entry invalid")
        name = str(entry.get("path") or "")
        if name not in HANDOFF_FILES or name in listed:
            raise ValueError(f"unexpected/duplicate operator handoff manifest path: {name}")
        path = output / name
        if path.stat().st_size != entry.get("bytes") or sha256_file(path) != entry.get("sha256"):
            raise ValueError(f"operator handoff file hash/size mismatch: {name}")
        listed.add(name)
    if listed != HANDOFF_FILES:
        raise ValueError("operator handoff manifest file set incomplete")
    snapshot = load_json(output / "OPERATOR_HANDOFF.json")
    if snapshot.get("schema") != SNAPSHOT_SCHEMA or snapshot.get("status") != manifest.get("status"):
        raise ValueError("operator handoff snapshot schema/status mismatch")
    entries_count, source_sha = verify_source_manifest(repo)
    if snapshot.get("source_manifest", {}).get("sha256") != source_sha or manifest.get("source_manifest_sha256") != source_sha:
        raise ValueError("operator handoff source checkpoint binding mismatch")
    if snapshot.get("source_manifest", {}).get("entries") != entries_count:
        raise ValueError("operator handoff source entry count mismatch")
    matrix_sha = sha256_file(repo / "config" / "release-closure-verification-matrix.json")
    if snapshot.get("matrix_sha256") != matrix_sha or manifest.get("matrix_sha256") != matrix_sha:
        raise ValueError("operator handoff matrix binding mismatch")
    gates = snapshot.get("gates")
    if not isinstance(gates, list) or len(gates) != 8:
        raise ValueError("operator handoff gate matrix invalid")
    if snapshot.get("release_closure_verified") is True:
        if snapshot.get("status") != "RELEASE_CLOSURE_VERIFIED" or not all(g.get("status") == "PASS" for g in gates if isinstance(g, dict)):
            raise ValueError("verified closure operator handoff has incomplete PASS matrix")
        if snapshot.get("release_authorized") is not True or snapshot.get("production_operationally_accepted") is not True:
            raise ValueError("verified closure operator handoff decision flags invalid")
    else:
        if snapshot.get("status") != "WAITING_EXTERNAL_EVIDENCE" or snapshot.get("release_authorized") is not False:
            raise ValueError("waiting operator handoff decision flags invalid")
        if gates[0].get("status") != "PASS" or any(g.get("status") != "WAITING_EXTERNAL_EVIDENCE" for g in gates[1:]):
            raise ValueError("waiting operator handoff gate statuses invalid")
    return snapshot


def write_markdown(path: Path, payload: dict[str, Any]) -> None:
    if path.exists() or path.is_symlink():
        raise ValueError(f"refusing to overwrite operator handoff output: {path}")
    lines = [
        "# NADI — Operator Release Handoff",
        "",
        f"**Status:** `{payload['status']}`  ",
        f"**Release authorized:** `{str(payload['release_authorized']).lower()}`  ",
        f"**Production operationally accepted:** `{str(payload['production_operationally_accepted']).lower()}`  ",
        f"**Closure independently verified:** `{str(payload['release_closure_verified']).lower()}`  ",
        f"**Expected repository:** `{payload.get('expected_repository') or 'NOT SET'}`  ",
        f"**Release version:** `{payload.get('release_version') or 'NOT AVAILABLE'}`",
        "",
        "| Gate | Phase | Authority | Status | Required state |",
        "|---|---|---|---|---|",
    ]
    for gate in payload["gates"]:
        lines.append(f"| {gate['id']} — {gate['title']} | {gate['phase']} | {gate['authority']} | **{gate['status']}** | {gate['required_state']} |")
    lines.extend([
        "",
        "## Operator rule",
        "",
        payload["operator_rule"],
        "",
        "## Source binding",
        "",
        f"- Source manifest entries: `{payload['source_manifest']['entries']}`",
        f"- Source manifest SHA-256: `{payload['source_manifest']['sha256']}`",
        f"- Matrix SHA-256: `{payload['matrix_sha256']}`",
    ])
    if payload.get("closure_artifact_sha256"):
        lines.append(f"- Closure artifact SHA-256: `{payload['closure_artifact_sha256']}`")
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser(description="Evaluate NADI release closure verification matrix and generate operator handoff status.")
    sub = parser.add_subparsers(dest="command", required=True)

    validate = sub.add_parser("validate", help="Validate matrix structure and current source manifest integrity.")
    validate.add_argument("--repo-root", default=".")

    status = sub.add_parser("status", help="Print current matrix status; optionally independently verify a real closure artifact.")
    status.add_argument("--repo-root", default=".")
    status.add_argument("--artifact")
    status.add_argument("--expected-repository", default=os.environ.get("NADI_EXPECTED_GITHUB_REPOSITORY"))

    export = sub.add_parser("export", help="Write non-overwriting closed-world operator handoff snapshot.")
    export.add_argument("--repo-root", default=".")
    export.add_argument("--artifact")
    export.add_argument("--expected-repository", default=os.environ.get("NADI_EXPECTED_GITHUB_REPOSITORY"))
    export.add_argument("--output-dir", required=True)

    verify_export = sub.add_parser("verify-export", help="Verify a closed-world operator handoff export against this source checkpoint.")
    verify_export.add_argument("--repo-root", default=".")
    verify_export.add_argument("--input-dir", required=True)

    args = parser.parse_args()
    repo = Path(args.repo_root).resolve()
    try:
        if args.command == "validate":
            matrix = load_matrix(repo)
            entries, source_sha = verify_source_manifest(repo)
            print(f"PASS release closure matrix validation: gates={len(matrix['gates'])} source_entries={entries} source_sha256={source_sha}")
            return 0

        if args.command == "verify-export":
            snapshot = verify_handoff_export(repo, Path(args.input_dir).resolve())
            print(f"PASS operator handoff verification: status={snapshot.get('status')}")
            return 0

        artifact = Path(args.artifact).resolve() if args.artifact else None
        snapshot = build_snapshot(repo, args.expected_repository, artifact)
        if args.command == "status":
            print(json.dumps(snapshot, indent=2, sort_keys=True))
            return 0

        output = Path(args.output_dir).resolve()
        if output.exists() or output.is_symlink():
            raise ValueError(f"operator handoff output already exists: {output}")
        output.mkdir(parents=True, exist_ok=False)
        write_json(output / "OPERATOR_HANDOFF.json", snapshot)
        write_markdown(output / "OPERATOR_HANDOFF.md", snapshot)
        matrix_src = repo / "config" / "release-closure-verification-matrix.json"
        (output / "RELEASE_CLOSURE_VERIFICATION_MATRIX.json").write_bytes(matrix_src.read_bytes())
        write_handoff_manifest(output, snapshot)
        verify_handoff_export(repo, output)
        print(f"PASS operator handoff export: {output}")
        return 0
    except (ValueError, OSError, json.JSONDecodeError, zipfile.BadZipFile) as exc:
        print(f"FAIL: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
