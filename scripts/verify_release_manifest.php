<?php

$root = realpath(__DIR__.'/..');
if ($root === false) {
    fwrite(STDERR, "FAIL: unable to resolve repository root.\n");
    exit(1);
}

$manifestPath = $root.'/RELEASE_MANIFEST.json';
if (! is_file($manifestPath) || is_link($manifestPath)) {
    fwrite(STDERR, "FAIL: RELEASE_MANIFEST.json is missing or is a symlink. Deploy only from an intact packaged NADI release artifact.\n");
    exit(1);
}

try {
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: RELEASE_MANIFEST.json is invalid JSON.\n");
    exit(1);
}

if (! is_array($manifest) || ! isset($manifest['files']) || ! is_array($manifest['files'])) {
    fwrite(STDERR, "FAIL: release manifest does not contain a files array.\n");
    exit(1);
}

$expectedCount = (int) ($manifest['file_count'] ?? -1);
if ($expectedCount !== count($manifest['files'])) {
    fwrite(STDERR, "FAIL: release manifest file_count does not match files array.\n");
    exit(1);
}

$normalize = static function (string $path): string {
    $path = str_replace('\\', '/', $path);
    return str_starts_with($path, './') ? substr($path, 2) : $path;
};
$allowedRuntimeExtra = static function (string $relative) use ($normalize): bool {
    $relative = $normalize($relative);
    if (in_array($relative, ['.env', '.env.production'], true)) {
        return true;
    }

    // Mutable application runtime locations are provisioned/created after release extraction.
    foreach ([
        'storage/framework/cache/',
        'storage/framework/sessions/',
        'storage/framework/views/',
        'storage/logs/',
        'storage/app/private/',
    ] as $prefix) {
        if (str_starts_with($relative, $prefix)) {
            return true;
        }
    }

    return false;
};

$seen = [];
$failures = [];
foreach ($manifest['files'] as $index => $entry) {
    if (! is_array($entry)) {
        $failures[] = "entry {$index}: invalid entry";
        continue;
    }

    $relative = (string) ($entry['path'] ?? '');
    $expectedHash = strtolower((string) ($entry['sha256'] ?? ''));
    $expectedBytes = $entry['bytes'] ?? null;

    $normalized = str_replace('\\', '/', $relative);
    if ($normalized === ''
        || str_starts_with($normalized, '/')
        || preg_match('/^[A-Za-z]:\//', $normalized)
        || in_array('..', explode('/', $normalized), true)) {
        $failures[] = "entry {$index}: unsafe path";
        continue;
    }

    if (isset($seen[$normalized])) {
        $failures[] = "duplicate path: {$normalized}";
        continue;
    }
    $seen[$normalized] = true;

    if (! preg_match('/^[a-f0-9]{64}$/', $expectedHash)) {
        $failures[] = "invalid sha256: {$normalized}";
        continue;
    }
    if (! is_int($expectedBytes) && ! ctype_digit((string) $expectedBytes)) {
        $failures[] = "invalid byte count: {$normalized}";
        continue;
    }

    $path = $root.'/'.$normalized;
    if (is_link($path)) {
        $failures[] = "symlink not allowed: {$normalized}";
        continue;
    }
    if (! is_file($path)) {
        $failures[] = "missing file: {$normalized}";
        continue;
    }

    $actualBytes = filesize($path);
    if ($actualBytes !== (int) $expectedBytes) {
        $failures[] = "size mismatch: {$normalized}";
        continue;
    }

    $actualHash = hash_file('sha256', $path);
    if (! hash_equals($expectedHash, strtolower($actualHash))) {
        $failures[] = "sha256 mismatch: {$normalized}";
    }
}

// Closed-world verification: an extracted release may contain only files covered by
// the immutable manifest plus explicitly allowed mutable runtime locations.
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST,
);
foreach ($iterator as $item) {
    $absolute = $item->getPathname();
    $relative = $normalize(substr($absolute, strlen($root) + 1));

    if ($item->isLink()) {
        $failures[] = "unexpected symlink: {$relative}";
        continue;
    }
    if (! $item->isFile()) {
        continue;
    }
    if ($relative === 'RELEASE_MANIFEST.json' || isset($seen[$relative]) || $allowedRuntimeExtra($relative)) {
        continue;
    }

    $failures[] = "unexpected file not covered by release manifest: {$relative}";
}

if ($failures !== []) {
    $failures = array_values(array_unique($failures));
    foreach (array_slice($failures, 0, 30) as $failure) {
        fwrite(STDERR, 'FAIL: '.$failure."\n");
    }
    if (count($failures) > 30) {
        fwrite(STDERR, 'FAIL: '.(count($failures) - 30)." additional manifest verification failures omitted.\n");
    }
    exit(1);
}

if (($manifest['frontend_build_included'] ?? false) !== true) {
    fwrite(STDERR, "FAIL: release manifest does not assert frontend_build_included=true.\n");
    exit(1);
}

printf(
    "PASS release manifest: version=%s files=%d closed_world=yes\n",
    (string) ($manifest['version'] ?? 'unknown'),
    count($manifest['files']),
);
