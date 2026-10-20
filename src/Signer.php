<?php

declare(strict_types=1);

namespace Setalabs;

/**
 * Request signature expected by the gateway.
 *
 * X-Api-Sign = HMAC-SHA256(apiKey + secret + timestamp + pathWithQuery, secret)
 * as lowercase hex. pathWithQuery is the decoded request path plus the raw query
 * string, for example /v1/api/{uuid}/items?page=1.
 */
final class Signer
{
    public static function sign(string $apiKey, string $secret, string $timestamp, string $pathWithQuery): string
    {
        return hash_hmac('sha256', $apiKey . $secret . $timestamp . $pathWithQuery, $secret);
    }
}
