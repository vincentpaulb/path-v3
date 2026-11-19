<?php

namespace App\Services;

class WebPushSettings
{
    public function keys(): array
    {
        if (config('webpush.public_key') && config('webpush.private_key')) {
            return ['publicKey' => config('webpush.public_key'), 'privateKey' => config('webpush.private_key')];
        }
        $path = config('webpush.key_path');

        return is_file($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
    }

    public function enabled(): bool
    {
        $keys = $this->keys();

        return config('webpush.enabled') && ! empty($keys['publicKey']) && ! empty($keys['privateKey']);
    }
}
