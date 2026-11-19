<?php

namespace Tests\Feature;

use App\Services\NotificationSignal;
use App\Services\NotificationStreamTicket;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class NotificationStreamTest extends TestCase
{
    public function test_socket_server_authenticates_and_only_signals_the_matching_user(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($probe, false);
        fclose($probe);
        $directory = storage_path('framework/testing/streams-'.Str::uuid());
        $key = 'base64:'.base64_encode(random_bytes(32));
        config(['app.key' => $key, 'notifications.signal_path' => $directory]);
        $process = new Process([PHP_BINARY, 'artisan', 'notifications:serve', '--listen='.$address], base_path(), [
            'APP_KEY' => $key, 'NOTIFICATION_SIGNAL_PATH' => $directory,
            'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1',
        ]);
        $connections = [];
        try {
            $process->start();
            $deadline = microtime(true) + 10;
            do {
                $socket = @stream_socket_client('tcp://'.$address, $errno, $error, 0.1);
                if ($socket) {
                    fclose($socket);
                    break;
                }
                usleep(50000);
            } while (microtime(true) < $deadline && $process->isRunning());
            $this->assertTrue($process->isRunning());

            $tickets = app(NotificationStreamTicket::class);
            foreach ([11, 22] as $userId) {
                $socket = stream_socket_client('tcp://'.$address, $errno, $error, 2);
                stream_set_blocking($socket, false);
                $connections[$userId] = $socket;
                fwrite($socket, 'GET /notification-events?ticket='.$tickets->issue($userId)." HTTP/1.1\r\nHost: localhost\r\n\r\n");
                $initial = $this->readUntil($socket, '"revision":"0"');
                $this->assertStringContainsString('200 OK', $initial);
                $this->assertStringContainsString('event: changed', $initial);
            }
            app(NotificationSignal::class)->publish(11);
            $revision = app(NotificationSignal::class)->revision(11);
            $this->assertStringContainsString($revision, $this->readUntil($connections[11], $revision));
            $this->assertSame('', stream_get_contents($connections[22]));

            $socket = stream_socket_client('tcp://'.$address, $errno, $error, 2);
            stream_set_blocking($socket, false);
            stream_set_blocking($socket, false);
            $connections[] = $socket;
            fwrite($socket, "GET /notification-events?ticket=forged HTTP/1.1\r\nHost: localhost\r\n\r\n");
            $this->assertStringContainsString('403 Forbidden', $this->readUntil($socket, '403 Forbidden'));

            foreach (['http://localhost:8000' => true, 'http://other.test' => false] as $origin => $allowed) {
                $socket = stream_socket_client('tcp://'.$address, $errno, $error, 2);
                stream_set_blocking($socket, false);
                $connections[] = $socket;
                $ticket = $tickets->issue(11, 'http://localhost:8000');
                fwrite($socket, 'GET /notification-events?ticket='.$ticket." HTTP/1.1\r\nHost: localhost\r\nOrigin: ".$origin."\r\n\r\n");
                $response = $this->readUntil($socket, $allowed ? 'event: changed' : '403 Forbidden');
                $this->assertStringContainsString($allowed ? '200 OK' : '403 Forbidden', $response);
                if ($allowed) {
                    $this->assertStringContainsString('Access-Control-Allow-Origin: http://localhost:8000', $response);
                }
            }
        } finally {
            foreach ($connections as $connection) {
                fclose($connection);
            }
            $process->stop(1);
            File::deleteDirectory($directory);
        }
    }

    private function readUntil($socket, string $expected): string
    {
        $result = '';
        $deadline = microtime(true) + 4;
        do {
            $read = [$socket];
            $write = $except = null;
            if (stream_select($read, $write, $except, 0, 100000)) {
                $result .= stream_get_contents($socket);
            }
            if (str_contains($result, $expected) || feof($socket)) {
                break;
            }
        } while (microtime(true) < $deadline);

        return $result;
    }
}
