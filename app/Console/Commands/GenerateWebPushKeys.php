<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Minishlink\WebPush\VAPID;

class GenerateWebPushKeys extends Command
{
    protected $signature = 'webpush:keys';

    protected $description = 'Create persistent private Web Push signing keys without printing them';

    public function handle(): int
    {
        $path = config('webpush.key_path');
        if (is_file($path) || (config('webpush.public_key') && config('webpush.private_key'))) {
            $this->info('Web Push keys already exist; preserved them.');

            return self::SUCCESS;
        }
        $keys = VAPID::createVapidKeys();
        File::ensureDirectoryExists(dirname($path));
        $file = fopen($path, 'x');
        if ($file === false) {
            $this->error('Unable to create the key file.');

            return self::FAILURE;
        }
        try {
            chmod($path, 0600);
            fwrite($file, json_encode($keys, JSON_THROW_ON_ERROR));
        } finally {
            fclose($file);
        }
        $this->info('Web Push keys created in private application storage. Keep a secure backup.');

        return self::SUCCESS;
    }
}
