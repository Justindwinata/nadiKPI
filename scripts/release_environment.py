#!/usr/bin/env python3
"""Validate and record the NADI release-host execution contract.

This script intentionally uses only the Python standard library so it can produce
truthful readiness evidence before project dependencies are installed.
"""

from __future__ import annotations

import argparse
import base64
import importlib.metadata
import json
import os
import re
import shutil
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parent.parent
CONTRACT_PATH = ROOT / "config" / "release-environment.json"
PACKAGE_PATH = ROOT / "package.json"
COMPOSER_PATH = ROOT / "composer.json"
PLAYWRIGHT_REQUIREMENTS = ROOT / "requirements-e2e.txt"


def run(cmd: list[str], timeout: int = 15) -> tuple[int, str]:
    try:
        proc = subprocess.run(
            cmd,
            cwd=ROOT,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
            timeout=timeout,
            check=False,
        )
        return proc.returncode, proc.stdout.strip()
    except (OSError, subprocess.TimeoutExpired) as exc:
        return 127, str(exc)


def parse_version(text: str) -> tuple[int, ...]:
    match = re.search(r"(\d+)(?:\.(\d+))?(?:\.(\d+))?", text)
    if not match:
        return ()
    return tuple(int(part or 0) for part in match.groups())


def version_at_least(actual: str, minimum: str) -> bool:
    a = parse_version(actual)
    m = parse_version(minimum)
    if not a or not m:
        return False
    length = max(len(a), len(m))
    return a + (0,) * (length - len(a)) >= m + (0,) * (length - len(m))


def node_engine_ok(version: str, engine: str) -> bool:
    """Evaluate the specific OR/range forms used by this repository."""
    actual = parse_version(version)
    if len(actual) < 3:
        return False
    for branch in (item.strip() for item in engine.split("||")):
        if branch.startswith(">="):
            if version_at_least(version, branch[2:].strip()):
                return True
            continue
        if branch.startswith("^"):
            minimum = parse_version(branch[1:].strip())
            if len(minimum) < 3:
                continue
            if actual[0] == minimum[0] and actual >= minimum:
                return True
            continue
    return False


def load_json(path: Path) -> dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


def playwright_requirement() -> str:
    for raw in PLAYWRIGHT_REQUIREMENTS.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if line and not line.startswith("#") and line.lower().startswith("playwright"):
            return line
    return ""


def requirement_exact_version(requirement: str) -> str | None:
    match = re.fullmatch(r"playwright==([0-9][0-9A-Za-z._-]*)", requirement)
    return match.group(1) if match else None


def masked_env_presence(names: list[str]) -> dict[str, bool]:
    return {name: bool(os.getenv(name, "")) for name in names}


def valid_app_key(value: str) -> bool:
    if not value.startswith("base64:"):
        return False
    try:
        decoded = base64.b64decode(value.split(":", 1)[1], validate=True)
    except Exception:
        return False
    return len(decoded) == 32


def db_name_is_verification_scoped(name: str) -> bool:
    return bool(re.search(r"(?:^|[_-])(test|testing|ci|verify|verification)(?:$|[_-])", name.lower()))


def php_extensions(required: list[str]) -> tuple[dict[str, bool], str]:
    if shutil.which("php") is None:
        return {name: False for name in required}, ""
    code = "echo json_encode(get_loaded_extensions());"
    rc, out = run(["php", "-r", code])
    if rc != 0:
        return {name: False for name in required}, out
    try:
        loaded = {str(item).lower() for item in json.loads(out)}
    except Exception:
        return {name: False for name in required}, out
    return {name: name.lower() in loaded for name in required}, ""


def mysql_probe(required_major: int) -> dict[str, Any]:
    result: dict[str, Any] = {
        "attempted": False,
        "reachable": False,
        "major_ok": False,
        "server_version": None,
        "error": None,
    }
    if shutil.which("php") is None:
        result["error"] = "php unavailable"
        return result
    if not all(os.getenv(name, "") for name in ("DB_HOST", "DB_PORT", "DB_DATABASE", "DB_USERNAME")):
        result["error"] = "DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME not fully set"
        return result
    result["attempted"] = True
    php = r'''
$host=getenv('DB_HOST') ?: '127.0.0.1';
$port=getenv('DB_PORT') ?: '3306';
$db=getenv('DB_DATABASE') ?: '';
$user=getenv('DB_USERNAME') ?: '';
$pass=getenv('DB_PASSWORD') ?: '';
try {
  $pdo=new PDO("mysql:host={$host};port={$port};dbname={$db}",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $version=(string)$pdo->query('select version()')->fetchColumn();
  echo json_encode(['ok'=>true,'version'=>$version]);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'error'=>get_class($e).': '.$e->getMessage()]);
  exit(3);
}
'''
    rc, out = run(["php", "-r", php], timeout=10)
    try:
        payload = json.loads(out)
    except Exception:
        payload = {"ok": False, "error": out or f"php exit {rc}"}
    if rc == 0 and payload.get("ok"):
        version = str(payload.get("version", ""))
        result["reachable"] = True
        result["server_version"] = version
        parsed = parse_version(version)
        result["major_ok"] = bool(parsed and parsed[0] == required_major)
    else:
        result["error"] = str(payload.get("error", out or f"php exit {rc}"))
    return result


