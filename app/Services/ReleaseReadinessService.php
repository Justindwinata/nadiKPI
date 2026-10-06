<?php

namespace App\Services;

use Illuminate\Database\Migrator;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReleaseReadinessService
{
    /**
     * @return array{ready: bool, checks: array<int, array{name:string,status:string,message:string}>}
     */
    public function evaluate(bool $production = false, bool $includeDatabase = true, bool $includeBuild = true): array
    {
        $checks = [];
        $push = function (string $name, bool $ok, string $message) use (&$checks): void {
            $checks[] = ['name' => $name, 'status' => $ok ? 'pass' : 'fail', 'message' => $message];
        };

        $push('php_version', version_compare(PHP_VERSION, '8.4.1', '>='), 'PHP '.PHP_VERSION.'; minimum 8.4.1 for the locked dependency set.');

        foreach (['ctype', 'dom', 'fileinfo', 'filter', 'hash', 'json', 'libxml', 'mbstring', 'openssl', 'pcre', 'pdo', 'session', 'tokenizer', 'xml', 'xmlwriter'] as $extension) {
            $push('extension_'.$extension, extension_loaded($extension), 'PHP extension '.$extension.' '.(extension_loaded($extension) ? 'available.' : 'missing.'));
        }

        if ($production) {
            $push('extension_pdo_mysql', extension_loaded('pdo_mysql'), 'Production MySQL requires pdo_mysql.');
            $push('app_environment', app()->environment('production'), 'APP_ENV must be production.');
            $push('debug_disabled', ! (bool) config('app.debug'), 'APP_DEBUG must be false.');
            $push('https_url', str_starts_with((string) config('app.url'), 'https://'), 'APP_URL must use HTTPS.');
            $push('database_mysql', config('database.default') === 'mysql', 'DB_CONNECTION must be mysql.');
            $databaseUser = trim((string) config('database.connections.mysql.username'));
            $databasePassword = trim((string) config('database.connections.mysql.password'));
            $databaseCredentialsReady = $databaseUser !== ''
                && $databasePassword !== ''
                && strtoupper($databaseUser) !== 'CHANGE_ME'
                && strtoupper($databasePassword) !== 'CHANGE_ME';
            $push('database_credentials_configured', $databaseCredentialsReady, 'Production MySQL username/password must be configured and must not use template placeholders.');
            $productionUrl = rtrim((string) config('app.url'), '/');
            $push('production_url_configured', $productionUrl !== 'https://nadi.example.co.id', 'APP_URL must be replaced with the real production hostname.');
            $push('session_database', config('session.driver') === 'database', 'SESSION_DRIVER must be database in production.');
            $push('cache_database', config('cache.default') === 'database', 'CACHE_STORE must be database in production.');
            $push('queue_database', config('queue.default') === 'database', 'QUEUE_CONNECTION must be database in production.');
            $push('session_encrypted', (bool) config('session.encrypt'), 'SESSION_ENCRYPT must be true.');
            $push('session_secure_cookie', (bool) config('session.secure'), 'SESSION_SECURE_COOKIE must be true.');
            $push('demo_disabled', ! (bool) config('nadi.demo_mode'), 'VITE_DEMO_MODE must be false for production builds.');
            $push('demo_seed_disabled', ! (bool) config('nadi.allow_demo_seed'), 'NADI_ALLOW_DEMO_SEED must be false in production.');
        }

        $key = (string) config('app.key');
        $keyBytes = $key;
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            $keyBytes = $decoded === false ? '' : $decoded;
        }
        $expectedKeyLength = str_contains(strtoupper((string) config('app.cipher')), '256') ? 32 : 16;
        $keyByteValues = $keyBytes === '' ? [] : array_values(unpack('C*', $keyBytes));
        $keyLooksRandom = strlen($keyBytes) === $expectedKeyLength
            && count(array_unique($keyByteValues)) >= min(8, $expectedKeyLength);
        $push(
            'application_key',
            $keyLooksRandom,
            'APP_KEY must decode to a non-placeholder '.$expectedKeyLength.'-byte key compatible with '.config('app.cipher').'.',
        );

        foreach ([storage_path('framework'), storage_path('logs'), base_path('bootstrap/cache')] as $path) {
            $push('writable_'.basename($path), is_dir($path) && is_writable($path), $path.' must exist and be writable.');
        }

        if ($includeBuild) {
            $manifestPath = public_path('build/manifest.json');
            $manifestOk = is_file($manifestPath);
            $message = $manifestOk ? 'Vite manifest exists.' : 'public/build/manifest.json is missing; run npm ci && npm run build.';
            if ($manifestOk) {
                $decoded = json_decode((string) file_get_contents($manifestPath), true);
                $manifestOk = is_array($decoded) && $decoded !== [];
                if ($manifestOk) {
                    foreach ($decoded as $entry) {
                        $file = is_array($entry) ? ($entry['file'] ?? null) : null;
                        if ($file && ! is_file(public_path('build/'.$file))) {
                            $manifestOk = false;
                            $message = 'Vite manifest references a missing asset: '.$file;
                            break;
                        }
                    }
                } else {
                    $message = 'Vite manifest is empty or invalid JSON.';
                }
            }
            $push('frontend_build', $manifestOk, $message);

            if ($production && $manifestOk) {
                $demoLeak = false;
                foreach (glob(public_path('build/assets/*.js')) ?: [] as $asset) {
                    $contents = (string) file_get_contents($asset);
                    if (str_contains($contents, '@demo.test') || str_contains($contents, 'Demo'.'12345!')) {
                        $demoLeak = true;
                        break;
                    }
                }
                $push('frontend_demo_credentials', ! $demoLeak, $demoLeak
                    ? 'Production JavaScript still contains demo credentials.'
                    : 'No demo credential marker found in production JavaScript.');
            }
        }

        if ($includeDatabase) {
            try {
                DB::select('select 1');
                $push('database_connection', true, 'Database connection succeeded.');

                if ($production) {
                    $server = DB::selectOne('SELECT VERSION() AS version');
                    $serverVersion = (string) ($server->version ?? '');
                    $mysql8 = stripos($serverVersion, 'mariadb') === false
                        && preg_match('/^8\.(\d+)/', $serverVersion) === 1;
                    $push(
                        'database_server_mysql8',
                        $mysql8,
                        $mysql8
                            ? 'Database server is supported MySQL 8.x: '.$serverVersion
                            : 'Production database must be MySQL 8.x (MariaDB/other engines are not release targets). Server reported: '.$serverVersion,
                    );
                }

                /** @var Migrator $migrator */
                $migrator = app('migrator');
                if (! $migrator->repositoryExists()) {
                    $push('migrations', false, 'Migration repository does not exist. Run php artisan migrate --force.');
                } else {
                    $files = $migrator->getMigrationFiles(database_path('migrations'));
                    $ran = $migrator->getRepository()->getRan();
                    $pending = array_values(array_diff(array_keys($files), $ran));
                    $push('migrations', $pending === [], $pending === [] ? 'No pending migrations.' : 'Pending migrations: '.implode(', ', $pending));
                }
            } catch (Throwable $e) {
                $push('database_connection', false, 'Database unavailable: '.$e->getMessage());
            }
        }

        return [
            'ready' => ! collect($checks)->contains(fn (array $check): bool => $check['status'] === 'fail'),
            'checks' => $checks,
        ];
    }
}
