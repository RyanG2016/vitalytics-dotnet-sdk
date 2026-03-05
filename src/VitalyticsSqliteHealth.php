<?php

namespace Vitalytics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Vitalytics SQLite Database Health Monitoring
 *
 * Monitors SQLite database health including file size, fragmentation,
 * cache statistics, WAL status, and integrity.
 */
class VitalyticsSqliteHealth
{
    private string $connectionName;
    private ?array $lastMetrics = null;
    private ?string $lastLevel = null;
    private array $issues = [];

    /**
     * Configurable thresholds
     */
    private array $thresholds = [
        // Fragmentation: free pages as percentage of total pages
        'fragmentation_warning' => 0.10,      // 10% free pages
        'fragmentation_critical' => 0.25,     // 25% free pages
        // Database file size in bytes
        'file_size_warning' => 500 * 1024 * 1024,   // 500 MB
        'file_size_critical' => 1024 * 1024 * 1024, // 1 GB
        // WAL file size (if in WAL mode)
        'wal_size_warning' => 50 * 1024 * 1024,     // 50 MB
        'wal_size_critical' => 100 * 1024 * 1024,   // 100 MB
    ];

    /**
     * Whether to run integrity check (can be slow on large databases)
     */
    private bool $runIntegrityCheck = false;

    /**
     * Use quick_check instead of integrity_check
     */
    private bool $useQuickCheck = true;

    public function __construct(?string $connectionName = null)
    {
        $this->connectionName = $connectionName
            ?? config('vitalytics.database.sqlite_connection', 'sqlite');

        // Load thresholds from config if available
        $configThresholds = config('vitalytics.database.sqlite_thresholds', []);
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
     * Enable integrity check (warning: can be slow on large databases)
     */
    public function enableIntegrityCheck(bool $quick = true): self
    {
        $this->runIntegrityCheck = true;
        $this->useQuickCheck = $quick;
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
                    'database_path' => config("database.connections.{$this->connectionName}.database"),
                ];
                return $this->lastMetrics;
            }

            $metrics = [
                'connected' => true,
                'connection_name' => $this->connectionName,
                'database' => $this->getDatabaseInfo(),
                'storage' => $this->checkStorage(),
                'fragmentation' => $this->checkFragmentation(),
                'cache' => $this->getCacheInfo(),
                'journal' => $this->getJournalInfo(),
                'tables' => $this->getTableStats(),
            ];

            // Optional integrity check
            if ($this->runIntegrityCheck) {
                $metrics['integrity'] = $this->checkIntegrity();
            }

