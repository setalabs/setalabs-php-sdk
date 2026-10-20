<?php

declare(strict_types=1);

namespace Setalabs\Tests;

use PHPUnit\Framework\TestCase;
use Setalabs\Signer;

final class SignerTest extends TestCase
{
    public function testSignMatchesGatewayAlgorithm(): void
    {
        $apiKey = 'key-1';
        $secret = 'secret-1';
        $timestamp = '1700000000';
        $pathWithQuery = '/v1/api/uuid/items?q=1';

        $expected = hash_hmac('sha256', $apiKey . $secret . $timestamp . $pathWithQuery, $secret);

        self::assertSame($expected, Signer::sign($apiKey, $secret, $timestamp, $pathWithQuery));
        self::assertSame(strtolower($expected), $expected);
    }
}
