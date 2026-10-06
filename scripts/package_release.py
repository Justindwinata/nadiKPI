#!/usr/bin/env python3
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import zipfile
from datetime import datetime, timezone

ROOT = Path(__file__).resolve().parents[1]
EXCLUDED_DIRS = {
    '.git', '.idea', '.vscode', '.cursor', '.codex', '.agents', '.claude',
    'node_modules', 'vendor', 'dist', 'artifacts', '__MACOSX', '__pycache__', '.pytest_cache', '.ruff_cache', '.mypy_cache',
}
EXCLUDED_FILES = {
    '.env', '.env.production', '.env.backup', '.DS_Store', '.mcp.json',
    'AGENTS.md', 'CLAUDE.md', 'opencode.json', 'boost.json', 'database/database.sqlite',
    'storage/logs/laravel.log', '.phpunit.result.cache', '.coverage',
}
EXCLUDED_PREFIXES = (
    'bootstrap/cache/',
    'storage/framework/cache/data/',
    'storage/framework/sessions/',
    'storage/framework/views/',
)
KEEP_RUNTIME_PLACEHOLDERS = {
    'bootstrap/cache/.gitignore',
    'storage/framework/cache/data/.gitignore',
    'storage/framework/cache/.gitignore',
    'storage/framework/sessions/.gitignore',
    'storage/framework/views/.gitignore',
    'storage/logs/.gitignore',
}
ALLOWED_ENV_TEMPLATES = {'.env.example', '.env.production.example'}
FORBIDDEN_TEXT_MARKERS = ('Demo' + '12345!',)


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open('rb') as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b''):
            h.update(chunk)
    return h.hexdigest()


def normalized_epoch() -> int:
    raw = os.environ.get('SOURCE_DATE_EPOCH', '').strip()
    if raw:
        try:
            value = int(raw)
            if value < 315532800:  # ZIP timestamps cannot predate 1980.
                raise ValueError
            return value
        except ValueError:
            raise SystemExit('SOURCE_DATE_EPOCH must be an integer Unix timestamp >= 315532800 (1980-01-01).')
    # Stable default for reproducible local packaging.
    return 946684800  # 2000-01-01T00:00:00Z


def is_runtime_env(rel: str) -> bool:
    name = Path(rel).name
    return name.startswith('.env') and name not in ALLOWED_ENV_TEMPLATES


def included_files(output_dir: Path) -> list[Path]:
    files: list[Path] = []
    output_dir = output_dir.resolve()
    for path in ROOT.rglob('*'):
        if not path.is_file():
            continue
        resolved = path.resolve()
        if resolved == output_dir or output_dir in resolved.parents:
            continue
        rel_path = path.relative_to(ROOT)
        rel = rel_path.as_posix()
        if any(part in EXCLUDED_DIRS for part in rel_path.parts):
            continue
        if rel in EXCLUDED_FILES or is_runtime_env(rel):
            continue
        if re.match(r'^NADI[_-]LSP[_-]MIGAS.*\.zip(?:\.sha256)?$', path.name, re.I):
            continue
        if any(rel.startswith(prefix) for prefix in EXCLUDED_PREFIXES) and rel not in KEEP_RUNTIME_PLACEHOLDERS:
            continue
        if rel.startswith('storage/logs/') and rel not in KEEP_RUNTIME_PLACEHOLDERS:
            continue
        if rel.startswith('storage/app/private/') and not rel.endswith('/.gitignore'):
            continue
        files.append(path)
    return sorted(files, key=lambda p: p.relative_to(ROOT).as_posix())


