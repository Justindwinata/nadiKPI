#!/usr/bin/env python3
from __future__ import annotations

import json
import os
import re
import sys
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urlsplit, urlunsplit

try:
    from playwright.sync_api import Error as PlaywrightError
    from playwright.sync_api import TimeoutError as PlaywrightTimeoutError
    from playwright.sync_api import sync_playwright
except ImportError as exc:
    raise SystemExit(
        "Browser acceptance requires Playwright. Install with: "
        "python3 -m pip install -r requirements-e2e.txt && python3 -m playwright install chromium"
    ) from exc

BASE_URL = os.getenv("NADI_ACCEPTANCE_BASE_URL", "http://127.0.0.1:8000").rstrip("/")
EMAIL = os.getenv("NADI_ACCEPTANCE_EMAIL", "").strip()
PASSWORD = os.getenv("NADI_ACCEPTANCE_PASSWORD", "")
HOST_HEADER = os.getenv("NADI_ACCEPTANCE_HOST_HEADER", "").strip()
FORCED_EMAIL = os.getenv("NADI_BROWSER_FORCED_EMAIL", "").strip()
FORCED_PASSWORD = os.getenv("NADI_BROWSER_FORCED_PASSWORD", "")
FORCED_NEW_PASSWORD = os.getenv("NADI_BROWSER_FORCED_NEW_PASSWORD", "")
VIEWER_EMAIL = os.getenv("NADI_BROWSER_VIEWER_EMAIL", "").strip()
VIEWER_PASSWORD = os.getenv("NADI_BROWSER_VIEWER_PASSWORD", "")
VIEWER_NEW_PASSWORD = os.getenv("NADI_BROWSER_VIEWER_NEW_PASSWORD", "")
ARTIFACT_DIR = Path(os.getenv("NADI_BROWSER_ARTIFACT_DIR", "artifacts/browser-acceptance"))
BROWSER_EXECUTABLE_PATH = os.getenv("NADI_BROWSER_EXECUTABLE_PATH", "").strip()

if HOST_HEADER and urlsplit(BASE_URL).hostname in {"127.0.0.1", "localhost"}:
    parsed_base_url = urlsplit(BASE_URL)
    BASE_URL = urlunsplit((parsed_base_url.scheme, HOST_HEADER + (f":{parsed_base_url.port}" if parsed_base_url.port else ""), parsed_base_url.path, parsed_base_url.query, parsed_base_url.fragment)).rstrip("/")

ROUTES = [
    "/dashboard",
    "/certification",
    "/finance",
    "/it",
    "/governance",
    "/decisions",
    "/reports",
    "/integrations",
    "/kpi-catalog",
    "/master-data",
    "/admin/users",
    "/account",
]


def require(value: str, name: str) -> str:
    if not value:
        raise SystemExit(f"{name} is required for browser acceptance.")
    return value


def login(page, email: str, password: str) -> None:
    page.goto(f"{BASE_URL}/login", wait_until="domcontentloaded")
    page.get_by_label("Email").fill(email)
    page.get_by_label("Kata sandi", exact=True).fill(password)
    page.get_by_role("button", name="Masuk ke NADI").click()


def wait_for_operational_page(page) -> None:
    page.wait_for_selector("#main-content", state="visible", timeout=15_000)
    if page.locator(".error-state").count():
        raise AssertionError("Rendered page contains .error-state")


def assert_no_global_overflow(page, label: str) -> None:
    overflow = page.evaluate(
        "() => ({w: document.documentElement.scrollWidth, vw: window.innerWidth, body: document.body.scrollWidth})"
    )
    if max(int(overflow["w"]), int(overflow["body"])) > int(overflow["vw"]) + 3:
        raise AssertionError(f"Global horizontal overflow on {label}: {overflow}")


def assert_notification_center(page) -> str:
    trigger = page.get_by_role("button", name=re.compile(r"^Notifikasi"))
    trigger.first.click()
    dialog = page.get_by_role("dialog", name="Pusat notifikasi")
    dialog.wait_for(state="visible", timeout=10_000)
    status = dialog.locator("header small")
    status.wait_for(state="visible", timeout=10_000)
    status_text = status.inner_text().strip()
    if status_text not in {"Realtime tersambung", "Fallback sinkronisasi aktif"}:
        raise AssertionError(f"Notification transport state is not recognized: {status_text!r}")
    if dialog.get_by_text("Memuat notifikasi…", exact=True).count():
        dialog.get_by_text("Memuat notifikasi…", exact=True).wait_for(state="hidden", timeout=10_000)
    trigger.first.click()
    return status_text


