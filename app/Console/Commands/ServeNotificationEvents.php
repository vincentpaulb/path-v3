<?php

namespace App\Console\Commands;

use App\Services\NotificationSignal;
use App\Services\NotificationStreamTicket;
use Illuminate\Console\Command;

class ServeNotificationEvents extends Command
{
    protected $signature = 'notifications:serve {--listen= : TCP address; defaults to notifications.listen}';

    protected $description = 'Serve authenticated notification change signals using native PHP SSE sockets';

    public function handle(NotificationStreamTicket $tickets, NotificationSignal $signals): int
    {
        $address = $this->option('listen') ?: config('notifications.listen');
        $server = @stream_socket_server('tcp://'.$address, $errno, $error);
        if (! $server) {
            $this->error("Cannot listen on {$address}: {$error} ({$errno})");

            return self::FAILURE;
        }
        stream_set_blocking($server, false);
        $this->info("Notification stream listening on {$address}");
        $clients = [];
        $nextTick = 0;

        try {
            while (true) {
                $read = [$server];
                $write = [];
                foreach ($clients as $client) {
                    $read[] = $client['socket'];
                    if ($client['output'] !== '') {
                        $write[] = $client['socket'];
                    }
                }
                $except = null;
                if (@stream_select($read, $write, $except, 0, 250000) === false) {
                    continue;
                }
                foreach ($read as $socket) {
                    if ($socket === $server) {
                        $connection = @stream_socket_accept($server, 0);
                        if (! $connection) {
                            continue;
                        }
                        if (count($clients) >= config('notifications.max_clients')) {
                            fclose($connection);

                            continue;
                        }
                        stream_set_blocking($connection, false);
                        $clients[(int) $connection] = [
                            'socket' => $connection, 'input' => '', 'output' => '',
                            'user_id' => null, 'expires' => time() + 5,
                            'revision' => null, 'heartbeat' => time(), 'close' => false,
                        ];

                        continue;
                    }
                    $id = (int) $socket;
                    $chunk = @fread($socket, 4096);
                    if ($chunk === false || feof($socket)) {
                        $this->disconnect($clients, $id);

                        continue;
                    }
                    if ($clients[$id]['user_id'] !== null || $clients[$id]['close']) {
                        continue;
                    }
                    $clients[$id]['input'] .= $chunk;
                    if (strlen($clients[$id]['input']) > 8192) {
                        $this->disconnect($clients, $id);

                        continue;
                    }
                    if (! str_contains($clients[$id]['input'], "\r\n\r\n")) {
                        continue;
                    }
                    $request = $clients[$id]['input'];
                    $auth = null;
                    if (preg_match('~^GET (/[^ ]*) HTTP/1\.[01]\r\n~', $request, $match)) {
                        $url = parse_url($match[1]);
                        if (is_array($url) && ($url['path'] ?? '') === '/notification-events') {
                            parse_str($url['query'] ?? '', $query);
                            if (is_string($query['ticket'] ?? null)) {
                                $auth = $tickets->verify($query['ticket']);
                            }
                        }
                    }
                    $clients[$id]['input'] = '';
                    if ($auth && preg_match('/\r\nOrigin:\s*([^\r\n]+)\r\n/i', $request, $origin)) {
                        if (! hash_equals($auth['origin'], trim($origin[1]))) {
                            $auth = null;
                        }
                    }
                    if (! $auth) {
                        $clients[$id]['output'] = "HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
                        $clients[$id]['close'] = true;

                        continue;
                    }
                    $clients[$id]['user_id'] = $auth['user_id'];
                    $clients[$id]['expires'] = $auth['expires'];
                    $cors = $auth['origin'] !== '' ? 'Access-Control-Allow-Origin: '.$auth['origin']."\r\nVary: Origin\r\n" : '';
                    $clients[$id]['output'] = "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nCache-Control: no-store\r\nX-Accel-Buffering: no\r\n".$cors."Connection: close\r\n\r\nretry: 5000\n\n";
                }
                foreach ($write as $socket) {
                    $id = (int) $socket;
                    if (! isset($clients[$id])) {
                        continue;
                    }
                    $written = @fwrite($socket, $clients[$id]['output']);
                    if ($written === false) {
                        $this->disconnect($clients, $id);

                        continue;
                    }
                    $clients[$id]['output'] = substr($clients[$id]['output'], $written);
                    if ($clients[$id]['close'] && $clients[$id]['output'] === '') {
                        $this->disconnect($clients, $id);
                    }
                }
                if (microtime(true) < $nextTick) {
                    continue;
                }
                $nextTick = microtime(true) + 1;
                $revisions = [];
                foreach (array_keys($clients) as $id) {
                    $client = &$clients[$id];
                    if ($client['expires'] <= time() || strlen($client['output']) > 16384) {
                        unset($client);
                        $this->disconnect($clients, $id);

                        continue;
                    }
                    if ($client['user_id'] === null) {
                        unset($client);

                        continue;
                    }
                    $userId = $client['user_id'];
                    $revision = $revisions[$userId] ??= $signals->revision($userId);
                    if ($client['revision'] !== $revision) {
                        // Invalidation only: actual content always requires an authenticated HTTP request.
                        $client['output'] .= 'event: changed'."\n".'data: '.json_encode(['revision' => $revision])."\n\n";
                        $client['revision'] = $revision;
                    }
                    if (time() - $client['heartbeat'] >= 15) {
                        $client['output'] .= ": heartbeat\n\n";
                        $client['heartbeat'] = time();
                    }
                    unset($client);
                }
            }
        } finally {
            foreach (array_keys($clients) as $id) {
                $this->disconnect($clients, $id);
            }
            fclose($server);
        }
    }

    private function disconnect(array &$clients, int $id): void
    {
        fclose($clients[$id]['socket']);
        unset($clients[$id]);
    }
}
