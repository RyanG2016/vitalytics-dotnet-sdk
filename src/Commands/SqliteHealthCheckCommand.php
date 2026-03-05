<?php

namespace Vitalytics\Commands;

use Illuminate\Console\Command;
use Vitalytics\VitalyticsSqliteHealth;

class SqliteHealthCheckCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'vitalytics:sqlite-health
                            {--connection= : SQLite connection name (defaults to config)}
                            {--dry-run : Show metrics without sending to Vitalytics}
                            {--json : Output metrics as JSON}
                            {--integrity : Run integrity check (can be slow)}
                            {--full-integrity : Run full integrity check instead of quick check}';

    /**
     * The console command description.
     */
    protected $description = 'Check SQLite database health and report metrics to Vitalytics';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $connectionName = $this->option('connection')
            ?: config('vitalytics.database.sqlite_connection', 'sqlite');
        $dryRun = $this->option('dry-run');
        $jsonOutput = $this->option('json');
        $runIntegrity = $this->option('integrity') || $this->option('full-integrity');
        $quickCheck = !$this->option('full-integrity');

        if (!$jsonOutput) {
            $this->info("Checking SQLite health for connection: {$connectionName}");
            $this->newLine();
        }

        try {
            $health = new VitalyticsSqliteHealth($connectionName);

            if ($runIntegrity) {
                $health->enableIntegrityCheck($quickCheck);
                if (!$jsonOutput) {
                    $checkType = $quickCheck ? 'quick_check' : 'integrity_check';
                    $this->warn("Running {$checkType} (may be slow on large databases)...");
                }
            }

            $metrics = $health->check();

            if ($jsonOutput) {
                $this->line(json_encode([
                    'level' => $health->getLevel(),
                    'issues' => $health->getIssues(),
                    'metrics' => $metrics,
                ], JSON_PRETTY_PRINT));
                return Command::SUCCESS;
            }

            $this->displayMetrics($metrics, $health);

            if (!$dryRun) {
                $this->newLine();

                // Check configuration before sending
                $apiKey = config('vitalytics.api_key');
                $appSecret = config('vitalytics.app_secret');
                $appIdentifier = config('vitalytics.app_identifier');
                $baseUrl = config('vitalytics.base_url');

                if (empty($apiKey) && empty($appSecret)) {
                    $this->error('Missing authentication in .env file');
                    $this->line('Set VITALYTICS_API_KEY or VITALYTICS_APP_SECRET');
                    return Command::FAILURE;
                }

                if (empty($appIdentifier)) {
                    $this->error('Missing VITALYTICS_APP_IDENTIFIER in .env file');
                    return Command::FAILURE;
                }

                $this->info('Reporting to Vitalytics...');
                $this->line("  Base URL: {$baseUrl}");
                $this->line("  App Identifier: {$appIdentifier}");

                if ($health->reportHealth()) {
                    $this->info('Health event sent successfully.');
                } else {
                    $this->error('Failed to send health event.');
                    $this->line('Check the Laravel log for more details.');
                    return Command::FAILURE;
                }
            } else {
                $this->newLine();
                $this->warn('Dry run mode - not sending to Vitalytics');
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            if ($jsonOutput) {
                $this->line(json_encode([
                    'level' => 'crash',
                    'error' => $e->getMessage(),
                ], JSON_PRETTY_PRINT));
            } else {
                $this->error('Error checking SQLite health: ' . $e->getMessage());
            }
            return Command::FAILURE;
        }
    }

    /**
     * Display metrics in a formatted table
     */
    private function displayMetrics(array $metrics, VitalyticsSqliteHealth $health): void
    {
        if (!($metrics['connected'] ?? false)) {
            $this->error('DATABASE CONNECTION FAILED');
            $this->error('Error: ' . ($metrics['error'] ?? 'Unknown error'));
            return;
        }

        $level = $health->getLevel();
        $levelDisplay = match ($level) {
            'crash' => '<fg=red;options=bold>CRITICAL</>',
            'error' => '<fg=red>ERROR</>',
            'warning' => '<fg=yellow>WARNING</>',
            default => '<fg=green>OK</>',
        };
        $this->line("Status: {$levelDisplay}");

        $issues = $health->getIssues();
        if (!empty($issues)) {
            $this->newLine();
            $this->warn('Issues detected:');
            foreach ($issues as $issue) {
                $this->line("  - {$issue}");
            }
        }

        // Database info
        if (isset($metrics['database'])) {
            $db = $metrics['database'];
            $this->newLine();
            $this->info('Database Information');
            $this->table(
                ['Property', 'Value'],
                [
                    ['SQLite Version', $db['version'] ?? 'N/A'],
                    ['Encoding', $db['encoding'] ?? 'N/A'],
                    ['Auto Vacuum', $db['auto_vacuum'] ?? 'N/A'],
                    ['Path', $this->truncatePath($db['path'] ?? 'N/A')],
                ]
            );
        }

        // Storage
        if (isset($metrics['storage'])) {
            $s = $metrics['storage'];
            $this->info('Storage');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Database Size', $s['size_formatted'] ?? 'N/A'],
                    ['Page Count', number_format($s['page_count'] ?? 0)],
                    ['Page Size', ($s['page_size'] ?? 0) . ' bytes'],
                    ['In-Memory', ($s['is_memory'] ?? false) ? 'Yes' : 'No'],
                ]
            );
        }

        // Fragmentation
        if (isset($metrics['fragmentation'])) {
            $f = $metrics['fragmentation'];
            $needsVacuum = ($f['needs_vacuum'] ?? false) ? '<fg=yellow>Yes</>' : '<fg=green>No</>';
            $this->info('Fragmentation');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Free Pages', number_format($f['freelist_count'] ?? 0)],
                    ['Fragmentation', ($f['fragmentation_percent'] ?? 0) . '%'],
                    ['Needs VACUUM', $needsVacuum],
                ]
            );
        }

        // Cache
        if (isset($metrics['cache'])) {
            $c = $metrics['cache'];
            $this->info('Cache');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Cache Size Setting', $c['cache_size_setting'] ?? 'N/A'],
                    ['Cache Size', $c['cache_size_formatted'] ?? 'N/A'],
                ]
            );
        }

        // Journal
        if (isset($metrics['journal'])) {
            $j = $metrics['journal'];
            $this->info('Journal');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Journal Mode', $j['mode'] ?? 'N/A'],
                    ['Synchronous', $j['synchronous'] ?? 'N/A'],
                ]
            );
        }

        // WAL (if in WAL mode)
        if (isset($metrics['wal'])) {
            $w = $metrics['wal'];
            $this->info('WAL Status');
            $walExists = ($w['wal_file_exists'] ?? false) ? '<fg=green>Yes</>' : '<fg=gray>No</>';
            $this->table(
                ['Metric', 'Value'],
                [
                    ['WAL File Exists', $walExists],
                    ['WAL Size', $w['wal_size_formatted'] ?? 'N/A'],
                    ['Checkpoint Blocked', $w['checkpoint_blocked'] ?? 'N/A'],
                    ['Log Frames', $w['checkpoint_log_frames'] ?? 'N/A'],
                    ['Checkpointed', $w['checkpoint_checkpointed'] ?? 'N/A'],
                ]
            );
        }

        // Tables
        if (isset($metrics['tables']) && !isset($metrics['tables']['error'])) {
            $t = $metrics['tables'];
            $this->info('Table Statistics');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Table Count', $t['table_count'] ?? 0],
                    ['Index Count', $t['index_count'] ?? 0],
                    ['Total Rows', number_format($t['total_rows'] ?? 0)],
                    ['Largest Table', $t['largest_table'] ?? 'N/A'],
                    ['Largest Table Rows', number_format($t['largest_table_rows'] ?? 0)],
                ]
            );
        }

        // Integrity (if run)
        if (isset($metrics['integrity'])) {
            $i = $metrics['integrity'];
            $passed = ($i['passed'] ?? false) ? '<fg=green>PASSED</>' : '<fg=red>FAILED</>';
            $this->info('Integrity Check');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Status', $passed],
                    ['Check Type', $i['check_type'] ?? 'N/A'],
                    ['Duration', ($i['duration_ms'] ?? 0) . 'ms'],
                ]
            );

            if (!empty($i['errors'])) {
                $this->error('Integrity Errors:');
                foreach (array_slice($i['errors'], 0, 10) as $error) {
                    $this->line("  - {$error}");
                }
                if (count($i['errors']) > 10) {
                    $this->line("  ... and " . (count($i['errors']) - 10) . " more");
                }
            }
        }
    }

    /**
     * Truncate long paths for display
     */
    private function truncatePath(string $path): string
    {
        if (strlen($path) <= 50) {
            return $path;
        }
        return '...' . substr($path, -47);
    }
}
