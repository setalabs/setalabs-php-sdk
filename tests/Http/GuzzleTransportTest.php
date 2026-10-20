<?php

declare(strict_types=1);

namespace Setalabs\Tests\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response as PsrResponse;
use PHPUnit\Framework\TestCase;
use Setalabs\Exception\TransportException;
use Setalabs\Http\GuzzleTransport;

final class GuzzleTransportTest extends TestCase
{
    public function testReturnsStatusHeadersAndBody(): void
    {
        $transport = $this->transport(new MockHandler([
            new PsrResponse(200, ['X-Request-Id' => 'req-1'], '{"ok":true}'),
        ]));

        $response = $transport->send('GET', 'https://gw.setalabs.com/v1/api/x/catalog', [
            'Accept' => 'application/json',
        ], null, 5);

        self::assertSame(200, $response->statusCode);
        self::assertSame('req-1', $response->requestId());
        self::assertSame(['ok' => true], $response->json());
    }

    public function testHttpErrorIsReturnedInsteadOfThrown(): void
    {
        $transport = $this->transport(new MockHandler([
            new PsrResponse(401, [], '{"error":"unauthorized","reason":"Invalid API key."}'),
        ]));

        $response = $transport->send('GET', 'https://gw.setalabs.com/v1/api/x/catalog', [], null, 5);

        self::assertSame(401, $response->statusCode);
        self::assertSame('Invalid API key.', $response->json()['reason']);
    }

    public function testConnectionFailureBecomesTransportException(): void
    {
        $transport = $this->transport(new MockHandler([
            new ConnectException('connection refused', new Request('GET', 'https://gw.setalabs.com')),
        ]));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('connection refused');
        $transport->send('GET', 'https://gw.setalabs.com/v1/api/x/catalog', [], null, 5);
    }

    private function transport(MockHandler $handler): GuzzleTransport
    {
        return new GuzzleTransport(new Client([
            'handler' => HandlerStack::create($handler),
            'http_errors' => false,
        ]));
    }
}
