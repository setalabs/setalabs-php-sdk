<?php

declare(strict_types=1);

namespace Setalabs\Http;

use Setalabs\Response;

interface Transport
{
    /**
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response;
}
