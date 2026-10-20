<?php

declare(strict_types=1);

namespace Setalabs;

use InvalidArgumentException;
use JsonException;
use Setalabs\Exception\ErrorMapper;
use Setalabs\Http\GuzzleTransport;
use Setalabs\Http\Transport;
use Setalabs\ValueObjects\Gateway;

/**
 * Calls one API published on the Setalabs gateway.
 *
 * The gateway address, API uuid, key and secret are separate: a key belongs to
 * a single API, and the secret is used only to sign the request. It is never sent.
 */
final class Client
{
    public const VERSION = '0.1.0';

    /** @var list<string> */
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'];

    /** @var list<string> */
    private const RESERVED_HEADERS = [
        'x-api-key',
        'x-api-secret',
        'x-api-sign',
        'x-api-timestamp',
    ];

    private readonly string $gateway;

    private readonly string $apiUuid;

    private readonly string $apiKey;

    private readonly string $apiSecret;

    private readonly Transport $transport;

    public function __construct(
        string $apiUuid,
        string $apiKey,
        string $apiSecret = '',
        ?string $gateway = null,
        private readonly float $timeout = 30.0,
        ?Transport $transport = null,
    ) {
        if ($gateway === null || trim($gateway) === '') {
            $gateway = Gateway::URL;
        }
        $this->gateway = rtrim(trim($gateway), '/');
        $this->apiUuid = strtolower(trim($apiUuid));
        $this->apiKey = trim($apiKey);
        $this->apiSecret = trim($apiSecret);
        $this->transport = $transport ?? new GuzzleTransport();
        $this->assertConfig();
    }

