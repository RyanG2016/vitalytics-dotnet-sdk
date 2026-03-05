<?php

namespace Vitalytics;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Throwable;
use Vitalytics\Commands\DbHealthCheckCommand;
use Vitalytics\Commands\SqliteHealthCheckCommand;
use Vitalytics\Http\Controllers\TrackingController;
use Vitalytics\Http\Controllers\MaintenanceController;

class VitalyticsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Merge config
        $this->mergeConfigFrom(
            __DIR__ . '/../config/vitalytics.php',
            'vitalytics'
        );

        // Register Vitalytics health client as singleton
        $this->app->singleton(Vitalytics::class, function ($app) {
            return Vitalytics::instance();
        });

        // Register VitalyticsAnalytics as singleton
        $this->app->singleton(VitalyticsAnalytics::class, function ($app) {
            return VitalyticsAnalytics::instance();
        });

        // Register VitalyticsDatabaseHealth (not singleton - allows different connections)
        $this->app->bind(VitalyticsDatabaseHealth::class, function ($app) {
            return new VitalyticsDatabaseHealth();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish config
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/vitalytics.php' => config_path('vitalytics.php'),
            ], 'vitalytics-config');

            // Publish JavaScript tracker
            $this->publishes([
                __DIR__ . '/../resources/js/tracker.js' => public_path('vendor/vitalytics/tracker.js'),
            ], 'vitalytics-assets');

            // Register artisan commands
            $this->commands([
                DbHealthCheckCommand::class,
                SqliteHealthCheckCommand::class,
            ]);
        }

        // Register tracking route for auto-tracking
        $this->registerTrackingRoute();

        // Register maintenance dismissal route
        $this->registerMaintenanceRoute();

        // Register exception handler integration
        $this->registerExceptionHandler();

        // Register queue job failure monitoring
        $this->registerQueueFailureHandler();

        // Register Blade directives
        $this->registerBladeDirectives();
    }

    /**
     * Register Blade directives for Vitalytics
     */
    private function registerBladeDirectives(): void
    {
        // @vitalyticsMeta - Outputs all necessary meta tags for the tracker
        // Includes PHI-safe meta tag if enabled in config
        Blade::directive('vitalyticsMeta', function () {
            return '<?php
                if (config("vitalytics.analytics.phi_safe", false)) {
                    echo \'<meta name="vitalytics-phi-safe" content="true">\' . "\n";
                }
            ?>';
        });

        // @vitalyticsScripts - Outputs the tracker script tag
        Blade::directive('vitalyticsScripts', function () {
            return '<?php
                if (config("vitalytics.analytics.enabled", false)) {
                    echo \'<script src="\' . asset("vendor/vitalytics/tracker.js") . \'"></script>\' . "\n";
                }
            ?>';
        });

        // @vitalyticsMaintenance - Outputs maintenance notification banners
        Blade::directive('vitalyticsMaintenance', function () {
            return '<?php
                if (config("vitalytics.maintenance.enabled", true)) {
                    $vitalytics = \Vitalytics\Vitalytics::instance();
                    $notifications = $vitalytics->getDisplayableMaintenanceNotifications();

                    $severityClasses = [
                        "info" => ["bg" => "bg-blue-100", "border" => "border-blue-500", "text" => "text-blue-800"],
                        "warning" => ["bg" => "bg-yellow-100", "border" => "border-yellow-500", "text" => "text-yellow-800"],
                        "critical" => ["bg" => "bg-red-100", "border" => "border-red-500", "text" => "text-red-800"],
                    ];

                    foreach ($notifications as $notification) {
                        $id = $notification["id"] ?? 0;
                        $title = e($notification["title"] ?? "Maintenance");
                        $message = e($notification["message"] ?? "");
                        $severity = $notification["severity"] ?? "info";
                        $dismissible = $notification["dismissible"] ?? true;
                        $classes = $severityClasses[$severity] ?? $severityClasses["info"];

                        echo \'<div class="vitalytics-maintenance-banner border-l-4 p-4 mb-4 \' . $classes["bg"] . \' \' . $classes["border"] . \' \' . $classes["text"] . \'" data-notification-id="\' . $id . \'">\';
                        echo \'<div class="flex justify-between items-start">\';
                        echo \'<div>\';
                        echo \'<h4 class="font-bold">\' . $title . \'</h4>\';
                        echo \'<p class="text-sm">\' . $message . \'</p>\';
                        echo \'</div>\';
                        if ($dismissible && \Illuminate\Support\Facades\Route::has("vitalytics.maintenance.dismiss")) {
                            echo \'<form method="POST" action="\' . route("vitalytics.maintenance.dismiss") . \'" class="ml-4">\';
                            echo csrf_field();
                            echo \'<input type="hidden" name="notification_id" value="\' . $id . \'">\';
                            echo \'<button type="submit" class="text-gray-500 hover:text-gray-700 text-xl leading-none">&times;</button>\';
                            echo \'</form>\';
                        }
                        echo \'</div>\';
                        echo \'</div>\';
                    }
                }
            ?>';
        });
    }

    /**
     * Register the tracking route for JavaScript auto-tracking
     */
    private function registerTrackingRoute(): void
    {
        if (!config('vitalytics.analytics.enabled', false)) {
            return;
        }

        if (!config('vitalytics.analytics.auto_tracking_route', true)) {
            return;
        }

        Route::post('/vitalytics/track', [TrackingController::class, 'track'])
            ->middleware('web')
            ->name('vitalytics.track');
    }

    /**
     * Register the maintenance dismissal route
     */
    private function registerMaintenanceRoute(): void
    {
        if (!config('vitalytics.maintenance.enabled', true)) {
            return;
        }

        Route::post('/vitalytics/maintenance/dismiss', [MaintenanceController::class, 'dismiss'])
            ->middleware('web')
            ->name('vitalytics.maintenance.dismiss');
    }

    /**
     * Register automatic exception handling
     */
    private function registerExceptionHandler(): void
    {
        // Register terminating callback for analytics flush
        if (method_exists($this->app, 'terminating')) {
            $this->app->terminating(function () {
                // Flush analytics on termination
                if (config('vitalytics.analytics.enabled', false)) {
                    VitalyticsAnalytics::instance()->flush();
                }
            });
        }

        // Register automatic exception reporting
        if (!config('vitalytics.health.enabled', true)) {
            return;
        }

        if (!config('vitalytics.exceptions.enabled', true)) {
            return;
        }

        // Use Laravel's exception handler to report exceptions
        $this->app->booted(function () {
            $this->registerExceptionReporting();
        });
    }

    /**
     * Register exception reporting with Laravel's exception handler
     */
    private function registerExceptionReporting(): void
    {
        try {
            $handler = $this->app->make(ExceptionHandler::class);

            // Laravel 10+ uses reportable() method
            if (method_exists($handler, 'reportable')) {
                $handler->reportable(function (Throwable $e) {
                    $this->reportExceptionToVitalytics($e);
                });
            }
        } catch (\Exception $e) {
            // Silently fail if we can't register - don't break the app
        }
    }

    /**
     * Register queue job failure handler
     */
    private function registerQueueFailureHandler(): void
    {
        if (!config('vitalytics.health.enabled', true)) {
            return;
        }

        if (!config('vitalytics.queue.monitor_failures', true)) {
            return;
        }

        Event::listen(JobFailed::class, function (JobFailed $event) {
            $this->reportJobFailedToVitalytics($event);
        });
    }

    /**
     * Report a failed queue job to Vitalytics
     */
    private function reportJobFailedToVitalytics(JobFailed $event): void
    {
        try {
            $vitalytics = Vitalytics::instance();

            if (!$vitalytics->isEnabled() || !$vitalytics->isConfigured()) {
                return;
            }

            // Get job details
            $job = $event->job;
            $exception = $event->exception;

            // Try to get the job class name
            $jobName = 'Unknown Job';
            $payload = $job->payload();
            if (isset($payload['displayName'])) {
                $jobName = $payload['displayName'];
            } elseif (isset($payload['job'])) {
                $jobName = $payload['job'];
            }

            // Build context
            $context = [
                'context' => 'queue',
                'job_name' => $jobName,
                'job_id' => $job->getJobId(),
                'queue' => $job->getQueue(),
                'connection' => $event->connectionName,
                'attempts' => $job->attempts(),
                'max_tries' => $payload['maxTries'] ?? null,
                'timeout' => $payload['timeout'] ?? null,
            ];

            // Add exception details
            if ($exception) {
                $context['exception_class'] = get_class($exception);
                $context['file'] = $exception->getFile();
                $context['line'] = $exception->getLine();
            }

            // Build message
            $message = "Queue job failed: {$jobName}";
            if ($exception) {
                $message .= " - " . $exception->getMessage();
            }

            // Report as error (queue failures are serious but recoverable)
            $vitalytics->error(
                $message,
                array_merge($context, [
                    'stack_trace' => $exception ? $exception->getTraceAsString() : null,
                ])
            );

        } catch (\Exception $e) {
            // Silently fail - don't break the queue worker
        }
    }

    /**
     * Report an exception to Vitalytics
     */
    private function reportExceptionToVitalytics(Throwable $e): void
    {
        // Check if this exception type should be ignored
        $ignoreTypes = config('vitalytics.exceptions.ignore', []);
        foreach ($ignoreTypes as $ignoreType) {
            if ($e instanceof $ignoreType) {
                return;
            }
        }

        // Build context with request information
        $context = $this->buildExceptionContext($e);

        // Report to Vitalytics as crash (critical level)
        // All unhandled exceptions (500 errors) are treated as critical
        $vitalytics = Vitalytics::instance();

        $vitalytics->crash(
            get_class($e) . ': ' . $e->getMessage(),
            $e->getTraceAsString(),
            $context
        );
    }

    /**
     * Build context array with exception and request details
     */
    private function buildExceptionContext(Throwable $e): array
    {
        $context = [
            'exception_class' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'code' => $e->getCode(),
        ];

        // Add request context if available
        if ($this->app->runningInConsole()) {
            $context['context'] = 'console';
            if (isset($_SERVER['argv'])) {
                $context['command'] = implode(' ', $_SERVER['argv']);
            }
        } else {
            try {
                $request = $this->app->make('request');
                $context['context'] = 'http';
                $context['url'] = $request->fullUrl();
                $context['method'] = $request->method();
                $context['route'] = $request->route()?->getName() ?? $request->path();
                $context['ip'] = $request->ip();
                $context['user_agent'] = $request->userAgent();

                // Add authenticated user ID if available
                if ($request->user()) {
                    $context['user_id'] = $request->user()->id;
                }
            } catch (\Exception $e) {
                // Request not available, skip request context
            }
        }

        // Add previous exception if exists
        if ($e->getPrevious()) {
            $context['previous_exception'] = get_class($e->getPrevious()) . ': ' . $e->getPrevious()->getMessage();
        }

        return $context;
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            Vitalytics::class,
            VitalyticsAnalytics::class,
            VitalyticsDatabaseHealth::class,
        ];
    }
}
