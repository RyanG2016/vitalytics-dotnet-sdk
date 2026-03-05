<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Vitalytics Base URL
    |--------------------------------------------------------------------------
    |
    | The base URL of your Vitalytics dashboard server.
    |
    */
    'base_url' => env('VITALYTICS_BASE_URL', 'https://your-vitalytics-server.com'),

    /*
    |--------------------------------------------------------------------------
    | API Key (Option 1: Direct)
    |--------------------------------------------------------------------------
    |
    | Your Vitalytics API key for authentication.
    | Use this OR app_secret below, not both.
    |
    */
    'api_key' => env('VITALYTICS_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | App Secret (Option 2: Dynamic - Recommended)
    |--------------------------------------------------------------------------
    |
    | Your Vitalytics App Secret for fetching API keys dynamically.
    | This allows API key rotation without changing your .env file.
    | The SDK will fetch and cache the API key automatically.
    |
    | If both api_key and app_secret are set, api_key takes precedence.
    |
    */
    'app_secret' => env('VITALYTICS_APP_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | App Identifier
    |--------------------------------------------------------------------------
    |
    | A unique identifier for this application (e.g., 'myapp-api', 'myapp-web').
    |
    */
    'app_identifier' => env('VITALYTICS_APP_IDENTIFIER'),

    /*
    |--------------------------------------------------------------------------
    | Device ID
    |--------------------------------------------------------------------------
    |
    | A persistent device/server identifier. If not set, a random UUID will be
    | generated and stored. For servers, you may want to set this to the
    | hostname or a consistent identifier.
    |
    */
    'device_id' => env('VITALYTICS_DEVICE_ID'),

    /*
    |--------------------------------------------------------------------------
    | Health Monitoring
    |--------------------------------------------------------------------------
    |
    | Configuration for health event monitoring (crashes, errors, warnings).
    |
    */
    'health' => [
        'enabled' => env('VITALYTICS_HEALTH_ENABLED', true),
        'is_test' => env('VITALYTICS_IS_TEST', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Automatic Exception Reporting
    |--------------------------------------------------------------------------
    |
    | When enabled, the SDK will automatically report unhandled exceptions
    | (500 errors) to Vitalytics. This integrates with Laravel's exception
    | handler to capture exceptions before they reach the user.
    |
    */
    'exceptions' => [
        'enabled' => env('VITALYTICS_EXCEPTIONS_ENABLED', true),
        // Exception classes to ignore (won't be reported)
        // These are normal HTTP errors, not server errors (500s)
        'ignore' => [
            \Illuminate\Auth\AuthenticationException::class,
            \Illuminate\Auth\Access\AuthorizationException::class,
            \Illuminate\Database\Eloquent\ModelNotFoundException::class,
            \Illuminate\Validation\ValidationException::class,
            \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
            \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Job Failure Monitoring
    |--------------------------------------------------------------------------
    |
    | When enabled, the SDK will automatically report failed queue jobs
    | to Vitalytics as errors. This helps you track job failures alongside
    | other application health events.
    |
    */
    'queue' => [
        'monitor_failures' => env('VITALYTICS_QUEUE_MONITOR_FAILURES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Analytics Tracking
    |--------------------------------------------------------------------------
    |
    | Configuration for analytics event tracking.
    |
    */
    'analytics' => [
        'enabled' => env('VITALYTICS_ANALYTICS_ENABLED', false),
        'is_test' => env('VITALYTICS_ANALYTICS_IS_TEST', false),
        'batch_size' => env('VITALYTICS_ANALYTICS_BATCH_SIZE', 20),
        'flush_interval' => env('VITALYTICS_ANALYTICS_FLUSH_INTERVAL', 30),
        // Session timeout in minutes - new session starts after this much inactivity
        'session_timeout_minutes' => env('VITALYTICS_SESSION_TIMEOUT_MINUTES', 30),
        // Enable auto-tracking route for JavaScript tracker (POST /vitalytics/track)
        'auto_tracking_route' => env('VITALYTICS_AUTO_TRACKING_ROUTE', true),
        // PHI-Safe Mode: For HIPAA-compliant healthcare applications
        // When enabled, the JavaScript tracker will:
        // - Never capture text content from buttons/links (may contain patient names)
        // - Never capture page titles (may contain patient names)
        // - Only use element IDs, names, and classes for identification
        // - Strip query parameters from URLs
        'phi_safe' => env('VITALYTICS_ANALYTICS_PHI_SAFE', false),

        /*
        |--------------------------------------------------------------------------
        | Collection Mode (NEW in v1.1.0)
        |--------------------------------------------------------------------------
        |
        | Direct mode selection when not using consent flow:
        | - 'privacy': No device ID (Privacy Mode - default)
        | - 'standard': With device ID (Standard Analytics)
        |
        */
        'collection_mode' => env('VITALYTICS_COLLECTION_MODE', 'privacy'),

        /*
        |--------------------------------------------------------------------------
        | Consent Mode (NEW in v1.1.0)
        |--------------------------------------------------------------------------
        |
        | Consent handling mode:
        | - 'none': Use collection_mode directly (no consent flow)
        | - 'standard-with-fallback': Consent for Standard, fallback to Privacy
        |
        | When using 'standard-with-fallback':
        | - Call VitalyticsAnalytics::setConsent(true) for Standard Analytics
        | - Call VitalyticsAnalytics::setConsent(false) for Privacy Mode
        | - Analytics are ALWAYS collected, just with varying detail levels
        |
        */
        'consent_mode' => env('VITALYTICS_CONSENT_MODE', 'none'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Health Monitoring
    |--------------------------------------------------------------------------
    |
    | Configuration for MariaDB/MySQL database health monitoring.
    |
    */
    'database' => [
        'enabled' => env('VITALYTICS_DATABASE_MONITORING_ENABLED', false),
        'connection' => env('VITALYTICS_DATABASE_CONNECTION', 'mysql'),

        // Thresholds for warning and critical alerts
        'thresholds' => [
            // Connection pool thresholds (percentage of max_connections)
            'connection_usage_warning' => 0.7,      // 70%
            'connection_usage_critical' => 0.9,     // 90%

            // Slow queries per check interval
            'slow_queries_warning' => 10,
            'slow_queries_critical' => 50,

            // Buffer pool usage thresholds
            'buffer_pool_usage_warning' => 0.85,    // 85%
            'buffer_pool_usage_critical' => 0.95,   // 95%

            // Table lock waits per interval
            'lock_wait_warning' => 5,
            'lock_wait_critical' => 20,

            // Aborted connections per interval
            'aborted_connects_warning' => 10,
            'aborted_connects_critical' => 50,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics Tracking (NEW in v1.1.0)
    |--------------------------------------------------------------------------
    |
    | Configuration for custom metrics tracking (AI tokens, API calls, etc.).
    | Metrics are aggregated server-side for dashboards and reporting.
    |
    */
    'metrics' => [
        'enabled' => env('VITALYTICS_METRICS_ENABLED', true),
        'is_test' => env('VITALYTICS_METRICS_IS_TEST', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Notifications (NEW in v1.4.0)
    |--------------------------------------------------------------------------
    |
    | Configuration for displaying maintenance banners from Vitalytics.
    | Maintenance notifications are delivered via heartbeat responses.
    |
    */
    'maintenance' => [
        // Enable maintenance notification display
        'enabled' => env('VITALYTICS_MAINTENANCE_ENABLED', true),

        // Auto-inject banners into HTML responses via middleware
        // Set to true and register InjectMaintenanceBanner middleware
        'auto_inject' => env('VITALYTICS_MAINTENANCE_AUTO_INJECT', false),

        // How long to cache maintenance data (in seconds)
        // Data is refreshed when heartbeat responses are received
        'refresh_interval' => env('VITALYTICS_MAINTENANCE_REFRESH', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for the HTTP client used to send events.
    |
    */
    'http' => [
        'timeout' => env('VITALYTICS_HTTP_TIMEOUT', 10),
        'retry_times' => env('VITALYTICS_HTTP_RETRY_TIMES', 2),
        'retry_sleep' => env('VITALYTICS_HTTP_RETRY_SLEEP', 100),
    ],
];