    /**
     * Same gateway and timeout, another API.
     * A key is valid only for the API it was issued for.
     */
    public function withApi(string $apiUuid, string $apiKey, string $apiSecret = ''): self
    {
        return new self(
            $apiUuid,
            $apiKey,
            $apiSecret,
            $this->gateway,
            $this->timeout,
            $this->transport,
        );
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, scalar> $headers
     */
    public function get(string $path, array $query = [], array $headers = []): Response
    {
        return $this->request('GET', $path, [
            'query' => $query,
            'headers' => $headers,
        ]);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, scalar> $headers
     */
    public function post(string $path, mixed $json = null, array $query = [], array $headers = []): Response
    {
        return $this->request('POST', $path, $this->jsonOptions($json, $query, $headers));
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, scalar> $headers
     */
    public function put(string $path, mixed $json = null, array $query = [], array $headers = []): Response
    {
        return $this->request('PUT', $path, $this->jsonOptions($json, $query, $headers));
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, scalar> $headers
     */
    public function patch(string $path, mixed $json = null, array $query = [], array $headers = []): Response
    {
        return $this->request('PATCH', $path, $this->jsonOptions($json, $query, $headers));
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, scalar> $headers
     */
    public function delete(string $path, mixed $json = null, array $query = [], array $headers = []): Response
    {
        return $this->request('DELETE', $path, $this->jsonOptions($json, $query, $headers));
    }

    /**
     * @param array{
     *     query?: array<string|int, mixed>|string,
     *     json?: mixed,
     *     body?: string,
     *     headers?: array<string, scalar>,
     *     timeout?: float|int
     * } $options
     */
    public function request(string $method, string $path, array $options = []): Response
    {
        $method = strtoupper($method);
        if (!in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException('Unsupported HTTP method "' . $method . '".');
        }

        $headers = $this->headers($options['headers'] ?? []);
        $queryString = $this->queryString($options['query'] ?? []);
        $decodedPath = $this->decodedPath($path);
        $pathWithQuery = $queryString === '' ? $decodedPath : $decodedPath . '?' . $queryString;
        $body = $this->body($options, $headers);

        $headers['X-Api-Key'] = $this->apiKey;
        if ($this->apiSecret !== '') {
            $timestamp = (string) time();
            $headers['X-Api-Timestamp'] = $timestamp;
            $headers['X-Api-Sign'] = Signer::sign($this->apiKey, $this->apiSecret, $timestamp, $pathWithQuery);
        }

        $url = $this->gateway . $this->encodePath($decodedPath);
        if ($queryString !== '') {
            $url .= '?' . $queryString;
        }

        $timeout = $this->timeout;
        if (array_key_exists('timeout', $options)) {
            $timeout = (float) $options['timeout'];
            if ($timeout <= 0) {
                throw new InvalidArgumentException('Timeout must be greater than zero.');
            }
        }

        $response = $this->transport->send($method, $url, $headers, $body, $timeout);
        if ($response->statusCode >= 400) {
            throw ErrorMapper::fromResponse($response);
        }

        return $response;
    }

    private function assertConfig(): void
    {
        if ($this->timeout <= 0) {
            throw new InvalidArgumentException('Timeout must be greater than zero.');
        }

        $parts = parse_url($this->gateway);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('Gateway address must be an absolute URL, for example https://gw.setalabs.com.');
        }
        if (!in_array(strtolower((string) $parts['scheme']), ['https', 'http'], true)) {
            throw new InvalidArgumentException('Gateway address must use http or https.');
        }
        $path = $parts['path'] ?? '';
        if ($path !== '' && $path !== '/') {
            throw new InvalidArgumentException(
                'Gateway address must be the gateway root, without /v1/api/{uuid}. Pass the API uuid separately.'
            );
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Gateway address must not contain a query or fragment.');
        }
        if ($this->apiUuid === '' || preg_match('/^[a-z0-9_-]+$/', $this->apiUuid) !== 1) {
            throw new InvalidArgumentException('API uuid must contain only lowercase letters, digits, "_" and "-".');
        }
        if ($this->apiKey === '') {
            throw new InvalidArgumentException('API key is required.');
        }
        $this->assertHeaderSafe('API key', $this->apiKey);
        $this->assertHeaderSafe('API secret', $this->apiSecret);
    }

    private function assertHeaderSafe(string $label, string $value): void
    {
        if (preg_match('/[\r\n]/', $value) === 1) {
            throw new InvalidArgumentException($label . ' must not contain line breaks.');
        }
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, scalar> $headers
     * @return array{query: array<string, mixed>, headers: array<string, scalar>, json?: mixed}
     */
    private function jsonOptions(mixed $json, array $query, array $headers): array
    {
        $options = [
            'query' => $query,
            'headers' => $headers,
        ];
        if ($json !== null) {
            $options['json'] = $json;
        }

        return $options;
    }

    /**
     * @param array<mixed> $headers
     * @return array<string, string>
     */
    private function headers(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || $name === '') {
                throw new InvalidArgumentException('Header name must be a non-empty string.');
            }
            if (is_array($value) || is_object($value)) {
                throw new InvalidArgumentException('Header "' . $name . '" must be a scalar.');
            }
            if (in_array(strtolower($name), self::RESERVED_HEADERS, true)) {
                continue;
            }
            $stringValue = (string) $value;
            $this->assertHeaderSafe('Header "' . $name . '"', $stringValue);
            $normalized[$name] = $stringValue;
        }
        if (!$this->hasHeader($normalized, 'Accept')) {
            $normalized['Accept'] = 'application/json';
        }
        if (!$this->hasHeader($normalized, 'User-Agent')) {
            $normalized['User-Agent'] = 'setalabs-php-sdk/' . self::VERSION;
        }

        return $normalized;
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

    /**
     * @param array<string|int, mixed>|string $query
     */
    private function queryString(array|string $query): string
    {
        if (is_string($query)) {
            return ltrim($query, '?');
        }

        $filtered = [];
        foreach ($query as $key => $value) {
            if ($key === '') {
                throw new InvalidArgumentException('Query parameter name must not be empty.');
            }
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif (is_array($value)) {
                // Nested lists are encoded by http_build_query.
            } elseif (!is_scalar($value)) {
                throw new InvalidArgumentException('Query parameter "' . $key . '" must be a scalar or a list.');
            }
            $filtered[$key] = $value;
        }
        if ($filtered === []) {
            return '';
        }

        return http_build_query($filtered, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, string> $headers
     */
    private function body(array $options, array &$headers): ?string
    {
        $hasJson = array_key_exists('json', $options);
        $hasBody = array_key_exists('body', $options);
        if ($hasJson && $hasBody) {
            throw new InvalidArgumentException('Pass either json or body, not both.');
        }
        if ($hasJson) {
            if (!$this->hasHeader($headers, 'Content-Type')) {
                $headers['Content-Type'] = 'application/json';
            }

            return $this->encodeJson($options['json']);
        }
        if ($hasBody) {
            if (!is_string($options['body'])) {
                throw new InvalidArgumentException('Body must be a string.');
            }

            return $options['body'];
        }

        return null;
    }

    private function encodeJson(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('JSON body could not be encoded.', 0, $e);
        }
    }

    private function decodedPath(string $path): string
    {
        $path = trim($path);
        if (str_contains($path, '?') || str_contains($path, '#')) {
            throw new InvalidArgumentException('Pass query parameters separately from the path.');
        }
        $relative = ($path === '' || $path === '/') ? '' : '/' . ltrim($path, '/');

        return Gateway::API_PREFIX . '/' . $this->apiUuid . $relative;
    }

    private function encodePath(string $decodedPath): string
    {
        $encoded = [];
        foreach (explode('/', $decodedPath) as $segment) {
            $encoded[] = $segment === '' ? '' : rawurlencode($segment);
        }

        return implode('/', $encoded);
    }
}
