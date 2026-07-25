<?php

namespace App\Support;

use DeviceDetector\DeviceDetector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class Device
{
    /**
     * Device names already resolved during this request, keyed by user agent.
     *
     * @var array<string, string>
     */
    private static array $resolvedNames = [];

    /**
     * Get the formatted device name from the request.
     *
     * @param  Request  $request
     * @return string
     */
    public static function name(Request $request): string
    {
        return self::nameFromUserAgent((string) $request->userAgent());
    }

    /**
     * Get the formatted device name from a raw user-agent string, e.g. one stored on a session or authentication-log row.
     *
     * DeviceDetector's parse() sweeps its whole regex database - tens of milliseconds of CPU per call - so resolved
     * names are memoized for the request and cached per distinct user agent, keeping the cost to one cold parse per
     * new browser rather than one per displayed row.
     *
     * @param  string  $userAgent
     * @return string
     */
    public static function nameFromUserAgent(string $userAgent): string
    {
        return self::$resolvedNames[$userAgent] ??= Cache::remember(
            'device-name:'.hash('sha256', $userAgent),
            now()->addMonth(),
            static function () use ($userAgent): string {
                $detector = self::getDeviceDetectorByUserAgent($userAgent);

                return Str::limit(self::getDeviceNameFromDetector($detector), 1024);
            },
        );
    }

    /**
     * Stable fingerprint identifying the device a request came from.
     *
     * Hashes the version-stripped user agent together with the IP and the accept headers: browser updates do not
     * read as a new device, while a different browser, machine, or network does.
     * Used by the authentication log to recognize known devices; a collision merely suppresses a new-device notification,
     * so best-effort accuracy is fine.
     *
     * @param  Request  $request
     * @return string
     */
    public static function fingerprint(Request $request): string
    {
        $versionlessUserAgent = preg_replace('#/[\d.]+#', '', (string) $request->userAgent());

        return hash('sha256', implode('|', [
            $versionlessUserAgent,
            (string) $request->ip(),
            (string) $request->header('Accept-Language'),
            (string) $request->header('Accept-Encoding'),
        ]));
    }

    /**
     * Parse the user agent string into a DeviceDetector instance.
     *
     * @param  string  $userAgent
     * @return DeviceDetector
     */
    private static function getDeviceDetectorByUserAgent(string $userAgent): DeviceDetector
    {
        try {
            $detector = new DeviceDetector(userAgent: $userAgent);
            $detector->parse();

            return $detector;
        } catch (Throwable) {
            return new DeviceDetector('');
        }
    }

    /**
     * Format the device name (OS + Client) from a detector instance.
     *
     * @param  DeviceDetector  $device
     * @return string
     */
    private static function getDeviceNameFromDetector(DeviceDetector $device): string
    {
        return implode(' / ', array_filter([
            trim(implode(' ', [$device->getOs('name'), $device->getOs('version')])),
            trim(implode(' ', [$device->getClient('name'), $device->getClient('version')])),
        ])) ?? 'Unknown';
    }
}
