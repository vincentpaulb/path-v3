<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class NotificationSignal
{
    public function publish(int $userId): void
    {
        File::ensureDirectoryExists(config('notifications.signal_path'));
        if (file_put_contents($this->path($userId), (string) Str::uuid(), LOCK_EX) === false) {
            throw new RuntimeException('Cannot write notification signal.');
        }
    }

    public function revision(int $userId): string
    {
        $path = $this->path($userId);
        if (! is_file($path)) {
            return '0';
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot read notification signal.');
        }
        try {
            flock($handle, LOCK_SH);

            return trim(stream_get_contents($handle)) ?: '0';
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function path(int $userId): string
    {
        return config('notifications.signal_path').'/'.$userId.'.signal';
    }
}
