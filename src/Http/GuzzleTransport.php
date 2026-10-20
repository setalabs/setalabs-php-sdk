<?php

declare(strict_types=1);

namespace Setalabs\Http;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Setalabs\Exception\TransportException;
use Setalabs\Response;

final class GuzzleTransport implements Transport
{
    private readonly ClientInterface $client;

    public function __construct(?ClientInterface $client = null)
    {
        $this->client = $client ?? new Client([
            'http_errors' => false,
            'allow_redirects' => false,
        ]);
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response
    {
        if (!$this->hasHeader($headers, 'Expect')) {
            $headers['Expect'] = '';
        }

        $options = [
            'headers' => $headers,
            'timeout' => $timeout,
            'connect_timeout' => min(10.0, $timeout),
            'http_errors' => false,
            'allow_redirects' => false,
        ];
        if ($body !== null) {
            $options['body'] = $body;
        }

        try {
            $response = $this->client->request($method, $url, $options);
        } catch (GuzzleException $e) {
            $message = $e->getMessage() !== '' ? $e->getMessage() : 'Request failed.';
            throw new TransportException($message, (int) $e->getCode(), $e);
        }

        return new Response(
            $response->getStatusCode(),
            $response->getHeaders(),
            (string) $response->getBody(),
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function hasHeader(array $headers, string $name): bool
    {
        foreach (array_keys($headers) as $headerName) {
            if (strcasecmp($headerName, $name) === 0) {
                return true;
            }
        }

        return false;
    }
}
