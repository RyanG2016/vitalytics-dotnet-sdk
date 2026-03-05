<?php

namespace Vitalytics;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Vitalytics Analytics Client
 *
 * Track user journeys, page views, feature usage, and custom events.
 */
class VitalyticsAnalytics
{
    private static ?self $instance = null;

    private string $baseUrl;
    private ?string $apiKey = null;
    private ?string $appSecret = null;
    private string $appIdentifier;
    private string $deviceId;
    private bool $enabled = false;
    private bool $isTest = false;
    private array $eventQueue = [];
    private string $sessionId;
    private ?string $currentScreen = null;
    private int $batchSize = 20;
    private int $timeout = 10;
    private int $sessionTimeoutMinutes = 30;

    // Consent/Collection Mode (NEW in v1.1.0)
    private string $collectionMode = 'privacy';
    private string $consentMode = 'none';
    private ?bool $consentGiven = null;

    private const CACHE_KEY_API_KEY = 'vitalytics_analytics_api_key';
    private const CACHE_KEY_CONSENT = 'vitalytics_consent';
    private const CACHE_TTL_HOURS = 20;

    private function __construct()
    {
        $this->baseUrl = rtrim(config('vitalytics.base_url', ''), '/');
        $this->appIdentifier = config('vitalytics.app_identifier', '');
        $this->deviceId = config('vitalytics.device_id') ?? gethostname() ?: 'laravel-server';
        $this->enabled = config('vitalytics.analytics.enabled', false);
        $this->isTest = config('vitalytics.analytics.is_test', false);
        $this->batchSize = config('vitalytics.analytics.batch_size', 20);
        $this->timeout = config('vitalytics.http.timeout', 10);
        $this->sessionTimeoutMinutes = config('vitalytics.analytics.session_timeout_minutes', 30);
        $this->sessionId = $this->getOrCreateSessionId();

        // Initialize consent/collection mode (NEW in v1.1.0)
        $this->collectionMode = config('vitalytics.analytics.collection_mode', 'privacy');
        $this->consentMode = config('vitalytics.analytics.consent_mode', 'none');
        $this->consentGiven = $this->getStoredConsent();

        // Check for API key first (direct configuration)
        $this->apiKey = config('vitalytics.api_key') ?: null;

        // If no API key, check for App Secret (dynamic key fetching)
        if (empty($this->apiKey)) {
            $this->appSecret = config('vitalytics.app_secret') ?: null;

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
     * Get stored consent preference from Laravel session
     */
    private function getStoredConsent(): ?bool
    {
        if (function_exists('session') && session()->isStarted()) {
            $storedConsent = session(self::CACHE_KEY_CONSENT);
            if ($storedConsent !== null) {
                return (bool) $storedConsent;
            }
        }
        return null;
    }

    /**
     * Store consent preference in Laravel session
     */
    private function storeConsent(bool $consent): void
    {
        if (function_exists('session') && session()->isStarted()) {
            session([self::CACHE_KEY_CONSENT => $consent]);
        }
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
                    Log::debug('VitalyticsAnalytics: API key fetched successfully');
                    return true;
                }
            }

            Log::error('VitalyticsAnalytics: Failed to fetch API key', [
                'status' => $response->status(),
            ]);
            return false;

        } catch (\Exception $e) {
            Log::error('VitalyticsAnalytics: Exception fetching API key', [
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
     * Clear cached API key
     */
    public function clearCachedApiKey(): void
    {
        Cache::forget(self::CACHE_KEY_API_KEY . '_' . $this->appIdentifier);
        $this->apiKey = null;
    }

    /**
     * Get existing session ID from Laravel session or create a new one.
     * This ensures the same user session persists across HTTP requests.
     * Sessions expire after configured minutes of inactivity (default: 30).
     */
    private function getOrCreateSessionId(): string
    {
        // Try to get from Laravel session first (for web requests)
        if (function_exists('session') && session()->isStarted()) {
            $existingId = session('vitalytics_session_id');
            $lastActivity = session('vitalytics_session_last_activity');

            // Check if session exists and hasn't expired
            if ($existingId && $lastActivity) {
                $minutesSinceActivity = now()->diffInMinutes($lastActivity);
                if ($minutesSinceActivity < $this->sessionTimeoutMinutes) {
                    // Update last activity time
                    session(['vitalytics_session_last_activity' => now()]);
                    return $existingId;
                }
            }

            // Create new session ID (either first time or expired)
            $newId = (string) Str::uuid();
            session([
                'vitalytics_session_id' => $newId,
                'vitalytics_session_last_activity' => now(),
            ]);
            return $newId;
        }

        // For non-web contexts (artisan commands, queue workers), generate fresh ID
        return (string) Str::uuid();
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
     * Configure the client manually (alternative to config file)
     */
    public function configure(
        string $baseUrl,
        string $apiKey,
        string $appIdentifier,
        bool $isTest = false
    ): self {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->appIdentifier = $appIdentifier;
        $this->isTest = $isTest;
        return $this;
    }

    /**
     * Enable or disable analytics tracking
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        if ($enabled) {
            $this->startSession();
        }
        return $this;
    }

    /**
     * Check if analytics is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    // =========================================================================
    // Consent Management (NEW in v1.1.0)
    // =========================================================================

    /**
     * Set user consent for Standard Analytics.
     *
     * In 'standard-with-fallback' mode:
     * - true = Standard Analytics (with device ID)
     * - false = Privacy Mode (no device ID, but still tracks!)
     *
     * @param bool $userAccepted Whether user accepted Standard Analytics
     * @return self
     */
    public function setConsent(bool $userAccepted): self
    {
        $this->consentGiven = $userAccepted;
        $this->storeConsent($userAccepted);

        Log::debug('VitalyticsAnalytics: Consent set', [
            'mode' => $userAccepted ? 'standard' : 'privacy',
        ]);

        return $this;
    }

    /**
     * Check if user has made a consent choice
     */
    public function hasConsentChoice(): bool
    {
        return $this->consentGiven !== null;
    }

    /**
     * Get the effective collection mode (considers consent state).
     *
     * @return string 'standard' or 'privacy'
     */
    public function getEffectiveCollectionMode(): string
    {
        if ($this->consentMode === 'standard-with-fallback') {
            // Consent flow: standard if consented, privacy otherwise
            return $this->consentGiven === true ? 'standard' : 'privacy';
        }
        // Direct mode selection
        return $this->collectionMode;
    }

    /**
     * Check if currently in Privacy Mode (no device ID)
     */
    public function isPrivacyMode(): bool
    {
        return $this->getEffectiveCollectionMode() === 'privacy';
    }

    /**
     * Check if currently in Standard Analytics mode (with device ID)
     */
    public function isStandardMode(): bool
    {
        return $this->getEffectiveCollectionMode() === 'standard';
    }

    /**
     * Set collection mode directly (when not using consent flow).
     *
     * @param string $mode 'standard' or 'privacy'
     * @return self
     */
    public function setCollectionMode(string $mode): self
    {
        if (!in_array($mode, ['standard', 'privacy'])) {
            Log::warning('VitalyticsAnalytics: Invalid collection mode', ['mode' => $mode]);
            return $this;
        }
        $this->collectionMode = $mode;
        return $this;
    }

    /**
     * Get the effective device ID (null in Privacy Mode)
     */
    private function getEffectiveDeviceId(): ?string
    {
        return $this->isPrivacyMode() ? null : $this->deviceId;
    }

    /**
     * Start a new analytics session
     */
    public function startSession(): self
    {
        $this->sessionId = (string) Str::uuid();
        $this->trackEvent('session_started', 'session');
        return $this;
    }

    /**
     * Track a screen/page view
     */
    public function trackScreen(string $screenName, array $properties = []): self
    {
        $this->currentScreen = $screenName;
        return $this->queueEvent('screen_viewed', 'navigation', $screenName, null, $properties);
    }

    /**
     * Set the current screen context without firing a screen_viewed event.
     * Useful for modals, dialogs, or sub-views where you want click events
     * to be associated with a screen name but don't want to track it as a navigation.
     */
    public function setScreen(string $screenName): self
    {
        $this->currentScreen = $screenName;
        return $this;
    }

    /**
     * Get the current screen context
     */
    public function getScreen(): ?string
    {
        return $this->currentScreen;
    }

    /**
     * Track a custom event
     */
    public function trackEvent(string $eventType, string $category, array $properties = []): self
    {
        return $this->queueEvent($eventType, $category, $this->currentScreen, null, $properties);
    }

    /**
     * Queue an event with proper structure
     */
    private function queueEvent(
        string $eventType,
        string $category,
        ?string $screen = null,
        ?string $element = null,
        array $properties = []
    ): self {
        if (!$this->enabled) {
            return $this;
        }

        $event = [
            'id' => (string) Str::uuid(),
            'timestamp' => now()->utc()->toIso8601String(),
            'eventType' => $eventType,
            'sessionId' => $this->sessionId,
            'category' => $category,
        ];

        if ($screen) {
            $event['screen'] = $screen;
        }

        if ($element) {
            $event['element'] = $element;
        }

        if (!empty($properties)) {
            $event['properties'] = $properties;
        }

        $this->eventQueue[] = $event;

        if (count($this->eventQueue) >= $this->batchSize) {
            $this->flush();
        }

        return $this;
    }

    /**
     * Track an API call
     */
    public function trackApiCall(string $endpoint, string $method, int $statusCode, int $durationMs): self
    {
        return $this->queueEvent('api_called', 'api', $this->currentScreen, null, [
            'endpoint' => $endpoint,
            'method' => $method,
            'status_code' => $statusCode,
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * Track feature usage
     */
    public function trackFeature(string $featureName, array $properties = []): self
    {
        return $this->queueEvent('feature_used', 'feature', $this->currentScreen, null, array_merge(
            ['feature' => $featureName],
            $properties
        ));
    }

    /**
     * Track a button/element click
     */
    public function trackClick(string $elementId, array $properties = []): self
    {
        return $this->queueEvent('button_clicked', 'interaction', $this->currentScreen, $elementId, $properties);
    }

    /**
     * Track a background job
     */
    public function trackJob(string $jobName, string $status, array $properties = []): self
    {
        return $this->queueEvent('job_' . $status, 'background', $this->currentScreen, null, array_merge(
            ['job' => $jobName],
            $properties
        ));
    }

    /**
     * Track a form submission
     */
    public function trackForm(string $formName, string $action, array $properties = []): self
    {
        return $this->queueEvent('form_' . $action, 'form', $this->currentScreen, null, array_merge(
            ['form' => $formName],
            $properties
        ));
    }

    /**
     * Track a search
     */
    public function trackSearch(string $query, int $resultCount, array $properties = []): self
    {
        return $this->queueEvent('search_performed', 'search', $this->currentScreen, null, array_merge(
            ['query' => $query, 'result_count' => $resultCount],
            $properties
        ));
    }

    /**
     * Flush the event queue and send to Vitalytics
     */
    public function flush(): bool
    {
        if (!$this->enabled || empty($this->eventQueue)) {
            return true;
        }

        // If no API key but we have a secret, try to fetch it
        if (empty($this->apiKey) && !empty($this->appSecret)) {
            $this->fetchApiKey();
        }

        if (empty($this->baseUrl) || empty($this->apiKey) || empty($this->appIdentifier)) {
            Log::warning('VitalyticsAnalytics: Missing configuration', [
                'has_base_url' => !empty($this->baseUrl),
                'has_api_key' => !empty($this->apiKey),
                'has_app_secret' => !empty($this->appSecret),
                'has_app_identifier' => !empty($this->appIdentifier),
            ]);
            return false;
        }

        $events = $this->eventQueue;
        $this->eventQueue = [];

        $batch = [
            'batchId' => (string) Str::uuid(),
            'appIdentifier' => $this->appIdentifier,
            'deviceInfo' => [
                'deviceId' => $this->getEffectiveDeviceId(), // null in Privacy Mode
                'deviceModel' => php_uname('n'),
                'platform' => 'Laravel',
                'osVersion' => PHP_OS . ' ' . php_uname('r'),
                'appVersion' => config('app.version', '1.0.0'),
                'language' => config('app.locale', 'en'),
            ],
            'sessionId' => $this->sessionId,
            'isTest' => $this->isTest,
            'sentAt' => now()->utc()->toIso8601String(),
            'events' => $events,
        ];

        try {
            $response = Http::withHeaders([
                'X-API-Key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
                ->timeout($this->timeout)
                ->post("{$this->baseUrl}/api/v1/analytics/events", $batch);

            // If unauthorized and we have a secret, try refreshing the key
            if ($response->status() === 401 && !empty($this->appSecret)) {
                Log::info('VitalyticsAnalytics: API key rejected, attempting refresh');
                $this->clearCachedApiKey();
                if ($this->fetchApiKey()) {
                    // Re-queue events and retry
                    $this->eventQueue = array_merge($events, $this->eventQueue);
                    return $this->flush();
                }
            }

            if (!$response->successful()) {
                Log::error('VitalyticsAnalytics: Failed to send batch', [
                    'status' => $response->status(),
                ]);
                // Re-queue events on failure
                $this->eventQueue = array_merge($events, $this->eventQueue);
                return false;
            }

            return true;

        } catch (\Exception $e) {
            Log::error('VitalyticsAnalytics: Exception sending batch', [
                'message' => $e->getMessage(),
            ]);
            // Re-queue events on failure
            $this->eventQueue = array_merge($events, $this->eventQueue);
            return false;
        }
    }

    /**
     * Get the current session ID
     */
    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * Set the session ID (useful for restoring a session from external storage)
     */
    public function setSessionId(string $sessionId): self
    {
        $this->sessionId = $sessionId;

        // Also update Laravel session if available
        if (function_exists('session') && session()->isStarted()) {
            session([
                'vitalytics_session_id' => $sessionId,
                'vitalytics_session_last_activity' => now(),
            ]);
        }

        return $this;
    }

    /**
     * Get pending event count
     */
    public function getPendingEventCount(): int
    {
        return count($this->eventQueue);
    }

    /**
     * Flush on destruct
     */
    public function __destruct()
    {
        $this->flush();
    }

    // =========================================================================
    // User Feedback Methods (NEW in v1.2.0)
    // =========================================================================

    /**
     * Submit user feedback
     *
     * @param string $message The feedback message (required)
     * @param array $options Optional settings:
     *   - category: 'general', 'bug', 'feature-request', 'praise' (default: 'general')
     *   - rating: 1-5 star rating (optional)
     *   - email: User's email for follow-up (optional)
     *   - userId: Custom user identifier (optional)
     *   - screen: Screen name where feedback was submitted (optional, defaults to currentScreen)
     *   - metadata: Additional custom data (optional)
     * @return array Result with 'success' and optional 'error' keys
     */
    public function submitFeedback(string $message, array $options = []): array
    {
        if (empty(trim($message))) {
            return ['success' => false, 'error' => 'Feedback message is required'];
        }

        // If no API key but we have a secret, try to fetch it
        if (empty($this->apiKey) && !empty($this->appSecret)) {
            $this->fetchApiKey();
        }

        if (empty($this->baseUrl) || empty($this->apiKey) || empty($this->appIdentifier)) {
            Log::warning('VitalyticsAnalytics: Missing configuration for feedback');
            return ['success' => false, 'error' => 'Missing configuration'];
        }

        // Validate category
        $validCategories = ['general', 'bug', 'feature-request', 'praise'];
        $category = $options['category'] ?? 'general';
        if (!is_string($category) || !in_array($category, $validCategories)) {
            $category = 'general';
        }

        // Validate rating (must be integer 1-5 or null)
        $rating = null;
        if (isset($options['rating']) && $options['rating'] !== null && $options['rating'] !== '') {
            $rating = (int) $options['rating'];
            if ($rating < 1 || $rating > 5) {
                $rating = null;
            }
        }

        // Validate email format
        $email = null;
        if (!empty($options['email']) && filter_var($options['email'], FILTER_VALIDATE_EMAIL)) {
            $email = (string) $options['email'];
        }

        // Validate metadata is array or null
        $metadata = null;
        if (isset($options['metadata']) && is_array($options['metadata'])) {
            $metadata = $options['metadata'];
        }

        $payload = [
            'appIdentifier' => $this->appIdentifier,
            'message' => trim($message),
            'category' => $category,
            'rating' => $rating,
            'email' => $email,
            'userId' => isset($options['userId']) ? (string) $options['userId'] : null,
            'deviceId' => $this->getEffectiveDeviceId(),
            'sessionId' => $this->sessionId,
            'screen' => isset($options['screen']) ? (string) $options['screen'] : $this->currentScreen,
            'deviceInfo' => [
                'platform' => 'Laravel',
                'osVersion' => PHP_OS . ' ' . php_uname('r'),
                'appVersion' => config('app.version', '1.0.0'),
            ],
            'metadata' => $metadata,
            'isTest' => (bool) $this->isTest,
        ];

        try {
            $response = Http::withHeaders([
                'X-API-Key' => $this->apiKey,
                'X-App-Identifier' => $this->appIdentifier,
                'X-SDK-Version' => 'laravel-1.2.1',
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
                ->timeout($this->timeout)
                ->post("{$this->baseUrl}/api/v1/feedback", $payload);

            // If unauthorized and we have a secret, try refreshing the key
            if ($response->status() === 401 && !empty($this->appSecret)) {
                Log::info('VitalyticsAnalytics: API key rejected during feedback, attempting refresh');
                $this->clearCachedApiKey();
                if ($this->fetchApiKey()) {
                    return $this->submitFeedback($message, $options);
                }
            }

            if ($response->successful()) {
                Log::debug('VitalyticsAnalytics: Feedback submitted successfully', [
                    'category' => $category,
                ]);
                return ['success' => true, 'feedbackId' => $response->json('feedbackId')];
            }

            $responseData = $response->json();
            Log::error('VitalyticsAnalytics: Failed to submit feedback', [
                'status' => $response->status(),
                'error' => $responseData['error'] ?? null,
                'message' => $responseData['message'] ?? null,
                'errors' => $responseData['errors'] ?? null,
            ]);
            return [
                'success' => false,
                'error' => $responseData['message'] ?? 'Failed to submit feedback',
                'details' => $responseData['errors'] ?? null,
            ];

        } catch (\Exception $e) {
            Log::error('VitalyticsAnalytics: Exception submitting feedback', [
                'message' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Submit a bug report (convenience method)
     *
     * @param string $message The bug report message
     * @param array $options Additional options (see submitFeedback)
     * @return array Result with 'success' and optional 'error' keys
     */
    public function submitBugReport(string $message, array $options = []): array
    {
        $options['category'] = 'bug';
        return $this->submitFeedback($message, $options);
    }

    /**
     * Submit a feature request (convenience method)
     *
     * @param string $message The feature request message
     * @param array $options Additional options (see submitFeedback)
     * @return array Result with 'success' and optional 'error' keys
     */
    public function submitFeatureRequest(string $message, array $options = []): array
    {
        $options['category'] = 'feature-request';
        return $this->submitFeedback($message, $options);
    }

    /**
     * Submit positive feedback/praise (convenience method)
     *
     * @param string $message The praise message
     * @param array $options Additional options (see submitFeedback)
     * @return array Result with 'success' and optional 'error' keys
     */
    public function submitPraise(string $message, array $options = []): array
    {
        $options['category'] = 'praise';
        return $this->submitFeedback($message, $options);
    }

    // =========================================================================
    // METRICS TRACKING
    // =========================================================================

    /**
     * Track a custom metric
     *
     * Use this to track any quantifiable data like AI token usage, API calls,
     * resource consumption, etc.
     *
     * @param string $name Metric name (e.g., 'ai_tokens', 'api_calls', 'storage_used')
     * @param array $data Metric data with numeric values to track
     * @param array $options Additional options:
     *   - 'aggregate' => 'sum'|'avg'|'min'|'max'|'count' (default: 'sum')
     *   - 'tags' => [] Array of tags for filtering/grouping
     *   - 'user_id' => string Optional user identifier
     * @return array Result with 'success' key
     *
     * @example
     * // Track AI token usage
     * Vitalytics::trackMetric('ai_tokens', [
     *     'provider' => 'claude',
     *     'model' => 'claude-3-opus',
     *     'input_tokens' => 1500,
     *     'output_tokens' => 800,
     *     'total_tokens' => 2300,
     *     'cost_cents' => 12,
     * ]);
     *
     * @example
     * // Track API response times with averaging
     * Vitalytics::trackMetric('api_latency', [
     *     'endpoint' => '/api/analyze',
     *     'duration_ms' => 234,
     * ], ['aggregate' => 'avg']);
     */
    public function trackMetric(string $name, array $data, array $options = []): array
    {
        if (!$this->enabled || !$this->isConfigured()) {
            return ['success' => false, 'error' => 'Metrics tracking not configured'];
        }

        if (!config('vitalytics.metrics.enabled', true)) {
            return ['success' => false, 'error' => 'Metrics tracking disabled'];
        }

        $metric = [
            'id' => Str::uuid()->toString(),
            'name' => $name,
            'data' => $data,
            'aggregate' => $options['aggregate'] ?? 'sum',
            'tags' => $options['tags'] ?? [],
            'user_id' => $options['user_id'] ?? null,
            'timestamp' => now()->toIso8601String(),
        ];

        return $this->sendMetric($metric);
    }

    /**
     * Track AI token usage (convenience method)
     *
     * @param string $provider AI provider name (e.g., 'claude', 'openai', 'gemini')
     * @param int $inputTokens Number of input/prompt tokens
     * @param int $outputTokens Number of output/completion tokens
     * @param array $options Additional options:
     *   - 'model' => string Model name (e.g., 'claude-3-opus')
     *   - 'cost_cents' => int Cost in cents
     *   - 'user_id' => string User identifier
     *   - 'request_type' => string Type of request (e.g., 'chat', 'completion', 'embedding')
     *   - 'tags' => array Additional tags
     * @return array Result with 'success' key
     *
     * @example
     * // After a Claude API call
     * $response = $claude->messages()->create([...]);
     * Vitalytics::trackAiTokens(
     *     'claude',
     *     $response->usage->input_tokens,
     *     $response->usage->output_tokens,
     *     [
     *         'model' => 'claude-3-opus-20240229',
     *         'user_id' => auth()->id(),
     *         'request_type' => 'chat',
     *     ]
     * );
     */
    public function trackAiTokens(
        string $provider,
        int $inputTokens,
        int $outputTokens,
        array $options = []
    ): array {
        $data = [
            'provider' => $provider,
            'model' => $options['model'] ?? 'unknown',
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $inputTokens + $outputTokens,
        ];

        if (isset($options['cost_cents'])) {
            $data['cost_cents'] = $options['cost_cents'];
        }

        if (isset($options['request_type'])) {
            $data['request_type'] = $options['request_type'];
        }

        $trackOptions = [
            'aggregate' => 'sum',
            'tags' => array_merge(
                ['provider:' . $provider],
                isset($options['model']) ? ['model:' . $options['model']] : [],
                $options['tags'] ?? []
            ),
        ];

        if (isset($options['user_id'])) {
            $trackOptions['user_id'] = $options['user_id'];
        }

        return $this->trackMetric('ai_tokens', $data, $trackOptions);
    }

    /**
     * Track API call metrics (convenience method)
     *
     * @param string $endpoint API endpoint path
     * @param int $durationMs Response time in milliseconds
     * @param array $options Additional options:
     *   - 'method' => string HTTP method (GET, POST, etc.)
     *   - 'status_code' => int HTTP status code
     *   - 'user_id' => string User identifier
     *   - 'tags' => array Additional tags
     * @return array Result with 'success' key
     */
    public function trackApiCallMetric(string $endpoint, int $durationMs, array $options = []): array
    {
        $data = [
            'endpoint' => $endpoint,
            'duration_ms' => $durationMs,
            'method' => $options['method'] ?? 'GET',
        ];

        if (isset($options['status_code'])) {
            $data['status_code'] = $options['status_code'];
        }

        $trackOptions = [
            'aggregate' => 'avg',
            'tags' => array_merge(
                ['endpoint:' . $endpoint],
                $options['tags'] ?? []
            ),
        ];

        if (isset($options['user_id'])) {
            $trackOptions['user_id'] = $options['user_id'];
        }

        return $this->trackMetric('api_calls', $data, $trackOptions);
    }

    /**
     * Send a metric to the Vitalytics API
     */
    private function sendMetric(array $metric): array
    {
        $payload = [
            'appIdentifier' => $this->appIdentifier,
            'deviceId' => $this->deviceId,
            'metric' => $metric,
            'isTest' => (bool) $this->isTest,
        ];

        try {
            $response = Http::withHeaders([
                'X-API-Key' => $this->apiKey,
                'X-App-Identifier' => $this->appIdentifier,
                'X-SDK-Version' => 'laravel-1.2.1',
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
                ->timeout($this->timeout)
                ->post("{$this->baseUrl}/api/v1/metrics", $payload);

            // If unauthorized and we have a secret, try refreshing the key
            if ($response->status() === 401 && !empty($this->appSecret)) {
                Log::info('VitalyticsAnalytics: API key rejected during metric, attempting refresh');
                $this->clearCachedApiKey();
                if ($this->fetchApiKey()) {
                    return $this->sendMetric($metric);
                }
            }

            if ($response->successful()) {
                Log::debug('VitalyticsAnalytics: Metric tracked successfully', [
                    'name' => $metric['name'],
                ]);
                return ['success' => true, 'metricId' => $response->json('metricId')];
            }

            $responseData = $response->json();
            Log::error('VitalyticsAnalytics: Failed to track metric', [
                'status' => $response->status(),
                'error' => $responseData['error'] ?? null,
                'message' => $responseData['message'] ?? null,
            ]);
            return [
                'success' => false,
                'error' => $responseData['message'] ?? 'Failed to track metric',
            ];

        } catch (\Exception $e) {
            Log::error('VitalyticsAnalytics: Exception tracking metric', [
                'message' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