def validate_vite_manifest(manifest: Path) -> dict:
    try:
        decoded = json.loads(manifest.read_text())
        if not isinstance(decoded, dict) or not decoded:
            raise ValueError('empty manifest')
    except Exception as exc:
        raise SystemExit(f'Release packaging refused: invalid Vite manifest ({exc}).')

    referenced: set[str] = set()
    for value in decoded.values():
        if not isinstance(value, dict):
            continue
        file_name = value.get('file')
        if isinstance(file_name, str):
            referenced.add(file_name)
        for key in ('css', 'assets'):
            items = value.get(key, [])
            if isinstance(items, list):
                referenced.update(item for item in items if isinstance(item, str))
        for key in ('imports', 'dynamicImports'):
            items = value.get(key, [])
            if isinstance(items, list):
                missing_entries = [item for item in items if isinstance(item, str) and item not in decoded]
                if missing_entries:
                    raise SystemExit(
                        'Release packaging refused: Vite manifest references missing manifest entries: '
                        + ', '.join(missing_entries[:10])
                    )

    missing = [asset for asset in sorted(referenced) if not (ROOT / 'public/build' / asset).is_file()]
    if missing:
        raise SystemExit('Release packaging refused: Vite manifest references missing assets: ' + ', '.join(missing[:10]))
    return decoded


def scan_release_files(files: list[Path]) -> None:
    for path in files:
        rel = path.relative_to(ROOT).as_posix()
        if is_runtime_env(rel):
            raise SystemExit(f'Release packaging refused: runtime environment file would be included: {rel}')
        if path.stat().st_size > 2 * 1024 * 1024:
            continue
        try:
            text = path.read_text(errors='strict')
        except (UnicodeDecodeError, OSError):
            continue
        for marker in FORBIDDEN_TEXT_MARKERS:
            if marker in text:
                raise SystemExit(f'Release packaging refused: forbidden demo credential marker found in {rel}.')


def zip_info(rel: str, epoch: int, executable: bool) -> zipfile.ZipInfo:
    dt = datetime.fromtimestamp(epoch, tz=timezone.utc)
    info = zipfile.ZipInfo(rel, (dt.year, dt.month, dt.day, dt.hour, dt.minute, dt.second))
    info.compress_type = zipfile.ZIP_DEFLATED
    info.create_system = 3
    mode = 0o755 if executable else 0o644
    info.external_attr = (stat.S_IFREG | mode) << 16
    return info


def main() -> int:
    parser = argparse.ArgumentParser(description='Create a clean, reproducible NADI release archive.')
    parser.add_argument('--version', default='rc', help='Release version/label, e.g. 1.0.0-rc1')
    parser.add_argument('--output-dir', default='dist')
    args = parser.parse_args()

    manifest = ROOT / 'public/build/manifest.json'
    if not manifest.is_file():
        raise SystemExit('Release packaging refused: public/build/manifest.json is missing. Run npm ci && npm run build first.')
    validate_vite_manifest(manifest)

    output_dir = (ROOT / args.output_dir).resolve()
    output_dir.mkdir(parents=True, exist_ok=True)
    safe_version = ''.join(c if c.isalnum() or c in '.-_' else '-' for c in args.version)
    output = output_dir / f'NADI-LSP-MIGAS-{safe_version}.zip'
    checksum_file = output.with_suffix(output.suffix + '.sha256')
    output.unlink(missing_ok=True)
    checksum_file.unlink(missing_ok=True)

    epoch = normalized_epoch()
    files = included_files(output_dir)
    scan_release_files(files)
    entries = []
    for path in files:
        rel = path.relative_to(ROOT).as_posix()
        entries.append({'path': rel, 'sha256': sha256(path), 'bytes': path.stat().st_size})

    release_manifest = {
        'product': 'NADI — LSP Migas KPI & Decision Intelligence',
        'version': args.version,
        'source_date_epoch': epoch,
        'created_at_utc': datetime.fromtimestamp(epoch, tz=timezone.utc).isoformat(),
        'files': entries,
        'file_count': len(entries),
        'source_requires_composer_install': True,
        'frontend_build_included': True,
    }
    manifest_bytes = (json.dumps(release_manifest, sort_keys=True, indent=2, ensure_ascii=False) + '\n').encode('utf-8')

    with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
        for path in files:
            rel = path.relative_to(ROOT).as_posix()
            executable = bool(path.stat().st_mode & 0o111)
            zf.writestr(zip_info(rel, epoch, executable), path.read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
        zf.writestr(zip_info('RELEASE_MANIFEST.json', epoch, False), manifest_bytes, compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)

    digest = sha256(output)
    checksum_file.write_text(f'{digest}  {output.name}\n')
    print(output)
    print(f'SHA-256 {digest}')
    print(f'Files {len(entries)} + RELEASE_MANIFEST.json')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
