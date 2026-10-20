<?php

declare(strict_types=1);

namespace Setalabs\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Setalabs\Client;
use Setalabs\Exception\UnauthorizedException;
use Setalabs\Response;
use Setalabs\Signer;
use Setalabs\Tests\Http\RecordingTransport;
use Setalabs\ValueObjects\Gateway;

final class ClientTest extends TestCase
{
    private const UUID = '05141812-7772-403e-9ca6-5e8e8bf22730';
    private const KEY = 'key-1';
    private const SECRET = 'secret-1';

    public function testSignedGetUsesGatewayPathAndKeepsQueryOrder(): void
    {
        $transport = new RecordingTransport(new Response(200, [
            'X-Request-Id' => 'req-1',
            'Content-Type' => 'application/json',
        ], '{"ok":true}'));
        $client = $this->client($transport);

        $response = $client->get('/catalog', [
            'b' => '2',
            'a' => '1',
        ]);

        self::assertTrue($response->successful());
        self::assertSame(['ok' => true], $response->json());
        self::assertSame('req-1', $response->requestId());

        $call = $transport->calls[0];
        $pathWithQuery = '/v1/api/' . self::UUID . '/catalog?b=2&a=1';
        self::assertSame('GET', $call['method']);
        self::assertSame(Gateway::URL . $pathWithQuery, $call['url']);
        self::assertSame(self::KEY, $call['headers']['X-Api-Key']);
        self::assertArrayNotHasKey('X-Api-Secret', $call['headers']);
        self::assertSame(
            Signer::sign(self::KEY, self::SECRET, $call['headers']['X-Api-Timestamp'], $pathWithQuery),
            $call['headers']['X-Api-Sign']
        );
    }

    public function testSignatureUsesDecodedPathAndEncodedQuery(): void
    {
        $transport = new RecordingTransport(new Response(200, [], '{"items":[]}'));
        $client = $this->client($transport);

        $client->get('/product/a b', ['q' => 'x y']);

        $call = $transport->calls[0];
        $signed = '/v1/api/' . self::UUID . '/product/a b?q=x%20y';
        $wire = Gateway::URL . '/v1/api/' . self::UUID . '/product/a%20b?q=x%20y';
        self::assertSame($wire, $call['url']);
        self::assertSame(
            Signer::sign(self::KEY, self::SECRET, $call['headers']['X-Api-Timestamp'], $signed),
            $call['headers']['X-Api-Sign']
        );
    }

    public function testKeyWithoutSecretIsNotSigned(): void
    {
        $transport = new RecordingTransport(new Response(200, [], '{}'));
        $client = new Client(self::UUID, self::KEY, '', Gateway::URL, 30.0, $transport);

        $client->get('/langs');

        $headers = $transport->calls[0]['headers'];
        self::assertSame(self::KEY, $headers['X-Api-Key']);
        self::assertArrayNotHasKey('X-Api-Sign', $headers);
        self::assertArrayNotHasKey('X-Api-Timestamp', $headers);
        self::assertArrayNotHasKey('X-Api-Secret', $headers);
        self::assertSame(Gateway::URL . '/v1/api/' . self::UUID . '/langs', $transport->calls[0]['url']);
    }

    public function testPostSendsJsonBody(): void
    {
        $transport = new RecordingTransport(new Response(201, [], '{"id":"1"}'));
        $client = $this->client($transport);

        $response = $client->post('/orders', ['sku' => 'a/b']);

        self::assertSame(201, $response->statusCode);
        self::assertSame('{"sku":"a/b"}', $transport->calls[0]['body']);
        self::assertSame('application/json', $transport->calls[0]['headers']['Content-Type']);
        self::assertSame('POST', $transport->calls[0]['method']);
    }

    public function testGatewayErrorIsThrown(): void
    {
        $transport = new RecordingTransport(new Response(401, [
            'X-Request-Id' => 'req-9',
        ], '{"error":"unauthorized","reason":"Invalid API signature."}'));
        $client = $this->client($transport);

        try {
            $client->get('/catalog');
            self::fail('Expected an unauthorized error.');
        } catch (UnauthorizedException $e) {
            self::assertSame(401, $e->statusCode);
            self::assertSame('Invalid API signature.', $e->getMessage());
            self::assertSame('req-9', $e->requestId);
        }
    }

    public function testReservedSecretHeaderIsDropped(): void
    {
        $transport = new RecordingTransport(new Response(200, [], '{}'));
        $client = $this->client($transport);

        $client->get('/catalog', [], [
            'X-Api-Secret' => 'do-not-send',
            'X-Trace' => '1',
        ]);

        $headers = $transport->calls[0]['headers'];
        self::assertArrayNotHasKey('X-Api-Secret', $headers);
        self::assertSame('1', $headers['X-Trace']);
    }

    public function testWithApiTargetsAnotherUuid(): void
    {
        $transport = new RecordingTransport(new Response(200, [], '{}'));
        $client = $this->client($transport)->withApi('another-api', 'other-key', 'other-secret');

        $client->get('/health');

        $call = $transport->calls[0];
        self::assertStringContainsString('/v1/api/another-api/health', $call['url']);
        self::assertSame('other-key', $call['headers']['X-Api-Key']);
        $path = '/v1/api/another-api/health';
        self::assertSame(
            Signer::sign('other-key', 'other-secret', $call['headers']['X-Api-Timestamp'], $path),
            $call['headers']['X-Api-Sign']
        );
    }

    public function testGatewayDefaultsToProduction(): void
    {
        $transport = new RecordingTransport(new Response(200, [], '{}'));
        $client = new Client(self::UUID, self::KEY, self::SECRET, null, 30.0, $transport);

        $client->get('/catalog');

        self::assertSame(
            Gateway::URL . '/v1/api/' . self::UUID . '/catalog',
            $transport->calls[0]['url']
        );
    }

    public function testGatewayCanBeReplaced(): void
    {
        $transport = new RecordingTransport(new Response(200, [], '{}'));
        $client = new Client(self::UUID, self::KEY, self::SECRET, 'https://gw.example.test/', 30.0, $transport);

        $client->get('/catalog');

        self::assertSame(
            'https://gw.example.test/v1/api/' . self::UUID . '/catalog',
            $transport->calls[0]['url']
        );
    }

    public function testGatewayMustBeTheRootUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Client(self::UUID, self::KEY, self::SECRET, Gateway::URL . '/v1/api/' . self::UUID);
    }

    public function testQueryInPathIsRejected(): void
    {
        $client = $this->client(new RecordingTransport(new Response(200, [], '{}')));

        $this->expectException(InvalidArgumentException::class);
        $client->get('/catalog?language=en');
    }

    private function client(RecordingTransport $transport): Client
    {
        return new Client(self::UUID, self::KEY, self::SECRET, Gateway::URL, 30.0, $transport);
    }
}