            // WAL-specific metrics
            $journalMode = $metrics['journal']['mode'] ?? '';
            if (strtolower($journalMode) === 'wal') {
                $metrics['wal'] = $this->checkWalStatus();
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

            if (!$vitalytics->isEnabled()) {
                Log::warning('VitalyticsSqliteHealth: Health monitoring is disabled');
                return false;
            }

            $hasApiKey = !empty(config('vitalytics.api_key'));
            $hasAppSecret = !empty(config('vitalytics.app_secret'));

            if (!$hasApiKey && !$hasAppSecret) {
                Log::error('VitalyticsSqliteHealth: Missing authentication - set VITALYTICS_API_KEY or VITALYTICS_APP_SECRET in .env');
                return false;
            }

            if (empty(config('vitalytics.app_identifier'))) {
                Log::error('VitalyticsSqliteHealth: Missing VITALYTICS_APP_IDENTIFIER in .env');
                return false;
            }

            if (!$vitalytics->isConfigured()) {
                Log::error('VitalyticsSqliteHealth: Vitalytics client not configured - API key fetch may have failed');
                return false;
            }

            $metadata = [
                'type' => 'database_health',
                'database' => 'sqlite',
                'metrics' => $this->lastMetrics,
            ];

            if (!empty($this->issues)) {
                $metadata['issues'] = $this->issues;
            }

            $result = match ($level) {
                'crash' => $vitalytics->crash($message, null, $metadata),
                'error' => $vitalytics->error($message, $metadata),
                'warning' => $vitalytics->warning($message, $metadata),
                default => $vitalytics->info($message, $metadata),
            };

            if (!$result) {
                Log::error('VitalyticsSqliteHealth: Failed to send event - check API key and app identifier');
            }

            return $result;

        } catch (\Exception $e) {
            Log::error('VitalyticsSqliteHealth: Failed to report', [
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
     * Get basic database information
     */
    private function getDatabaseInfo(): array
    {
        $version = $this->getPragmaValue('sqlite_version');
        $encoding = $this->getPragmaValue('encoding');
        $autoVacuum = $this->getPragmaValue('auto_vacuum');

        $autoVacuumMode = match ((int) $autoVacuum) {
            0 => 'none',
            1 => 'full',
            2 => 'incremental',
            default => 'unknown',
        };

        // Get database file path
        $dbList = DB::connection($this->connectionName)
            ->select('PRAGMA database_list');
        $mainDb = collect($dbList)->firstWhere('name', 'main');
        $dbPath = $mainDb->file ?? config("database.connections.{$this->connectionName}.database");

        return [
            'version' => $version,
            'encoding' => $encoding,
            'auto_vacuum' => $autoVacuumMode,
            'path' => $dbPath,
        ];
    }

    /**
     * Check storage metrics (file size, page count, etc.)
     */
    private function checkStorage(): array
    {
        $pageCount = (int) $this->getPragmaValue('page_count');
        $pageSize = (int) $this->getPragmaValue('page_size');
        $calculatedSize = $pageCount * $pageSize;

        // Get actual file size if possible
        $dbPath = config("database.connections.{$this->connectionName}.database");
        $actualSize = null;
        if ($dbPath && $dbPath !== ':memory:' && file_exists($dbPath)) {
            $actualSize = filesize($dbPath);
        }

        $fileSize = $actualSize ?? $calculatedSize;

        // Check file size thresholds
        if ($fileSize >= $this->thresholds['file_size_critical']) {
            $this->escalateLevel('error');
            $this->issues[] = 'Critical database size: ' . $this->formatBytes($fileSize);
        } elseif ($fileSize >= $this->thresholds['file_size_warning']) {
            $this->escalateLevel('warning');
            $this->issues[] = 'Large database size: ' . $this->formatBytes($fileSize);
        }

        return [
            'page_count' => $pageCount,
            'page_size' => $pageSize,
            'calculated_size_bytes' => $calculatedSize,
            'actual_size_bytes' => $actualSize,
            'size_formatted' => $this->formatBytes($fileSize),
            'is_memory' => $dbPath === ':memory:',
        ];
    }

    /**
     * Check fragmentation (free pages)
     */
    private function checkFragmentation(): array
    {
        $pageCount = (int) $this->getPragmaValue('page_count');
        $freelistCount = (int) $this->getPragmaValue('freelist_count');

        $fragmentationPercent = $pageCount > 0
            ? round(($freelistCount / $pageCount) * 100, 2)
            : 0;

        $fragmentationRatio = $fragmentationPercent / 100;

        if ($fragmentationRatio >= $this->thresholds['fragmentation_critical']) {
            $this->escalateLevel('error');
            $this->issues[] = "Critical fragmentation: {$fragmentationPercent}% free pages - consider VACUUM";
        } elseif ($fragmentationRatio >= $this->thresholds['fragmentation_warning']) {
            $this->escalateLevel('warning');
            $this->issues[] = "High fragmentation: {$fragmentationPercent}% free pages";
        }

        return [
            'freelist_count' => $freelistCount,
            'fragmentation_percent' => $fragmentationPercent,
            'needs_vacuum' => $fragmentationRatio >= $this->thresholds['fragmentation_warning'],
        ];
    }

    /**
     * Get cache information
     */
    private function getCacheInfo(): array
    {
        $cacheSize = (int) $this->getPragmaValue('cache_size');
        $pageSize = (int) $this->getPragmaValue('page_size');

        // Negative cache_size means KB, positive means pages
        if ($cacheSize < 0) {
            $cacheSizeBytes = abs($cacheSize) * 1024;
        } else {
            $cacheSizeBytes = $cacheSize * $pageSize;
        }

        return [
            'cache_size_setting' => $cacheSize,
            'cache_size_bytes' => $cacheSizeBytes,
            'cache_size_formatted' => $this->formatBytes($cacheSizeBytes),
        ];
    }

    /**
     * Get journal mode information
     */
    private function getJournalInfo(): array
    {
        $journalMode = $this->getPragmaValue('journal_mode');
        $synchronous = $this->getPragmaValue('synchronous');

        $synchronousMode = match ((int) $synchronous) {
            0 => 'OFF',
            1 => 'NORMAL',
            2 => 'FULL',
            3 => 'EXTRA',
            default => 'unknown',
        };

        return [
            'mode' => strtoupper($journalMode),
            'synchronous' => $synchronousMode,
        ];
    }

    /**
     * Check WAL status (only when in WAL mode)
     */
    private function checkWalStatus(): array
    {
        $walPath = config("database.connections.{$this->connectionName}.database") . '-wal';
        $walSize = null;

        if (file_exists($walPath)) {
            $walSize = filesize($walPath);

            if ($walSize >= $this->thresholds['wal_size_critical']) {
                $this->escalateLevel('error');
                $this->issues[] = 'Critical WAL file size: ' . $this->formatBytes($walSize) . ' - checkpoint needed';
            } elseif ($walSize >= $this->thresholds['wal_size_warning']) {
                $this->escalateLevel('warning');
                $this->issues[] = 'Large WAL file: ' . $this->formatBytes($walSize);
            }
        }

        // Get WAL checkpoint info
        try {
            $checkpoint = DB::connection($this->connectionName)
                ->select('PRAGMA wal_checkpoint(PASSIVE)');
            $checkpointInfo = $checkpoint[0] ?? null;
        } catch (\Exception $e) {
            $checkpointInfo = null;
        }

        return [
            'wal_file_exists' => file_exists($walPath),
            'wal_size_bytes' => $walSize,
            'wal_size_formatted' => $walSize ? $this->formatBytes($walSize) : null,
            'checkpoint_blocked' => $checkpointInfo->busy ?? null,
            'checkpoint_log_frames' => $checkpointInfo->log ?? null,
            'checkpoint_checkpointed' => $checkpointInfo->checkpointed ?? null,
        ];
    }

    /**
     * Run integrity check
     */
    private function checkIntegrity(): array
    {
        $command = $this->useQuickCheck ? 'PRAGMA quick_check' : 'PRAGMA integrity_check';

        try {
            $startTime = microtime(true);
            $results = DB::connection($this->connectionName)->select($command);
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $isOk = count($results) === 1 &&
                    isset($results[0]->integrity_check) &&
                    $results[0]->integrity_check === 'ok';

            if (!$isOk) {
                $this->escalateLevel('error');
                $errors = collect($results)->pluck('integrity_check')->toArray();
                $this->issues[] = 'Integrity check failed: ' . implode('; ', array_slice($errors, 0, 3));
            }

            return [
                'passed' => $isOk,
                'check_type' => $this->useQuickCheck ? 'quick_check' : 'integrity_check',
                'duration_ms' => $duration,
                'errors' => $isOk ? [] : collect($results)->pluck('integrity_check')->toArray(),
            ];

        } catch (\Exception $e) {
            $this->escalateLevel('warning');
            $this->issues[] = 'Integrity check error: ' . $e->getMessage();

            return [
                'passed' => false,
                'check_type' => $this->useQuickCheck ? 'quick_check' : 'integrity_check',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get table statistics
     */
    private function getTableStats(): array
    {
        try {
            // Get list of tables
            $tables = DB::connection($this->connectionName)
                ->select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");

            $tableCount = count($tables);
            $totalRows = 0;
            $largestTable = null;
            $largestTableRows = 0;

            foreach ($tables as $table) {
                try {
                    $count = DB::connection($this->connectionName)
                        ->select("SELECT COUNT(*) as cnt FROM \"{$table->name}\"");
                    $rows = $count[0]->cnt ?? 0;
                    $totalRows += $rows;

                    if ($rows > $largestTableRows) {
                        $largestTableRows = $rows;
                        $largestTable = $table->name;
                    }
                } catch (\Exception $e) {
                    // Skip tables we can't count
                }
            }

            // Get index count
            $indexes = DB::connection($this->connectionName)
                ->select("SELECT COUNT(*) as cnt FROM sqlite_master WHERE type='index'");
            $indexCount = $indexes[0]->cnt ?? 0;

            return [
                'table_count' => $tableCount,
                'index_count' => $indexCount,
                'total_rows' => $totalRows,
                'largest_table' => $largestTable,
                'largest_table_rows' => $largestTableRows,
            ];

        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get a single PRAGMA value
     */
    private function getPragmaValue(string $pragma): mixed
    {
        try {
            $result = DB::connection($this->connectionName)
                ->select("PRAGMA {$pragma}");

            if (empty($result)) {
                return null;
            }

            // PRAGMA results come as objects with the pragma name as the property
            $row = (array) $result[0];
            return reset($row);

        } catch (\Exception $e) {
            return null;
        }
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
        $prefix = match ($this->lastLevel) {
            'crash' => 'SQLite connection failed',
            'error' => 'SQLite health check: CRITICAL',
            'warning' => 'SQLite health check: WARNING',
            default => 'SQLite health check: OK',
        };

        if ($this->lastLevel === 'crash' && isset($this->lastMetrics['error'])) {
            return $prefix . ': ' . $this->lastMetrics['error'];
        }

        if (!empty($this->issues)) {
            return $prefix . ' - ' . implode('; ', array_slice($this->issues, 0, 3));
        }

        return $prefix;
    }

    /**
     * Format bytes to human-readable string
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