def browser_probe() -> dict[str, Any]:
    configured = os.getenv("NADI_BROWSER_EXECUTABLE_PATH", "").strip()
    result: dict[str, Any] = {
        "playwright_importable": False,
        "playwright_version": None,
        "expected_playwright_requirement": playwright_requirement(),
        "version_matches_requirement": False,
        "executable_source": "configured" if configured else "playwright-managed",
        "executable_path": configured or None,
        "executable_ready": False,
        "error": None,
    }
    try:
        version = importlib.metadata.version("playwright")
        result["playwright_importable"] = True
        result["playwright_version"] = version
        exact = requirement_exact_version(result["expected_playwright_requirement"])
        result["version_matches_requirement"] = exact is not None and version == exact
    except Exception as exc:
        result["error"] = f"Playwright package unavailable: {exc}"
        return result

    if configured:
        path = Path(configured)
        result["executable_ready"] = path.is_file() and os.access(path, os.X_OK)
        if not result["executable_ready"]:
            result["error"] = "configured browser executable missing or not executable"
        return result

    code = (
        "from pathlib import Path; "
        "from playwright.sync_api import sync_playwright; "
        "p=sync_playwright().start(); "
        "path=Path(p.chromium.executable_path); "
        "print(path); p.stop(); "
        "raise SystemExit(0 if path.is_file() else 2)"
    )
    rc, out = run([sys.executable, "-c", code])
    if rc == 0 and out:
        result["executable_path"] = out.splitlines()[-1].strip()
        result["executable_ready"] = True
    else:
        result["error"] = out or "Playwright-managed Chromium unavailable"
    return result


def contract_alignment(contract: dict[str, Any]) -> dict[str, Any]:
    package = load_json(PACKAGE_PATH)
    composer = load_json(COMPOSER_PATH)
    node_engine = str(package.get("engines", {}).get("node", ""))
    npm_engine = str(package.get("engines", {}).get("npm", ""))
    php_requirement = str(composer.get("require", {}).get("php", ""))
    expected_php = "^" + contract["php"]["minimum"]
    expected_npm = f">={contract['npm']['minimum_major']}"
    return {
        "node_engine_present": bool(node_engine),
        "node_engine": node_engine,
        "npm_engine_matches": npm_engine == expected_npm,
        "npm_engine": npm_engine,
        "php_requirement_matches": php_requirement == expected_php,
        "php_requirement": php_requirement,
        "playwright_requirement": playwright_requirement(),
        "playwright_requirement_pinned": requirement_exact_version(playwright_requirement()) is not None,
    }