def create_viewer_through_ui(page) -> None:
    page.goto(f"{BASE_URL}/admin/users", wait_until="domcontentloaded")
    wait_for_operational_page(page)
    page.get_by_role("button", name="Tambah pengguna").click()
    dialog = page.get_by_role("dialog", name="Tambah pengguna")
    dialog.get_by_label("Nama").fill("NADI Browser Viewer")
    dialog.get_by_label("Email").fill(VIEWER_EMAIL)
    dialog.get_by_label("Role").select_option("viewer")
    dialog.get_by_label("Jabatan").fill("Acceptance Viewer")
    dialog.get_by_label("Password sementara").fill(VIEWER_PASSWORD)
    dialog.get_by_label("Konfirmasi").fill(VIEWER_PASSWORD)
    dialog.get_by_role("button", name="Buat pengguna").click()
    page.get_by_text("Pengguna dibuat dengan kewajiban mengganti kata sandi sementara.", exact=True).wait_for(timeout=10_000)
    page.get_by_text(VIEWER_EMAIL, exact=True).wait_for(timeout=10_000)


def generate_report_and_verify_exports(page, context) -> dict[str, object]:
    page.goto(f"{BASE_URL}/reports", wait_until="domcontentloaded")
    wait_for_operational_page(page)
    create_button = page.get_by_role("button", name="Buat snapshot")
    create_button.wait_for(state="visible", timeout=15_000)
    page.wait_for_function(
        "() => [...document.querySelectorAll('button')].some(button => button.textContent.includes('Buat snapshot') && !button.disabled)",
        timeout=15_000,
    )
    with page.expect_response(
        lambda response: response.request.method == "POST" and response.url.rstrip("/").endswith("/api/reports"),
        timeout=15_000,
    ) as response_info:
        create_button.click()
    response = response_info.value
    if response.status != 201:
        raise AssertionError(f"Report snapshot creation returned HTTP {response.status}")
    payload = response.json()
    report = payload.get("report") or {}
    report_id = int(report.get("id") or 0)
    content_hash = str(report.get("content_hash") or "")
    if report_id < 1 or not re.fullmatch(r"[0-9a-f]{64}", content_hash):
        raise AssertionError(f"Invalid immutable report evidence: id={report_id}, hash={content_hash!r}")

    preview = page.locator(".report-preview")
    preview.wait_for(state="visible", timeout=15_000)
    preview.locator(".report-hash code").filter(has_text=content_hash).wait_for(timeout=10_000)

    exports: dict[str, object] = {}
    expected_content_types = {
        "csv": "text/csv",
        "json": "application/json",
        "zip": "application/zip",
    }
    for fmt, expected_type in expected_content_types.items():
        link = preview.locator(f'a[href="/api/reports/{report_id}/export/{fmt}"]')
        if link.count() != 1:
            raise AssertionError(f"Missing {fmt.upper()} export link for generated snapshot")
        export_response = context.request.get(f"{BASE_URL}/api/reports/{report_id}/export/{fmt}")
        if export_response.status != 200:
            raise AssertionError(f"{fmt.upper()} export returned HTTP {export_response.status}")
        content_type = export_response.headers.get("content-type", "")
        export_hash = export_response.headers.get("x-nadi-report-hash", "")
        body = export_response.body()
        if expected_type not in content_type:
            raise AssertionError(f"{fmt.upper()} export content type mismatch: {content_type!r}")
        if export_hash != content_hash:
            raise AssertionError(f"{fmt.upper()} export report hash mismatch")
        if len(body) < 2:
            raise AssertionError(f"{fmt.upper()} export is empty")
        if fmt == "zip" and not body.startswith(b"PK"):
            raise AssertionError("Evidence Pack ZIP does not have a ZIP signature")
        artifact_path = ARTIFACT_DIR / f"report-export.{fmt}"
        artifact_path.write_bytes(body)
        exports[fmt] = {
            "bytes": len(body),
            "content_type": content_type,
            "hash": export_hash,
            "artifact": str(artifact_path),
        }

    return {"id": report_id, "content_hash": content_hash, "exports": exports}


