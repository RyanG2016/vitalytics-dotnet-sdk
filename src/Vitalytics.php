<?php

namespace Vitalytics;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Vitalytics Health Monitoring Client
 *
 * Tracks crashes, errors, warnings, and info events in your Laravel application.
 */
class Vitalytics
{
    private static ?self $instance = null;

    private string $baseUrl;
    private ?string $apiKey = null;
    private ?string $appSecret = null;
    private string $appIdentifier;
    private string $deviceId;
    private bool $enabled = true;
    private bool $isTest = false;
    private int $timeout = 10;
    private int $retryTimes = 2;
    private int $retrySleep = 100;

    private const CACHE_KEY_API_KEY = 'vitalytics_api_key';
    private const CACHE_KEY_MAINTENANCE = 'vitalytics_maintenance';
    private const CACHE_TTL_HOURS = 20; // Cache for 20 hours (API key expires in 24)
    private const MAINTENANCE_CACHE_TTL = 300; // 5 minutes

    private function __construct()
    {
        $this->baseUrl = rtrim(config('vitalytics.base_url') ?? '', '/');
        $this->appIdentifier = config('vitalytics.app_identifier') ?? '';
        $this->deviceId = config('vitalytics.device_id') ?? $this->generateDeviceId();
        $this->enabled = config('vitalytics.health.enabled') ?? true;
        $this->isTest = config('vitalytics.health.is_test') ?? false;
        $this->timeout = config('vitalytics.http.timeout') ?? 10;
        $this->retryTimes = config('vitalytics.http.retry_times') ?? 2;
        $this->retrySleep = config('vitalytics.http.retry_sleep') ?? 100;

        // Check for API key first (direct configuration)
        $this->apiKey = config('vitalytics.api_key') ?? null;

        // If no API key, check for App Secret (dynamic key fetching)
        if (empty($this->apiKey)) {
            $this->appSecret = config('vitalytics.app_secret') ?? null;

            if ($this->appSecret) {
                // Try to get cached API key first
                $this->apiKey = $this->getCachedApiKey();

                // If no cached key, fetch it
                if (empty($this->apiKey)) {
                    $this->fetchApiKey();
                }
            }
        }
    }

