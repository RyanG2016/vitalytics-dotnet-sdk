<?php

namespace Vitalytics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Vitalytics Database Health Monitoring
 *
 * Monitors MariaDB/MySQL database health including connection pool,
 * query performance, buffer pool, and locks.
 */
class VitalyticsDatabaseHealth
{
    private string $connectionName;
    private ?array $lastMetrics = null;
    private ?string $lastLevel = null;
    private array $issues = [];

    /**
     * Configurable thresholds
     */
    private array $thresholds = [
        'connection_usage_warning' => 0.7,
        'connection_usage_critical' => 0.9,
        'slow_queries_warning' => 10,
        'slow_queries_critical' => 50,
        'buffer_pool_usage_warning' => 0.85,
        'buffer_pool_usage_critical' => 0.95,
        'lock_wait_warning' => 5,
        'lock_wait_critical' => 20,
        'aborted_connects_warning' => 10,
        'aborted_connects_critical' => 50,
    ];

    /**
     * Previous metric values for delta calculations
     */
    private static array $previousMetrics = [];

    public function __construct(?string $connectionName = null)
    {
        $this->connectionName = $connectionName
            ?? config('vitalytics.database.connection', 'mysql');

        // Load thresholds from config if available
        $configThresholds = config('vitalytics.database.thresholds', []);
        if (!empty($configThresholds)) {
            $this->thresholds = array_merge($this->thresholds, $configThresholds);
        }
    }

    /**
     * Set custom thresholds
     */
    public function setThresholds(array $thresholds): self
    {
        $this->thresholds = array_merge($this->thresholds, $thresholds);
        return $this;
    }

    /**
     * Run all health checks and return metrics
     */
    public function check(): array
    {
        $this->issues = [];
        $this->lastLevel = 'info';

        try {
            $connectionCheck = $this->checkConnection();

            if (!$connectionCheck['connected']) {
                $this->lastLevel = 'crash';
                $this->lastMetrics = [
                    'connected' => false,
                    'error' => $connectionCheck['error'],
                    'connection_name' => $this->connectionName,
                    'host' => config("database.connections.{$this->connectionName}.host"),
                    'port' => config("database.connections.{$this->connectionName}.port"),
                ];
                return $this->lastMetrics;
            }

            $metrics = [
                'connected' => true,
                'connection_name' => $this->connectionName,
                'connections' => $this->checkConnectionPool(),
                'performance' => $this->checkQueryPerformance(),
                'memory' => $this->checkBufferPool(),
                'locks' => $this->checkLocks(),
                'server' => $this->getServerInfo(),
            ];

            $replication = $this->checkReplication();
            if ($replication !== null) {
                $metrics['replication'] = $replication;
            }

            $this->lastMetrics = $metrics;
            return $metrics;

        } catch (\Exception $e) {
            $this->lastLevel = 'crash';
            $this->issues[] = 'Database check failed: ' . $e->getMessage();
            $this->lastMetrics = [
                'connected' => false,
                'error' => $e->getMessage(),
                'connection_name' => $this->connectionName,
            ];
            return $this->lastMetrics;
        }
    }