def verify_viewer_boundary(browser, extra_headers, report_id: int) -> dict[str, object]:
    viewer_context = browser.new_context(viewport={"width": 1280, "height": 900}, extra_http_headers=extra_headers)
    viewer_page = viewer_context.new_page()
    login(viewer_page, VIEWER_EMAIL, VIEWER_PASSWORD)
    viewer_page.get_by_role("heading", name="Buat kata sandi pribadi").wait_for(timeout=15_000)
    viewer_page.get_by_label("Kata sandi saat ini").fill(VIEWER_PASSWORD)
    viewer_page.get_by_label("Kata sandi baru").fill(VIEWER_NEW_PASSWORD)
    viewer_page.get_by_label("Konfirmasi kata sandi").fill(VIEWER_NEW_PASSWORD)
    viewer_page.get_by_role("button", name="Perbarui kata sandi").click()
    viewer_page.wait_for_url("**/dashboard", timeout=15_000)
    wait_for_operational_page(viewer_page)
    viewer_page.screenshot(path=str(ARTIFACT_DIR / "viewer-dashboard.png"), full_page=True)

    allowed_nav = ["Ikhtisar", "Laporan", "Katalog KPI", "Akun Saya"]
    restricted_nav = ["Sertifikasi", "Keuangan", "Teknologi", "Mutu & Kepatuhan", "Pusat Keputusan", "Integrasi Data", "Pusat Data", "Pengguna & Akses"]
    sidebar = viewer_page.locator("aside.sidebar")
    for label in allowed_nav:
        if sidebar.get_by_role("link", name=label).count() != 1:
            raise AssertionError(f"Viewer is missing allowed navigation item: {label}")
    for label in restricted_nav:
        if sidebar.get_by_role("link", name=label).count() != 0:
            raise AssertionError(f"Viewer can see restricted navigation item: {label}")

    viewer_page.goto(f"{BASE_URL}/admin/users", wait_until="domcontentloaded")
    viewer_page.wait_for_url("**/dashboard", timeout=10_000)
    viewer_page.goto(f"{BASE_URL}/finance", wait_until="domcontentloaded")
    viewer_page.wait_for_url("**/dashboard", timeout=10_000)

    admin_api = viewer_context.request.get(f"{BASE_URL}/api/admin/users")
    if admin_api.status != 403:
        raise AssertionError(f"Viewer admin API boundary expected 403, got {admin_api.status}")
    report_api = viewer_context.request.get(f"{BASE_URL}/api/reports/{report_id}")
    if report_api.status != 403:
        raise AssertionError(f"Viewer object-level report boundary expected 403, got {report_api.status}")
    export_api = viewer_context.request.get(f"{BASE_URL}/api/reports/{report_id}/export/zip")
    if export_api.status != 403:
        raise AssertionError(f"Viewer report export boundary expected 403, got {export_api.status}")

    viewer_page.goto(f"{BASE_URL}/reports", wait_until="domcontentloaded")
    wait_for_operational_page(viewer_page)
    with viewer_page.expect_response(
        lambda response: response.request.method == "POST" and response.url.rstrip("/").endswith("/api/reports"),
        timeout=15_000,
    ) as viewer_report_response_info:
        viewer_page.get_by_role("button", name="Buat snapshot").click()
    viewer_report_response = viewer_report_response_info.value
    if viewer_report_response.status != 201:
        raise AssertionError(f"Viewer department snapshot returned HTTP {viewer_report_response.status}")
    viewer_report = (viewer_report_response.json().get("report") or {})
    viewer_report_id = int(viewer_report.get("id") or 0)
    viewer_preview = viewer_page.locator(".report-preview")
    viewer_preview.wait_for(state="visible", timeout=15_000)
    viewer_page.screenshot(path=str(ARTIFACT_DIR / "viewer-report.png"), full_page=True)
    if viewer_preview.locator(".report-export-actions a").count() != 0 or viewer_preview.get_by_role("button", name="Preview / Print PDF").count() != 0:
        raise AssertionError("Viewer unexpectedly has report export controls")
    viewer_export_api = viewer_context.request.get(f"{BASE_URL}/api/reports/{viewer_report_id}/export/zip")
    if viewer_export_api.status != 403:
        raise AssertionError(f"Viewer own-report export boundary expected 403, got {viewer_export_api.status}")
    transport = assert_notification_center(viewer_page)
    viewer_context.close()
    return {
        "forced_password_flow": "pass",
        "allowed_navigation": allowed_nav,
        "restricted_navigation_hidden": restricted_nav,
        "admin_api_status": admin_api.status,
        "executive_report_status": report_api.status,
        "executive_export_status": export_api.status,
        "viewer_report_id": viewer_report_id,
        "viewer_export_status": viewer_export_api.status,
        "notification_transport": transport,
    }


