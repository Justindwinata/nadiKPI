#!/usr/bin/env python3
"""Static contract audit between React API calls and Laravel routes.

This intentionally checks only literal/template endpoints that can be resolved from source.
Dynamic path expressions are normalized to wildcard segments and matched against Laravel
route parameters. It does not replace runtime HTTP tests; it prevents obvious orphan calls.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ROUTE_FILES = [ROOT / "routes" / "web.php", ROOT / "bootstrap" / "app.php"]
FRONTEND_FILE = ROOT / "resources" / "js" / "app.jsx"

METHOD_RE = re.compile(r"Route::(get|post|put|patch|delete)\(\s*(['\"])(.*?)\2")
PREFIX_RE = re.compile(r"Route::prefix\(\s*(['\"])(.*?)\1\).*?->group\(function\s*\(\)\s*\{")
HTTP_RE = re.compile(r"http\.(get|post|put|patch|delete)\(\s*([`'\"])(.*?)\2", re.S)
REMOTE_RE = re.compile(r"useRemote\(\s*([`'\"])(.*?)\1", re.S)
EVENT_RE = re.compile(r"new\s+EventSource\(\s*([`'\"])(.*?)\1", re.S)
DIRECT_API_RE = re.compile(r"([`'\"])(/api/[^`'\"\s<>{}]*?(?:\$\{.*?\}[^`'\"\s<>]*)?)\1", re.S)


def join_path(parts: list[str], leaf: str) -> str:
    segments = [p.strip("/") for p in [*parts, leaf] if p.strip("/")]
    return "/" + "/".join(segments)


def normalize(path: str, base_api: bool = False) -> str:
    path = path.strip()
    # Replace JS template expressions, including ternaries, with one wildcard segment.
    path = re.sub(r"\$\{.*?\}", "{param}", path)
    path = path.split("?", 1)[0]
    if base_api and not path.startswith("/api/") and path != "/api":
        path = "/api" + (path if path.startswith("/") else "/" + path)
    if not path.startswith("/"):
        path = "/" + path
    path = re.sub(r"/+", "/", path)
    return path.rstrip("/") or "/"


def parse_routes(text: str) -> set[tuple[str, str]]:
    routes: set[tuple[str, str]] = set()
    prefix_stack: list[tuple[int, str]] = []
    depth = 0

    for raw_line in text.splitlines():
        line = raw_line.strip()
        # Drop prefix scopes that ended before this line.
        while prefix_stack and depth < prefix_stack[-1][0]:
            prefix_stack.pop()

        prefix_match = PREFIX_RE.search(line)
        if prefix_match:
            prefix = prefix_match.group(2)
            # Group body's first statement is one brace deeper than current depth.
            prefix_stack.append((depth + line.count("{") - line.count("}"), prefix))

        method_match = METHOD_RE.search(line)
        if method_match:
            method, _, leaf = method_match.groups()
            prefixes = [p for _, p in prefix_stack]
            routes.add((method.upper(), normalize(join_path(prefixes, leaf))))

        depth += line.count("{") - line.count("}")
        while prefix_stack and depth < prefix_stack[-1][0]:
            prefix_stack.pop()

    return routes


def parse_frontend(text: str) -> set[tuple[str, str]]:
    calls: set[tuple[str, str]] = set()

    for match in HTTP_RE.finditer(text):
        method, _, raw = match.groups()
        # Ignore variable-only calls such as http.get(url) because regex only accepts quoted/template args.
        calls.add((method.upper(), normalize(raw, base_api=True)))

    for match in REMOTE_RE.finditer(text):
        _, raw = match.groups()
        calls.add(("GET", normalize(raw, base_api=True)))

    for match in EVENT_RE.finditer(text):
        _, raw = match.groups()
        calls.add(("GET", normalize(raw, base_api=False)))

    # Direct anchors/window.open API URLs are GETs. HTTP/useRemote duplicates collapse in the set.
    for match in DIRECT_API_RE.finditer(text):
        _, raw = match.groups()
        calls.add(("GET", normalize(raw, base_api=False)))

    return calls


def route_matches(route_path: str, call_path: str) -> bool:
    route_segments = [s for s in route_path.strip("/").split("/") if s]
    call_segments = [s for s in call_path.strip("/").split("/") if s]
    if len(route_segments) != len(call_segments):
        return False
    for route_seg, call_seg in zip(route_segments, call_segments):
        route_dynamic = route_seg.startswith("{") and route_seg.endswith("}")
        call_dynamic = call_seg.startswith("{") and call_seg.endswith("}")
        if route_dynamic or call_dynamic:
            continue
        if route_seg != call_seg:
            return False
    return True


def main() -> int:
    routes: set[tuple[str, str]] = set()
    for route_file in ROUTE_FILES:
        routes |= parse_routes(route_file.read_text(encoding="utf-8"))
    calls = parse_frontend(FRONTEND_FILE.read_text(encoding="utf-8"))

    missing: list[tuple[str, str]] = []
    for method, path in sorted(calls):
        if not any(route_method == method and route_matches(route_path, path) for route_method, route_path in routes):
            missing.append((method, path))

    print(f"Laravel routes parsed: {len(routes)}")
    print(f"Frontend API contracts parsed: {len(calls)}")
    if missing:
        print("Missing backend contracts:")
        for method, path in missing:
            print(f"  {method} {path}")
        return 1

    print("Frontend/API contract audit: PASS")
    return 0


if __name__ == "__main__":
    sys.exit(main())
