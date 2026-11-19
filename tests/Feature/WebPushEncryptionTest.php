<?php

namespace Tests\Feature;

use App\Models\WebPushSubscription;
use App\Services\WebPushTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\VAPID;
use Tests\TestCase;

class WebPushEncryptionTest extends TestCase
{
    public function test_real_library_encrypts_the_payload_and_signs_the_push_request(): void
    {
        $server = VAPID::createVapidKeys();
        $browserPrivate = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $browserDetails = openssl_pkey_get_details($browserPrivate)['ec'];
        $browserPublic = "\x04".str_pad($browserDetails['x'], 32, "\0", STR_PAD_LEFT).str_pad($browserDetails['y'], 32, "\0", STR_PAD_LEFT);
        $auth = random_bytes(16);
        config(['webpush.enabled' => true, 'webpush.public_key' => $server['publicKey'], 'webpush.private_key' => $server['privateKey']]);
        $history = [];
        $handler = HandlerStack::create(new MockHandler([new Response(201)]));
        $handler->push(Middleware::history($history));
        $transport = new WebPushTransport(new Client(['handler' => $handler, 'allow_redirects' => false]));
        $subscription = new WebPushSubscription([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test',
            'public_key' => rtrim(strtr(base64_encode($browserPublic), '+/', '-_'), '='),
            'auth_token' => rtrim(strtr(base64_encode($auth), '+/', '-_'), '='),
        ]);
        $report = $transport->send($subscription, ['title' => 'Private encryption test']);
        $this->assertTrue($report['success']);
        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertSame('aes128gcm', $request->getHeaderLine('Content-Encoding'));
        $this->assertStringStartsWith('vapid t=', $request->getHeaderLine('Authorization'));
        $this->assertSame('3600', $request->getHeaderLine('TTL'));
        $this->assertStringNotContainsString('Private encryption test', (string) $request->getBody());
        $this->assertGreaterThan(100, strlen((string) $request->getBody()));

        // Independently decrypt RFC 8291 framing with PHP/OpenSSL, as a browser would.
        $body = (string) $request->getBody();
        $salt = substr($body, 0, 16);
        $publicLength = ord($body[20]);
        $senderPublic = substr($body, 21, $publicLength);
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$senderPublic;
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
        $shared = openssl_pkey_derive($pem, $browserPrivate, 32);
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0".$browserPublic.$senderPublic, $auth);
        $key = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $ciphertext = substr($body, 21 + $publicLength);
        $plain = openssl_decrypt(substr($ciphertext, 0, -16), 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $nonce, substr($ciphertext, -16));
        $this->assertSame('{"title":"Private encryption test"}'."\x02", rtrim($plain, "\0"));
    }
}