def main() -> int:
    require(EMAIL, "NADI_ACCEPTANCE_EMAIL")
    require(PASSWORD, "NADI_ACCEPTANCE_PASSWORD")
    require(VIEWER_EMAIL, "NADI_BROWSER_VIEWER_EMAIL")
    require(VIEWER_PASSWORD, "NADI_BROWSER_VIEWER_PASSWORD")
    require(VIEWER_NEW_PASSWORD, "NADI_BROWSER_VIEWER_NEW_PASSWORD")
    ARTIFACT_DIR.mkdir(parents=True, exist_ok=True)

    evidence: dict[str, object] = {
        "started_at_utc": datetime.now(timezone.utc).isoformat(),
        "base_url": BASE_URL,
        "routes": [],
        "console_errors": [],
        "page_errors": [],
        "request_failures": [],
        "bad_responses": [],
        "forced_password_flow": "not_configured",
        "functional_scenarios": {},
    }

    # Chromium rejects Host as a manually supplied navigation header. Host and
    # forwarded-proxy behavior are covered by the HTTP acceptance phase.
    extra_headers = None

    with sync_playwright() as pw:
        launch_kwargs = {"headless": True}
        if HOST_HEADER and BASE_URL.startswith(("http://"+HOST_HEADER, "https://"+HOST_HEADER)):
            launch_kwargs["args"] = [f"--host-resolver-rules=MAP {HOST_HEADER} 127.0.0.1"]
        if BROWSER_EXECUTABLE_PATH:
            executable = Path(BROWSER_EXECUTABLE_PATH)
            if not executable.is_file() or not os.access(executable, os.X_OK):
                raise AssertionError(f"Configured browser executable does not exist: {executable}")
            launch_kwargs["executable_path"] = str(executable)
        browser = pw.chromium.launch(**launch_kwargs)
        context = browser.new_context(viewport={"width": 1440, "height": 1000}, extra_http_headers=extra_headers)
        page = context.new_page()

        # Ignore the expected unauthenticated /api/me probe before login by attaching diagnostics after login.
        login(page, EMAIL, PASSWORD)
        page.wait_for_url("**/dashboard", timeout=15_000)
        wait_for_operational_page(page)

        page.on("console", lambda msg: evidence["console_errors"].append(msg.text) if msg.type == "error" else None)
        page.on("pageerror", lambda exc: evidence["page_errors"].append(str(exc)))
        page.on("requestfailed", lambda req: evidence["request_failures"].append({"url": req.url, "failure": req.failure}))
        page.on(
            "response",
            lambda response: evidence["bad_responses"].append({"status": response.status, "url": response.url})
            if response.status >= 400
            else None,
        )

        for route in ROUTES:
            page.goto(f"{BASE_URL}{route}", wait_until="domcontentloaded")
            wait_for_operational_page(page)
            assert_no_global_overflow(page, route)
            heading = page.locator("#main-content h1, #main-content h2").first
            try:
                heading.wait_for(state="visible", timeout=15_000)
            except PlaywrightTimeoutError as exc:
                raise AssertionError(f"No visible page heading found on {route}") from exc
            evidence["routes"].append({"route": route, "heading": heading.inner_text().strip()})

        page.goto(f"{BASE_URL}/dashboard", wait_until="domcontentloaded")
        wait_for_operational_page(page)
        page.screenshot(path=str(ARTIFACT_DIR / "dashboard-desktop.png"), full_page=True)

        evidence["functional_scenarios"]["notification_center"] = {
            "transport": assert_notification_center(page),
        }

        report_evidence = generate_report_and_verify_exports(page, context)
        evidence["functional_scenarios"]["immutable_report"] = report_evidence

        create_viewer_through_ui(page)
        evidence["functional_scenarios"]["viewer_creation"] = {
            "status": "pass",
            "email": VIEWER_EMAIL,
            "role": "viewer",
        }

        # Keyboard/focus smoke: the skip link should be the first useful keyboard target.
        page.locator("body").press("Home")
        page.keyboard.press("Tab")
        focused_class = page.evaluate("() => document.activeElement && document.activeElement.className")
        if "skip-link" not in str(focused_class):
            raise AssertionError(f"Skip-link is not first keyboard focus target (active class={focused_class!r})")

        # Responsive/mobile navigation smoke.
        mobile = browser.new_context(viewport={"width": 390, "height": 844}, extra_http_headers=extra_headers)
        mobile_page = mobile.new_page()
        login(mobile_page, EMAIL, PASSWORD)
        mobile_page.wait_for_url("**/dashboard", timeout=15_000)
        wait_for_operational_page(mobile_page)
        assert_no_global_overflow(mobile_page, "mobile dashboard")
        mobile_page.get_by_role("button", name="Buka navigasi").click()
        if "open" not in (mobile_page.locator("aside.sidebar").get_attribute("class") or ""):
            raise AssertionError("Mobile sidebar did not open")
        mobile_page.screenshot(path=str(ARTIFACT_DIR / "dashboard-mobile.png"), full_page=True)
        mobile.close()

        # Logout/session UI path.
        page.goto(f"{BASE_URL}/dashboard", wait_until="domcontentloaded")
        wait_for_operational_page(page)
        page.locator("button.user-card").click()
        page.wait_for_url("**/login", timeout=15_000)
        evidence["logout"] = "pass"
        context.close()

        # Forced-password browser flow is mandatory when configured by the CI release gate.
        if FORCED_EMAIL or FORCED_PASSWORD or FORCED_NEW_PASSWORD:
            require(FORCED_EMAIL, "NADI_BROWSER_FORCED_EMAIL")
            require(FORCED_PASSWORD, "NADI_BROWSER_FORCED_PASSWORD")
            require(FORCED_NEW_PASSWORD, "NADI_BROWSER_FORCED_NEW_PASSWORD")
            forced_context = browser.new_context(viewport={"width": 1280, "height": 900}, extra_http_headers=extra_headers)
            forced_page = forced_context.new_page()
            login(forced_page, FORCED_EMAIL, FORCED_PASSWORD)
            forced_page.get_by_role("heading", name="Buat kata sandi pribadi").wait_for(timeout=15_000)
            forced_page.get_by_label("Kata sandi saat ini").fill(FORCED_PASSWORD)
            forced_page.get_by_label("Kata sandi baru").fill(FORCED_NEW_PASSWORD)
            forced_page.get_by_label("Konfirmasi kata sandi").fill(FORCED_NEW_PASSWORD)
            forced_page.get_by_role("button", name="Perbarui kata sandi").click()
            forced_page.wait_for_selector("#main-content", state="visible", timeout=15_000)
            evidence["forced_password_flow"] = "pass"
            forced_context.close()

        evidence["functional_scenarios"]["viewer_boundary"] = verify_viewer_boundary(
            browser,
            extra_headers,
            int(report_evidence["id"]),
        )

        browser.close()

    diagnostics = [
        ("console_errors", evidence["console_errors"]),
        ("page_errors", evidence["page_errors"]),
        ("request_failures", evidence["request_failures"]),
        ("bad_responses", evidence["bad_responses"]),
    ]
    evidence["finished_at_utc"] = datetime.now(timezone.utc).isoformat()
    evidence_path = ARTIFACT_DIR / "browser_acceptance.json"
    evidence_path.write_text(json.dumps(evidence, ensure_ascii=False, indent=2) + "\n")

    failures = [(name, values) for name, values in diagnostics if values]
    if failures:
        for name, values in failures:
            print(f"FAIL {name}: {json.dumps(values, ensure_ascii=False)}", file=sys.stderr)
        print(f"Evidence: {evidence_path}", file=sys.stderr)
        return 1

    print(f"NADI browser acceptance: PASS ({len(ROUTES)} routes + report/export + viewer RBAC + notifications + responsive + focus + logout)")
    print(f"Evidence: {evidence_path}")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (AssertionError, PlaywrightTimeoutError, PlaywrightError) as exc:
        ARTIFACT_DIR.mkdir(parents=True, exist_ok=True)
        failure = ARTIFACT_DIR / "browser_acceptance_failure.txt"
        failure.write_text(f"{type(exc).__name__}: {exc}\n")
        print(f"NADI browser acceptance: FAIL — {exc}", file=sys.stderr)
        print(f"Failure evidence: {failure}", file=sys.stderr)
        raise SystemExit(1)