def snapshot() -> tuple[dict[str, Any], bool]:
    contract = load_json(CONTRACT_PATH)
    commands: dict[str, dict[str, Any]] = {}
    for name in contract["required_commands"]:
        path = shutil.which(name)
        commands[name] = {"available": path is not None, "path": path}

    versions: dict[str, Any] = {}
    version_cmds = {
        "php": ["php", "-r", "echo PHP_VERSION;"],
        "composer": ["composer", "--version", "--no-ansi"],
        "node": ["node", "--version"],
        "npm": ["npm", "--version"],
        "python": [sys.executable, "--version"],
    }
    for key, cmd in version_cmds.items():
        if shutil.which(cmd[0]) or cmd[0] == sys.executable:
            rc, out = run(cmd)
            versions[key] = out if rc == 0 else None
        else:
            versions[key] = None

    extensions, extension_error = php_extensions(contract["php"]["required_extensions"])
    env_presence = masked_env_presence(contract["required_environment"])
    db_database = os.getenv("DB_DATABASE", "")
    destructive = os.getenv("NADI_VERIFY_DESTRUCTIVE_DATABASE", "")
    alignment = contract_alignment(contract)
    browser = browser_probe()

    composer_major_ok = False
    if versions["composer"]:
        parsed = parse_version(str(versions["composer"]))
        composer_major_ok = bool(parsed and parsed[0] == int(contract["composer"]["required_major"]))

    npm_major_ok = False
    if versions["npm"]:
        parsed = parse_version(str(versions["npm"]))
        npm_major_ok = bool(parsed and parsed[0] >= int(contract["npm"]["minimum_major"]))

    node_ok = bool(versions["node"] and node_engine_ok(str(versions["node"]), alignment["node_engine"]))
    php_ok = bool(versions["php"] and version_at_least(str(versions["php"]), contract["php"]["minimum"]))
    python_ok = version_at_least(".".join(map(str, sys.version_info[:3])), contract["python"]["minimum"])
    app_key_ok = valid_app_key(os.getenv("APP_KEY", ""))
    db_ack_ok = bool(db_database and destructive and db_database == destructive)
    db_scope_ok = bool(db_database and db_name_is_verification_scoped(db_database))
    db_connection_ok = os.getenv("DB_CONNECTION", "mysql").strip().lower() == "mysql"
    mysql = mysql_probe(int(contract["mysql"]["required_major"]))

    checks = {
        "commands": all(item["available"] for item in commands.values()),
        "contract_alignment": all(
            bool(alignment[name])
            for name in (
                "node_engine_present",
                "npm_engine_matches",
                "php_requirement_matches",
                "playwright_requirement_pinned",
            )
        ),
        "php_version": php_ok,
        "composer_major": composer_major_ok,
        "node_engine": node_ok,
        "npm_version": npm_major_ok,
        "python_version": python_ok,
        "php_extensions": all(extensions.values()),
        "required_environment": all(env_presence.values()),
        "app_key": app_key_ok,
        "db_connection_mysql": db_connection_ok,
        "destructive_db_ack": db_ack_ok,
        "verification_db_scope": db_scope_ok,
        "playwright_package": bool(browser["playwright_importable"] and browser["version_matches_requirement"]),
        "browser_executable": bool(browser["executable_ready"]),
        "mysql_connectivity": bool(mysql["reachable"]),
        "mysql_major": bool(mysql["major_ok"]),
    }
    ok = all(checks.values())
    data = {
        "schema": "nadi.release-environment-evidence.v1",
        "status": "pass" if ok else "fail",
        "generated_at_utc": datetime.now(timezone.utc).isoformat(),
        "contract_file": str(CONTRACT_PATH.relative_to(ROOT)),
        "contract_schema": contract.get("schema"),
        "contract_alignment": alignment,
        "checks": checks,
        "commands": commands,
        "versions": versions,
        "php_extensions": extensions,
        "php_extension_probe_error": extension_error or None,
        "environment_presence": env_presence,
        "app_key_valid_32_byte_base64": app_key_ok,
        "database": {
            "connection_is_mysql": db_connection_ok,
            "verification_name_scoped": db_scope_ok,
            "destructive_ack_matches": db_ack_ok,
            "server": mysql,
        },
        "browser": browser,
        "platform": {
            "os_name": os.name,
            "sys_platform": sys.platform,
            "machine": os.uname().machine if hasattr(os, "uname") else None,
        },
    }
    return data, ok


def write_output(data: dict[str, Any], output: str | None) -> None:
    payload = json.dumps(data, indent=2, sort_keys=True) + "\n"
    if output:
        path = Path(output)
        if not path.is_absolute():
            path = ROOT / path
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(payload, encoding="utf-8")
    else:
        sys.stdout.write(payload)


def print_human(data: dict[str, Any]) -> None:
    if "commands" in data:
        for name, meta in data["commands"].items():
            print(("PASS" if meta["available"] else "FAIL") + f" command {name}")
        for name, loaded in data.get("php_extensions", {}).items():
            print(("PASS" if loaded else "FAIL") + f" PHP extension {name}")
        for name, present in data.get("environment_presence", {}).items():
            print(("PASS" if present else "FAIL") + f" environment {name} {'is set' if present else 'is required'}")
    for name, passed in data["checks"].items():
        if name in {"commands", "php_extensions", "required_environment"}:
            continue
        print(("PASS" if passed else "FAIL") + f" {name}")
    print(f"NADI release environment: {data['status'].upper()}")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("command", choices=("snapshot", "contract"))
    parser.add_argument("--output")
    parser.add_argument("--quiet", action="store_true")
    args = parser.parse_args()

    if args.command == "contract":
        contract = load_json(CONTRACT_PATH)
        alignment = contract_alignment(contract)
        ok = all(
            bool(alignment[name])
            for name in (
                "node_engine_present",
                "npm_engine_matches",
                "php_requirement_matches",
                "playwright_requirement_pinned",
            )
        )
        data = {
            "schema": "nadi.release-environment-contract-check.v1",
            "status": "pass" if ok else "fail",
            "checked_at_utc": datetime.now(timezone.utc).isoformat(),
            "contract": contract,
            "alignment": alignment,
        }
        write_output(data, args.output)
        if not args.quiet:
            print_human({"checks": {"contract_alignment": ok}, "status": data["status"]})
        return 0 if ok else 1

    data, ok = snapshot()
    write_output(data, args.output)
    if not args.quiet:
        print_human(data)
    return 0 if ok else 1


if __name__ == "__main__":
    raise SystemExit(main())
