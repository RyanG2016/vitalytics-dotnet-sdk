<?php

namespace Vitalytics\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Vitalytics\Vitalytics instance()
 * @method static \Vitalytics\Vitalytics configure(string $baseUrl, string $apiKey, string $appIdentifier, ?string $deviceId = null, bool $isTest = false)
 * @method static \Vitalytics\Vitalytics setEnabled(bool $enabled)
 * @method static bool isEnabled()
 * @method static bool crash(string $message, ?string $stackTrace = null, array $context = [])
 * @method static bool error(string $message, array $context = [])
 * @method static bool warning(string $message, array $context = [])
 * @method static bool info(string $message, array $context = [])
 * @method static bool heartbeat(string $message = 'Application heartbeat', array $context = [])
 * @method static string getDeviceId()
 * @method static string getAppIdentifier()
 *
 * @see \Vitalytics\Vitalytics
 */
class Vitalytics extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Vitalytics\Vitalytics::class;
    }
}
