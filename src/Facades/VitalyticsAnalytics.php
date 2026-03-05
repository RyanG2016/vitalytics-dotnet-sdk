<?php

namespace Vitalytics\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Vitalytics\VitalyticsAnalytics instance()
 * @method static \Vitalytics\VitalyticsAnalytics configure(string $baseUrl, string $apiKey, string $appIdentifier, bool $isTest = false)
 * @method static \Vitalytics\VitalyticsAnalytics setEnabled(bool $enabled)
 * @method static bool isEnabled()
 * @method static \Vitalytics\VitalyticsAnalytics startSession()
 * @method static \Vitalytics\VitalyticsAnalytics trackScreen(string $screenName, array $properties = [])
 * @method static \Vitalytics\VitalyticsAnalytics trackEvent(string $name, string $category, array $properties = [])
 * @method static \Vitalytics\VitalyticsAnalytics trackApiCall(string $endpoint, string $method, int $statusCode, int $durationMs)
 * @method static \Vitalytics\VitalyticsAnalytics trackFeature(string $featureName, array $properties = [])
 * @method static \Vitalytics\VitalyticsAnalytics trackClick(string $elementId, array $properties = [])
 * @method static \Vitalytics\VitalyticsAnalytics trackJob(string $jobName, string $status, array $properties = [])
 * @method static \Vitalytics\VitalyticsAnalytics trackForm(string $formName, string $action, array $properties = [])
 * @method static \Vitalytics\VitalyticsAnalytics trackSearch(string $query, int $resultCount, array $properties = [])
 * @method static bool flush()
 * @method static string getSessionId()
 * @method static int getPendingEventCount()
 * @method static array submitFeedback(string $message, array $options = [])
 * @method static array submitBugReport(string $message, array $options = [])
 * @method static array submitFeatureRequest(string $message, array $options = [])
 * @method static array submitPraise(string $message, array $options = [])
 * @method static array trackMetric(string $name, array $data, array $options = [])
 * @method static array trackAiTokens(string $provider, int $inputTokens, int $outputTokens, array $options = [])
 * @method static array trackApiCallMetric(string $endpoint, int $durationMs, array $options = [])
 *
 * @see \Vitalytics\VitalyticsAnalytics
 */
class VitalyticsAnalytics extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Vitalytics\VitalyticsAnalytics::class;
    }
}
