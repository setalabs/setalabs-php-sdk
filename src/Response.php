<?php

declare(strict_types=1);

namespace Setalabs;

use JsonException;

final class Response
{
    public readonly int $statusCode;

    /** @var array<string, list<string>> */
    public readonly array $headers;

    public readonly string $body;

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(int $statusCode, array $headers, string $body)
    {
        $normalized = [];
        foreach ($headers as $name => $values) {
            $key = strtolower((string) $name);
            if (is_string($values)) {
                $values = [$values];
            }
            foreach ($values as $value) {
                $normalized[$key][] = (string) $value;
            }
        }

        $this->statusCode = $statusCode;
        $this->headers = $normalized;
        $this->body = $body;
    }

    public function successful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    public function header(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? [];

        return $values[0] ?? null;
    }

    public function requestId(): ?string
    {
        return $this->header('X-Request-Id');
    }

    public function json(): mixed
    {
        if ($this->body === '') {
            throw new Exception\SetalabsException('Response body is empty.');
        }

        try {
            return json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new Exception\SetalabsException('Response body is not valid JSON.', 0, $e);
        }
    }
}