    /**
     * Report health metrics to Vitalytics
     */
    public function reportHealth(): bool
    {
        if ($this->lastMetrics === null) {
            $this->check();
        }

        $level = $this->lastLevel;
        $message = $this->getHealthMessage();

        try {
            $vitalytics = Vitalytics::instance();

            // Check if Vitalytics is properly configured
            if (!$vitalytics->isEnabled()) {
                Log::warning('VitalyticsDatabaseHealth: Health monitoring is disabled');
                return false;
            }

            // Check if we have required configuration (api_key OR app_secret)
            $hasApiKey = !empty(config('vitalytics.api_key'));
            $hasAppSecret = !empty(config('vitalytics.app_secret'));

            if (!$hasApiKey && !$hasAppSecret) {
                Log::error('VitalyticsDatabaseHealth: Missing authentication - set VITALYTICS_API_KEY or VITALYTICS_APP_SECRET in .env');
                return false;
            }

            if (empty(config('vitalytics.app_identifier'))) {
                Log::error('VitalyticsDatabaseHealth: Missing VITALYTICS_APP_IDENTIFIER in .env');
                return false;
            }

            // Verify Vitalytics has an API key (either direct or fetched via app_secret)
            if (!$vitalytics->isConfigured()) {
                Log::error('VitalyticsDatabaseHealth: Vitalytics client not configured - API key fetch may have failed');
                return false;
            }

            $metadata = [
                'type' => 'database_health',
                'database' => 'mariadb',
                'metrics' => $this->lastMetrics,
            ];

            if (!empty($this->issues)) {
                $metadata['issues'] = $this->issues;
            }

            $result = false;
            switch ($level) {
                case 'crash':
                    $result = $vitalytics->crash($message, null, $metadata);
                    break;
                case 'error':
                    $result = $vitalytics->error($message, $metadata);
                    break;
                case 'warning':
                    $result = $vitalytics->warning($message, $metadata);
                    break;
                default:
                    $result = $vitalytics->info($message, $metadata);
            }

            if (!$result) {
                Log::error('VitalyticsDatabaseHealth: Failed to send event - check API key and app identifier');
            }

            return $result;

        } catch (\Exception $e) {
            Log::error('VitalyticsDatabaseHealth: Failed to report', [
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get metrics without reporting
     */
    public function getMetrics(): ?array
    {
        return $this->lastMetrics;
    }

    /**
     * Get detected issues
     */
    public function getIssues(): array
    {
        return $this->issues;
    }

    /**
     * Get the determined health level
     */
    public function getLevel(): ?string
    {
        return $this->lastLevel;
    }

    /**
     * Check basic database connectivity
     */
    private function checkConnection(): array
    {
        try {
            DB::connection($this->connectionName)->select('SELECT 1');
            return ['connected' => true, 'error' => null];
        } catch (\Exception $e) {
            return [
                'connected' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check connection pool metrics
     */
    private function checkConnectionPool(): array
    {
        $status = $this->getStatusValues([
            'Threads_connected',
            'Threads_running',
            'Threads_cached',
            'Aborted_connects',
            'Connections',
        ]);

        $variables = $this->getVariableValues(['max_connections']);

        $current = (int) ($status['Threads_connected'] ?? 0);
        $max = (int) ($variables['max_connections'] ?? 100);
        $usagePercent = $max > 0 ? round(($current / $max) * 100, 2) : 0;

        if ($usagePercent / 100 >= $this->thresholds['connection_usage_critical']) {
            $this->escalateLevel('error');
            $this->issues[] = "Critical connection usage: {$usagePercent}%";
        } elseif ($usagePercent / 100 >= $this->thresholds['connection_usage_warning']) {
            $this->escalateLevel('warning');
            $this->issues[] = "High connection usage: {$usagePercent}%";
        }

        $abortedConnects = (int) ($status['Aborted_connects'] ?? 0);
        $previousAborted = self::$previousMetrics['aborted_connects'] ?? $abortedConnects;
        $abortedDelta = $abortedConnects - $previousAborted;
        self::$previousMetrics['aborted_connects'] = $abortedConnects;

        if ($abortedDelta >= $this->thresholds['aborted_connects_critical']) {
            $this->escalateLevel('error');
            $this->issues[] = "Critical aborted connections: {$abortedDelta} since last check";
        } elseif ($abortedDelta >= $this->thresholds['aborted_connects_warning']) {
            $this->escalateLevel('warning');
            $this->issues[] = "High aborted connections: {$abortedDelta} since last check";
        }

        return [
            'current' => $current,
            'max' => $max,
            'usage_percent' => $usagePercent,
            'threads_running' => (int) ($status['Threads_running'] ?? 0),
            'threads_cached' => (int) ($status['Threads_cached'] ?? 0),
            'aborted_connects' => $abortedConnects,
            'aborted_connects_delta' => $abortedDelta,
            'total_connections' => (int) ($status['Connections'] ?? 0),
        ];
    }

    /**
     * Check query performance metrics
     */
    private function checkQueryPerformance(): array
    {
        $status = $this->getStatusValues([
            'Slow_queries',
            'Questions',
            'Uptime',
            'Com_select',
            'Com_insert',
            'Com_update',
            'Com_delete',
        ]);

        $uptime = (int) ($status['Uptime'] ?? 1);
        $questions = (int) ($status['Questions'] ?? 0);
        $qps = $uptime > 0 ? round($questions / $uptime, 2) : 0;

        $slowQueries = (int) ($status['Slow_queries'] ?? 0);
        $previousSlowQueries = self::$previousMetrics['slow_queries'] ?? $slowQueries;
        $slowQueriesDelta = $slowQueries - $previousSlowQueries;
        self::$previousMetrics['slow_queries'] = $slowQueries;

        if ($slowQueriesDelta >= $this->thresholds['slow_queries_critical']) {
            $this->escalateLevel('error');
            $this->issues[] = "Critical slow query count: {$slowQueriesDelta} since last check";
        } elseif ($slowQueriesDelta >= $this->thresholds['slow_queries_warning']) {
            $this->escalateLevel('warning');
            $this->issues[] = "Slow queries detected: {$slowQueriesDelta} since last check";
        }

        return [
            'slow_queries_total' => $slowQueries,
            'slow_queries_delta' => $slowQueriesDelta,
            'questions_total' => $questions,
            'questions_per_sec' => $qps,
            'uptime_seconds' => $uptime,
            'selects' => (int) ($status['Com_select'] ?? 0),
            'inserts' => (int) ($status['Com_insert'] ?? 0),
            'updates' => (int) ($status['Com_update'] ?? 0),
            'deletes' => (int) ($status['Com_delete'] ?? 0),
        ];
    }

    /**
     * Check InnoDB buffer pool metrics
     */
    private function checkBufferPool(): array
    {
        $status = $this->getStatusValues([
            'Innodb_buffer_pool_bytes_data',
            'Innodb_buffer_pool_bytes_dirty',
            'Innodb_buffer_pool_pages_total',
            'Innodb_buffer_pool_pages_data',
            'Innodb_buffer_pool_pages_dirty',
            'Innodb_buffer_pool_pages_free',
            'Innodb_buffer_pool_read_requests',
            'Innodb_buffer_pool_reads',
        ]);

        $pagesTotal = (int) ($status['Innodb_buffer_pool_pages_total'] ?? 0);
        $pagesFree = (int) ($status['Innodb_buffer_pool_pages_free'] ?? 0);
        $pagesUsed = $pagesTotal - $pagesFree;
        $usagePercent = $pagesTotal > 0 ? round(($pagesUsed / $pagesTotal) * 100, 2) : 0;

        $readRequests = (int) ($status['Innodb_buffer_pool_read_requests'] ?? 0);
        $reads = (int) ($status['Innodb_buffer_pool_reads'] ?? 0);
        $hitRatio = $readRequests > 0 ? round((($readRequests - $reads) / $readRequests) * 100, 2) : 100;

        if ($usagePercent / 100 >= $this->thresholds['buffer_pool_usage_critical']) {
            $this->escalateLevel('error');
            $this->issues[] = "Critical buffer pool usage: {$usagePercent}%";
        } elseif ($usagePercent / 100 >= $this->thresholds['buffer_pool_usage_warning']) {
            $this->escalateLevel('warning');
            $this->issues[] = "High buffer pool usage: {$usagePercent}%";
        }

        return [
            'bytes_data' => (int) ($status['Innodb_buffer_pool_bytes_data'] ?? 0),
            'bytes_dirty' => (int) ($status['Innodb_buffer_pool_bytes_dirty'] ?? 0),
            'pages_total' => $pagesTotal,
            'pages_used' => $pagesUsed,
            'pages_free' => $pagesFree,
            'pages_dirty' => (int) ($status['Innodb_buffer_pool_pages_dirty'] ?? 0),
            'usage_percent' => $usagePercent,
            'hit_ratio' => $hitRatio,
        ];
    }

    /**
     * Check lock metrics
     */
    private function checkLocks(): array
    {
        $status = $this->getStatusValues([
            'Table_locks_waited',
            'Table_locks_immediate',
            'Innodb_row_lock_waits',
            'Innodb_row_lock_time',
            'Innodb_row_lock_time_avg',
        ]);

        $locksWaited = (int) ($status['Table_locks_waited'] ?? 0);
        $previousLocksWaited = self::$previousMetrics['table_locks_waited'] ?? $locksWaited;
        $locksWaitedDelta = $locksWaited - $previousLocksWaited;
        self::$previousMetrics['table_locks_waited'] = $locksWaited;

        if ($locksWaitedDelta >= $this->thresholds['lock_wait_critical']) {
            $this->escalateLevel('error');
            $this->issues[] = "Critical table lock waits: {$locksWaitedDelta} since last check";
        } elseif ($locksWaitedDelta >= $this->thresholds['lock_wait_warning']) {
            $this->escalateLevel('warning');
            $this->issues[] = "Table lock waits detected: {$locksWaitedDelta} since last check";
        }

        return [
            'table_locks_waited' => $locksWaited,
            'table_locks_waited_delta' => $locksWaitedDelta,
            'table_locks_immediate' => (int) ($status['Table_locks_immediate'] ?? 0),
            'row_lock_waits' => (int) ($status['Innodb_row_lock_waits'] ?? 0),
            'row_lock_time_ms' => (int) ($status['Innodb_row_lock_time'] ?? 0),
            'row_lock_time_avg_ms' => (int) ($status['Innodb_row_lock_time_avg'] ?? 0),
        ];
    }

    /**
     * Check replication status (if server is a replica)
     */
    private function checkReplication(): ?array
    {
        try {
            $result = DB::connection($this->connectionName)
                ->select('SHOW SLAVE STATUS');

            if (empty($result)) {
                return null;
            }

            $status = (array) $result[0];

            $ioRunning = $status['Slave_IO_Running'] ?? 'No';
            $sqlRunning = $status['Slave_SQL_Running'] ?? 'No';
            $secondsBehind = $status['Seconds_Behind_Master'] ?? null;

            if ($ioRunning !== 'Yes' || $sqlRunning !== 'Yes') {
                $this->escalateLevel('error');
                $this->issues[] = 'Replication not running (IO: ' . $ioRunning . ', SQL: ' . $sqlRunning . ')';
            } elseif ($secondsBehind !== null && $secondsBehind > 60) {
                $this->escalateLevel('warning');
                $this->issues[] = "Replication lag: {$secondsBehind} seconds";
            }

            return [
                'io_running' => $ioRunning === 'Yes',
                'sql_running' => $sqlRunning === 'Yes',
                'seconds_behind_master' => $secondsBehind,
                'master_host' => $status['Master_Host'] ?? null,
                'last_error' => $status['Last_Error'] ?? null,
            ];

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get basic server information
     */
    private function getServerInfo(): array
    {
        $variables = $this->getVariableValues([
            'version',
            'version_comment',
            'innodb_buffer_pool_size',
            'long_query_time',
        ]);

        return [
            'version' => $variables['version'] ?? 'unknown',
            'version_comment' => $variables['version_comment'] ?? '',
            'buffer_pool_size_bytes' => (int) ($variables['innodb_buffer_pool_size'] ?? 0),
            'slow_query_threshold_sec' => (float) ($variables['long_query_time'] ?? 10),
        ];
    }

    /**
     * Get status values from SHOW STATUS
     */
    private function getStatusValues(array $names): array
    {
        $placeholders = implode(',', array_fill(0, count($names), '?'));

        $results = DB::connection($this->connectionName)
            ->select("SHOW STATUS WHERE Variable_name IN ({$placeholders})", $names);

        $values = [];
        foreach ($results as $row) {
            $values[$row->Variable_name] = $row->Value;
        }

        return $values;
    }

    /**
     * Get variable values from SHOW VARIABLES
     */
    private function getVariableValues(array $names): array
    {
        $placeholders = implode(',', array_fill(0, count($names), '?'));

        $results = DB::connection($this->connectionName)
            ->select("SHOW VARIABLES WHERE Variable_name IN ({$placeholders})", $names);

        $values = [];
        foreach ($results as $row) {
            $values[$row->Variable_name] = $row->Value;
        }

        return $values;
    }

    /**
     * Escalate the health level if necessary
     */
    private function escalateLevel(string $newLevel): void
    {
        $levels = ['info' => 0, 'warning' => 1, 'error' => 2, 'crash' => 3];

        $currentPriority = $levels[$this->lastLevel] ?? 0;
        $newPriority = $levels[$newLevel] ?? 0;

        if ($newPriority > $currentPriority) {
            $this->lastLevel = $newLevel;
        }
    }

    /**
     * Generate health message based on level and issues
     */
    private function getHealthMessage(): string
    {
        $prefix = match($this->lastLevel) {
            'crash' => 'Database connection failed',
            'error' => 'Database health check: CRITICAL',
            'warning' => 'Database health check: WARNING',
            default => 'Database health check: OK',
        };

        if ($this->lastLevel === 'crash' && isset($this->lastMetrics['error'])) {
            return $prefix . ': ' . $this->lastMetrics['error'];
        }

        if (!empty($this->issues)) {
            return $prefix . ' - ' . implode('; ', array_slice($this->issues, 0, 3));
        }

        return $prefix;
    }
}
