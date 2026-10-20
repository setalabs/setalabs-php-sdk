<?php

declare(strict_types=1);

namespace Setalabs\Tests\Http;

use Setalabs\Http\Transport;
use Setalabs\Response;

final class RecordingTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, timeout: float}> */
    public array $calls = [];

    public function __construct(private readonly Response $response)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response
    {
        $this->calls[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'timeout' => $timeout,
        ];

        return $this->response;
    }
}