    /**
     * Get the singleton instance
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Reset the singleton instance (useful for testing)
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * Fetch API key using App Secret
     */
    private function fetchApiKey(): bool
    {
        if (empty($this->appSecret) || empty($this->baseUrl) || empty($this->appIdentifier)) {
            return false;
        }

        try {
            $response = Http::withHeaders([
                'X-App-Secret' => $this->appSecret,
                'Accept' => 'application/json',
            ])
                ->timeout($this->timeout)
                ->get("{$this->baseUrl}/api/v1/auth/key/{$this->appIdentifier}");

            if ($response->successful()) {
                $data = $response->json();

                if ($data['success'] ?? false) {
                    $this->apiKey = $data['apiKey'];
                    $this->cacheApiKey($this->apiKey);
                    Log::debug('Vitalytics: API key fetched successfully');
                    return true;
                }
            }

            Log::error('Vitalytics: Failed to fetch API key', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return false;

        } catch (\Exception $e) {
            Log::error('Vitalytics: Exception fetching API key', [
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Cache the API key
     */
    private function cacheApiKey(string $apiKey): void
    {
        Cache::put(
            self::CACHE_KEY_API_KEY . '_' . $this->appIdentifier,
            $apiKey,
            now()->addHours(self::CACHE_TTL_HOURS)
        );
    }

    /**
     * Get cached API key
     */
    private function getCachedApiKey(): ?string
    {
        return Cache::get(self::CACHE_KEY_API_KEY . '_' . $this->appIdentifier);
    }

    /**
     * Clear cached API key (useful if key is rotated)
     */
    public function clearCachedApiKey(): void
    {
        Cache::forget(self::CACHE_KEY_API_KEY . '_' . $this->appIdentifier);
        $this->apiKey = null;
    }

    /**
     * Configure the client manually (alternative to config file)
     */
    public function configure(
        string $baseUrl,
        string $apiKey,
        string $appIdentifier,
        ?string $deviceId = null,
        bool $isTest = false
    ): self {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->appIdentifier = $appIdentifier;
        $this->deviceId = $deviceId ?? $this->generateDeviceId();
        $this->isTest = $isTest;
        return $this;
    }

    /**
     * Configure with App Secret (fetches API key dynamically)
     */
    public function configureWithSecret(
        string $baseUrl,
        string $appSecret,
        string $appIdentifier,
        ?string $deviceId = null,
        bool $isTest = false
    ): self {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->appSecret = $appSecret;
        $this->appIdentifier = $appIdentifier;
        $this->deviceId = $deviceId ?? $this->generateDeviceId();
        $this->isTest = $isTest;

        // Try cached key first
        $this->apiKey = $this->getCachedApiKey();
        if (empty($this->apiKey)) {
            $this->fetchApiKey();
        }

        return $this;
    }

    /**
     * Enable or disable health monitoring
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    /**
     * Check if health monitoring is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Check if properly configured (has API key)
     */
    public function isConfigured(): bool
    {
        return !empty($this->baseUrl) && !empty($this->apiKey) && !empty($this->appIdentifier);
    }

    /**
     * Report a crash (unhandled exception, fatal error)
     */
    public function crash(string $message, ?string $stackTrace = null, array $context = []): bool
    {
        return $this->sendEvent('crash', $message, array_merge($context, [
            'stack_trace' => $stackTrace,
        ]));
    }

    /**
     * Report an error (handled exception, API failure)
     */
    public function error(string $message, array $context = []): bool
    {
        return $this->sendEvent('error', $message, $context);
    }

    /**
     * Report a warning (deprecation, slow operation)
     */
    public function warning(string $message, array $context = []): bool
    {
        return $this->sendEvent('warning', $message, $context);
    }

    /**
     * Report an info event (successful operation, milestone)
     */
    public function info(string $message, array $context = []): bool
    {
        return $this->sendEvent('info', $message, $context);
    }

    /**
     * Send a heartbeat to indicate the application is running
     *
     * Use this for scheduled health checks, cron jobs, or long-running processes.
     * Heartbeats can be monitored in the dashboard to detect when services go down.
     */
    public function heartbeat(string $message = 'Application heartbeat', array $context = []): bool
    {
        return $this->sendEvent('heartbeat', $message, $context);
    }

    /**
     * Send a health event to Vitalytics
     */
    private function sendEvent(string $type, string $message, array $metadata = []): bool
    {
        if (!$this->enabled) {
            return true;
        }

        // If no API key but we have a secret, try to fetch it
        if (empty($this->apiKey) && !empty($this->appSecret)) {
            $this->fetchApiKey();
        }

        if (empty($this->baseUrl) || empty($this->apiKey) || empty($this->appIdentifier)) {
            Log::warning('Vitalytics: Missing configuration', [
                'has_base_url' => !empty($this->baseUrl),
                'has_api_key' => !empty($this->apiKey),
                'has_app_secret' => !empty($this->appSecret),
                'has_app_identifier' => !empty($this->appIdentifier),
            ]);
            return false;
        }

        // Build payload in the format expected by the API
        $eventId = (string) Str::uuid();
        $timestamp = now()->utc()->toIso8601String();

        // Extract and format stack trace (API expects array or null)
        $stackTrace = null;
        if (isset($metadata['stack_trace']) && $metadata['stack_trace']) {
            $stackTraceString = $metadata['stack_trace'];
            unset($metadata['stack_trace']); // Remove from metadata to avoid duplication
            // Convert string to array of lines
            $stackTrace = is_string($stackTraceString)
                ? array_filter(explode("\n", $stackTraceString))
                : (is_array($stackTraceString) ? $stackTraceString : null);
        }

        $payload = [
            'batchId' => (string) Str::uuid(),
            'deviceInfo' => [
                'deviceId' => $this->deviceId,
                'deviceModel' => gethostname() ?: 'Unknown Server',
                'osVersion' => php_uname('r'),
                'appVersion' => config('app.version', '1.0.0'),
                'buildNumber' => null,
                'platform' => PHP_OS,
            ],
            'appIdentifier' => $this->appIdentifier,
            'environment' => config('app.env', 'production'),
            'userId' => null,
            'events' => [
                [
                    'id' => $eventId,
                    'timestamp' => $timestamp,
                    'level' => $type,
                    'message' => $message,
                    'metadata' => $this->enrichMetadata($metadata),
                    'stackTrace' => $stackTrace,
                ],
            ],
            'sentAt' => $timestamp,
            'isTest' => $this->isTest,
        ];

        try {
            $response = Http::withHeaders([
                'X-API-Key' => $this->apiKey,
                'X-App-Identifier' => $this->appIdentifier,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
                ->timeout($this->timeout)
                ->retry($this->retryTimes, $this->retrySleep)
                ->post("{$this->baseUrl}/api/v1/health/events", $payload);

            // If unauthorized and we have a secret, try refreshing the key
            if ($response->status() === 401 && !empty($this->appSecret)) {
                Log::info('Vitalytics: API key rejected, attempting refresh');
                $this->clearCachedApiKey();
                if ($this->fetchApiKey()) {
                    // Retry with new key
                    return $this->sendEvent($type, $message, $metadata);
                }
            }

            if (!$response->successful()) {
                Log::error('Vitalytics: Failed to send event', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return false;
            }

            // Extract and cache maintenance notifications from response
            $responseData = $response->json();
            if (isset($responseData['maintenance']) && is_array($responseData['maintenance'])) {
                $this->storeMaintenanceNotifications($responseData['maintenance']);
            }

            return true;

        } catch (\Exception $e) {
            Log::error('Vitalytics: Exception sending event', [
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Enrich metadata with additional context
     */
    private function enrichMetadata(array $metadata): array
    {
        // Remove null values
        $metadata = array_filter($metadata, fn($v) => $v !== null);

        // Add environment info if not present
        if (!isset($metadata['environment'])) {
            $metadata['environment'] = config('app.env', 'production');
        }

        // Add PHP version
        if (!isset($metadata['php_version'])) {
            $metadata['php_version'] = PHP_VERSION;
        }

        // Add Laravel version
        if (!isset($metadata['laravel_version'])) {
            $metadata['laravel_version'] = app()->version();
        }

        // Add hostname
        if (!isset($metadata['hostname'])) {
            $metadata['hostname'] = gethostname();
        }

        // Add memory usage
        if (!isset($metadata['memory_usage'])) {
            $metadata['memory_usage'] = memory_get_usage(true);
        }

        return $metadata;
    }

    /**
     * Generate a persistent device ID
     */
    private function generateDeviceId(): string
    {
        // Try to use hostname as a consistent identifier
        $hostname = gethostname();
        if ($hostname) {
            return 'server-' . md5($hostname);
        }

        // Fallback to a random UUID
        return (string) Str::uuid();
    }

    /**
     * Get the current device ID
     */
    public function getDeviceId(): string
    {
        return $this->deviceId;
    }

    /**
     * Get the app identifier
     */
    public function getAppIdentifier(): string
    {
        return $this->appIdentifier;
    }

    /**
     * Get the current API key (for debugging)
     */
    public function getApiKey(): ?string
    {
        return $this->apiKey;
    }

    // =========================================================================
    // Maintenance Notifications
    // =========================================================================

    /**
     * Store maintenance notifications from API response
     */
    private function storeMaintenanceNotifications(array $notifications): void
    {
        $ttl = config('vitalytics.maintenance.refresh_interval', self::MAINTENANCE_CACHE_TTL);
        Cache::put(
            self::CACHE_KEY_MAINTENANCE . '_' . $this->appIdentifier,
            $notifications,
            now()->addSeconds($ttl)
        );
    }

    /**
     * Get cached maintenance notifications (raw from API)
     */
    public function getMaintenanceNotifications(): array
    {
        return Cache::get(self::CACHE_KEY_MAINTENANCE . '_' . $this->appIdentifier, []);
    }

    /**
     * Get active maintenance notifications (filters by current time)
     * Only returns notifications that are currently within their time window.
     * Note: API returns UTC timestamps, we convert to local for comparison.
     */
    public function getActiveMaintenanceNotifications(): array
    {
        $notifications = $this->getMaintenanceNotifications();
        $now = now();

        return array_values(array_filter($notifications, function ($notification) use ($now) {
            if (!isset($notification['startsAt']) || !isset($notification['endsAt'])) {
                return false;
            }
            try {
                // Parse UTC timestamps from API and convert to local timezone
                $startsAt = \Carbon\Carbon::parse($notification['startsAt'])->setTimezone(config('app.timezone'));
                $endsAt = \Carbon\Carbon::parse($notification['endsAt'])->setTimezone(config('app.timezone'));
                return $now->between($startsAt, $endsAt);
            } catch (\Exception $e) {
                return false;
            }
        }));
    }

    /**
     * Get non-dismissed active maintenance notifications
     * Filters out notifications the user has dismissed this session.
     */
    public function getDisplayableMaintenanceNotifications(): array
    {
        $notifications = $this->getActiveMaintenanceNotifications();

        return array_values(array_filter($notifications, function ($notification) {
            $id = $notification['id'] ?? null;
            return $id && !$this->isMaintenanceDismissed($id);
        }));
    }

    /**
     * Check if a maintenance notification has been dismissed
     */
    public function isMaintenanceDismissed(int $notificationId): bool
    {
        if (function_exists('session') && session()->isStarted()) {
            $dismissed = session('vitalytics_dismissed_maintenance', []);
            return in_array($notificationId, $dismissed, true);
        }
        return false;
    }

    /**
     * Dismiss a maintenance notification for the current session
     */
    public function dismissMaintenance(int $notificationId): void
    {
        if (function_exists('session') && session()->isStarted()) {
            $dismissed = session('vitalytics_dismissed_maintenance', []);
            if (!in_array($notificationId, $dismissed, true)) {
                $dismissed[] = $notificationId;
                session(['vitalytics_dismissed_maintenance' => $dismissed]);
            }
        }
    }

    /**
     * Clear all dismissed maintenance notifications
     */
    public function clearDismissedMaintenance(): void
    {
        if (function_exists('session') && session()->isStarted()) {
            session()->forget('vitalytics_dismissed_maintenance');
        }
    }

    /**
     * Check if there are any active maintenance notifications to display
     */
    public function hasMaintenanceNotifications(): bool
    {
        return !empty($this->getDisplayableMaintenanceNotifications());
    }
}
