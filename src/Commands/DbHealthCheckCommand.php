<?php

namespace Vitalytics\Commands;

use Illuminate\Console\Command;
use Vitalytics\VitalyticsDatabaseHealth;

class DbHealthCheckCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'vitalytics:db-health
                            {--connection= : Database connection name (defaults to config)}
                            {--dry-run : Show metrics without sending to Vitalytics}
                            {--json : Output metrics as JSON}';

    /**
     * The console command description.
     */
    protected $description = 'Check database health and report metrics to Vitalytics';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $connectionName = $this->option('connection')
            ?: config('vitalytics.database.connection', 'mysql');
        $dryRun = $this->option('dry-run');
        $jsonOutput = $this->option('json');

        if (!$jsonOutput) {
            $this->info("Checking database health for connection: {$connectionName}");
            $this->newLine();
        }

        try {
            $health = new VitalyticsDatabaseHealth($connectionName);
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

                // Also check env variables directly (in case config isn't published)
                $envApiKey = env('VITALYTICS_API_KEY');
                $envAppSecret = env('VITALYTICS_APP_SECRET');

                if (empty($apiKey) && empty($appSecret)) {
                    // Check if env vars exist but config doesn't have them
                    if (!empty($envAppSecret) || !empty($envApiKey)) {
                        $this->error('Config file is missing app_secret key!');
                        $this->newLine();
                        $this->line('Your .env has the credentials, but the config file is outdated.');
                        $this->line('Run: php artisan vendor:publish --tag=vitalytics-config --force');
                        $this->line('Then: php artisan config:clear');
                        return Command::FAILURE;
                    }

                    $this->error('Missing authentication in .env file');
                    $this->line('The database monitor uses the same credentials as health monitoring.');
                    $this->newLine();
                    $this->line('Option 1 - Use App Secret (recommended):');
                    $this->line('  VITALYTICS_APP_SECRET=your-app-secret');
                    $this->newLine();
                    $this->line('Option 2 - Use API Key directly:');
                    $this->line('  VITALYTICS_API_KEY=your-api-key');
                    $this->newLine();
                    $this->line('Also required:');
                    $this->line('  VITALYTICS_BASE_URL=https://your-vitalytics-server.com');
                    $this->line('  VITALYTICS_APP_IDENTIFIER=your-app-identifier');
                    $this->newLine();
                    $this->warn('Debug: Checking config values...');
                    $this->line('  config(vitalytics.api_key): ' . (empty($apiKey) ? '<not set>' : 'set'));
                    $this->line('  config(vitalytics.app_secret): ' . (empty($appSecret) ? '<not set>' : 'set'));
                    $this->line('  env(VITALYTICS_API_KEY): ' . (empty($envApiKey) ? '<not set>' : 'set'));
                    $this->line('  env(VITALYTICS_APP_SECRET): ' . (empty($envAppSecret) ? '<not set>' : 'set'));
                    return Command::FAILURE;
                }

                if (empty($appIdentifier)) {
                    $this->error('Missing VITALYTICS_APP_IDENTIFIER in .env file');
                    return Command::FAILURE;
                }

                $this->info('Reporting to Vitalytics...');
                $this->line("  Base URL: {$baseUrl}");
                $this->line("  App Identifier: {$appIdentifier}");
                if (!empty($apiKey)) {
                    $this->line("  Auth: API Key (" . substr($apiKey, 0, 8) . '...)');
                } else {
                    $this->line("  Auth: App Secret (fetching API key dynamically)");
                }

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
                $this->error('Error checking database health: ' . $e->getMessage());
            }
            return Command::FAILURE;
        }
    }

    /**
     * Display metrics in a formatted table
     */
    private function displayMetrics(array $metrics, VitalyticsDatabaseHealth $health): void
    {
        if (!($metrics['connected'] ?? false)) {
            $this->error('DATABASE CONNECTION FAILED');
            $this->error('Error: ' . ($metrics['error'] ?? 'Unknown error'));
            return;
        }

        $level = $health->getLevel();
        $levelDisplay = match($level) {
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

        // Server info
        if (isset($metrics['server'])) {
            $this->newLine();
            $this->info('Server Information');
            $this->table(
                ['Property', 'Value'],
                [
                    ['Version', $metrics['server']['version'] ?? 'N/A'],
                    ['Type', $metrics['server']['version_comment'] ?? 'N/A'],
                    ['Buffer Pool Size', $this->formatBytes($metrics['server']['buffer_pool_size_bytes'] ?? 0)],
                    ['Slow Query Threshold', ($metrics['server']['slow_query_threshold_sec'] ?? 10) . 's'],
                ]
            );
        }

        // Connection pool
        if (isset($metrics['connections'])) {
            $c = $metrics['connections'];
            $this->info('Connection Pool');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Current Connections', $c['current'] ?? 0],
                    ['Max Connections', $c['max'] ?? 0],
                    ['Usage', ($c['usage_percent'] ?? 0) . '%'],
                    ['Running Threads', $c['threads_running'] ?? 0],
                    ['Cached Threads', $c['threads_cached'] ?? 0],
                    ['Aborted (delta)', $c['aborted_connects_delta'] ?? 0],
                    ['Total Connections', $c['total_connections'] ?? 0],
                ]
            );
        }

        // Query performance
        if (isset($metrics['performance'])) {
            $p = $metrics['performance'];
            $this->info('Query Performance');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Uptime', $this->formatDuration($p['uptime_seconds'] ?? 0)],
                    ['Questions/sec', $p['questions_per_sec'] ?? 0],
                    ['Slow Queries (total)', $p['slow_queries_total'] ?? 0],
                    ['Slow Queries (delta)', $p['slow_queries_delta'] ?? 0],
                    ['SELECT', number_format($p['selects'] ?? 0)],
                    ['INSERT', number_format($p['inserts'] ?? 0)],
                    ['UPDATE', number_format($p['updates'] ?? 0)],
                    ['DELETE', number_format($p['deletes'] ?? 0)],
                ]
            );
        }

        // Buffer pool
        if (isset($metrics['memory'])) {
            $m = $metrics['memory'];
            $this->info('InnoDB Buffer Pool');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Data Size', $this->formatBytes($m['bytes_data'] ?? 0)],
                    ['Dirty Pages', $this->formatBytes($m['bytes_dirty'] ?? 0)],
                    ['Pages Total', number_format($m['pages_total'] ?? 0)],
                    ['Pages Used', number_format($m['pages_used'] ?? 0)],
                    ['Pages Free', number_format($m['pages_free'] ?? 0)],
                    ['Usage', ($m['usage_percent'] ?? 0) . '%'],
                    ['Hit Ratio', ($m['hit_ratio'] ?? 100) . '%'],
                ]
            );
        }

        // Locks
        if (isset($metrics['locks'])) {
            $l = $metrics['locks'];
            $this->info('Lock Statistics');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Table Locks Waited (total)', $l['table_locks_waited'] ?? 0],
                    ['Table Locks Waited (delta)', $l['table_locks_waited_delta'] ?? 0],
                    ['Table Locks Immediate', number_format($l['table_locks_immediate'] ?? 0)],
                    ['Row Lock Waits', number_format($l['row_lock_waits'] ?? 0)],
                    ['Avg Row Lock Time', ($l['row_lock_time_avg_ms'] ?? 0) . 'ms'],
                ]
            );
        }

        // Replication
        if (isset($metrics['replication'])) {
            $r = $metrics['replication'];
            $this->info('Replication Status');
            $ioStatus = ($r['io_running'] ?? false) ? '<fg=green>Running</>' : '<fg=red>Stopped</>';
            $sqlStatus = ($r['sql_running'] ?? false) ? '<fg=green>Running</>' : '<fg=red>Stopped</>';
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Master Host', $r['master_host'] ?? 'N/A'],
                    ['IO Thread', $ioStatus],
                    ['SQL Thread', $sqlStatus],
                    ['Seconds Behind Master', $r['seconds_behind_master'] ?? 'N/A'],
                    ['Last Error', $r['last_error'] ?? 'None'],
                ]
            );
        }
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) return '0 B';

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);

        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }

    /**
     * Format seconds to human readable duration
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) return "{$seconds}s";
        if ($seconds < 3600) return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
        if ($seconds < 86400) return floor($seconds / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'm';
        return floor($seconds / 86400) . 'd ' . floor(($seconds % 86400) / 3600) . 'h';
    }
}
