<?php

namespace App\Console\Commands;

use App\Services\ReleaseReadinessService;
use Illuminate\Console\Command;

class ReleaseCheck extends Command
{
    protected $signature = 'nadi:release-check
        {--production : Enforce production-only requirements}
        {--skip-db : Skip database connectivity and migration checks}
        {--skip-build : Skip Vite manifest/assets check}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Validate NADI runtime, production configuration, database migrations, and frontend build before release.';

    public function handle(ReleaseReadinessService $readiness): int
    {
        $result = $readiness->evaluate(
            production: (bool) $this->option('production'),
            includeDatabase: ! $this->option('skip-db'),
            includeBuild: ! $this->option('skip-build'),
        );

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $result['ready'] ? self::SUCCESS : self::FAILURE;
        }

        $this->newLine();
        $this->line('<info>NADI release readiness</info>');
        foreach ($result['checks'] as $check) {
            $mark = $check['status'] === 'pass' ? '<info>PASS</info>' : '<error>FAIL</error>';
            $this->line(sprintf('%-6s %-28s %s', strip_tags($mark), $check['name'], $check['message']));
        }
        $this->newLine();
        $result['ready'] ? $this->info('Release readiness: PASS') : $this->error('Release readiness: FAIL');

        return $result['ready'] ? self::SUCCESS : self::FAILURE;
    }
}
