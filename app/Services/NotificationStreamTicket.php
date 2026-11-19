<?php

namespace App\Services;

use RuntimeException;

class NotificationStreamTicket
{
    public function issue(int $userId, string $origin = ''): string
    {
        $encodedOrigin = $origin === '' ? '-' : rtrim(strtr(base64_encode($origin), '+/', '-_'), '=');
        $payload = $userId.'.'.(time() + config('notifications.ticket_lifetime')).'.'.bin2hex(random_bytes(16)).'.'.$encodedOrigin;

        return $payload.'.'.$this->signature($payload);
    }

    public function verify(string $ticket): ?array
    {
        if (! preg_match('/^([1-9][0-9]*)\.([0-9]{10})\.([a-f0-9]{32})\.([A-Za-z0-9_-]{1,512})\.([a-f0-9]{64})$/D', $ticket, $parts)) {
            return null;
        }
        $expires = (int) $parts[2];
        if ($expires <= time() || $expires > time() + config('notifications.ticket_lifetime')) {
            return null;
        }
        if (! hash_equals($this->signature($parts[1].'.'.$parts[2].'.'.$parts[3].'.'.$parts[4]), $parts[5])) {
            return null;
        }

        $origin = $parts[4] === '-' ? '' : base64_decode(strtr($parts[4], '-_', '+/'), true);
        if ($origin === false || preg_match('/[\r\n]/', $origin)) {
            return null;
        }

        return ['user_id' => (int) $parts[1], 'expires' => $expires, 'origin' => $origin];
    }

    private function signature(string $payload): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new RuntimeException('APP_KEY is required for notification tickets.');
        }

        return hash_hmac('sha256', 'notification-stream:'.$payload, $key);
    }
}
