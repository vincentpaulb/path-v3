<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class WebPushEndpoint implements ValidationRule
{
    public static function allowed(string $endpoint): bool
    {
        $url = parse_url($endpoint);
        if (! is_array($url) || ($url['scheme'] ?? '') !== 'https'
            || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])
            || (isset($url['port']) && $url['port'] !== 443)) {
            return false;
        }
        $host = strtolower($url['host'] ?? '');

        return $host === 'fcm.googleapis.com'
            || $host === 'updates.push.services.mozilla.com'
            || str_ends_with($host, '.push.services.mozilla.com')
            || str_ends_with($host, '.notify.windows.com')
            || $host === 'web.push.apple.com';
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::allowed($value)) {
            $fail('This browser push service is not supported.');
        }
    }
}
