<?php

declare(strict_types=1);

namespace Setalabs\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Setalabs\ErrorCode;
use Setalabs\Exception\ApiException;
use Setalabs\Exception\BadGatewayException;
use Setalabs\Exception\BadRequestException;
use Setalabs\Exception\ErrorMapper;
use Setalabs\Exception\ForbiddenException;
use Setalabs\Exception\NotFoundException;
use Setalabs\Exception\RateLimitException;
use Setalabs\Exception\ServerException;
use Setalabs\Exception\UnauthorizedException;
use Setalabs\Exception\UsageLimitException;
use Setalabs\Exception\ValidationException;
use Setalabs\Response;

final class ErrorMapperTest extends TestCase
{
    /**
     * @param class-string<ApiException> $expectedClass
     */
    #[DataProvider('gatewayErrors')]
    public function testMapsGatewayError(
        int $statusCode,
        string $body,
        string $expectedClass,
        string $expectedMessage,
    ): void {
        $exception = ErrorMapper::fromResponse(new Response($statusCode, [
            'X-Request-Id' => 'abc123',
        ], $body));

        self::assertInstanceOf($expectedClass, $exception);
        self::assertSame($expectedMessage, $exception->getMessage());
        self::assertSame($statusCode, $exception->statusCode);
        self::assertSame('abc123', $exception->requestId);
    }

    /**
     * @return array<string, array{int, string, class-string<ApiException>, string}>
     */
    public static function gatewayErrors(): array
    {
        return [
            'bad request' => [400, self::json(ErrorCode::BAD_REQUEST, 'API has no base URL.'), BadRequestException::class, 'API has no base URL.'],
            'missing key' => [401, self::json(ErrorCode::UNAUTHORIZED, 'Missing API key.'), UnauthorizedException::class, 'Missing API key.'],
            'invalid key' => [401, self::json(ErrorCode::UNAUTHORIZED, 'Invalid API key.'), UnauthorizedException::class, 'Invalid API key.'],
            'missing sign' => [401, self::json(ErrorCode::UNAUTHORIZED, 'Missing API signature.'), UnauthorizedException::class, 'Missing API signature.'],
            'missing timestamp' => [401, self::json(ErrorCode::UNAUTHORIZED, 'Missing API timestamp.'), UnauthorizedException::class, 'Missing API timestamp.'],
            'invalid sign' => [401, self::json(ErrorCode::UNAUTHORIZED, 'Invalid API signature.'), UnauthorizedException::class, 'Invalid API signature.'],
            'blocked key' => [403, self::json(ErrorCode::FORBIDDEN, 'API key is blocked.'), ForbiddenException::class, 'API key is blocked.'],
            'expired key' => [403, self::json(ErrorCode::FORBIDDEN, 'API key subscription has expired.'), ForbiddenException::class, 'API key subscription has expired.'],
            'no tariff' => [403, self::json(ErrorCode::FORBIDDEN, 'API key has no active tariff.'), ForbiddenException::class, 'API key has no active tariff.'],
            'wrong api' => [403, self::json(ErrorCode::FORBIDDEN, 'API key is not valid for this API.'), ForbiddenException::class, 'API key is not valid for this API.'],
            'api not found' => [404, self::json(ErrorCode::NOT_FOUND, 'API not found.'), NotFoundException::class, 'API not found.'],
            'endpoint not found' => [404, self::json(ErrorCode::NOT_FOUND, 'API endpoint not found.'), NotFoundException::class, 'API endpoint not found.'],
            'route not found' => [404, self::json(ErrorCode::NOT_FOUND, null), NotFoundException::class, ErrorCode::NOT_FOUND],
            'upstream down' => [502, self::json(ErrorCode::BAD_GATEWAY, 'Upstream API is unavailable.'), BadGatewayException::class, 'Upstream API is unavailable.'],
            'internal' => [500, self::json(ErrorCode::INTERNAL_ERROR, ''), ServerException::class, ErrorCode::INTERNAL_ERROR],
        ];
    }

    public function testValidationErrorIncludesMissingFields(): void
    {
        $exception = ErrorMapper::fromResponse(new Response(422, [], json_encode([
            'error' => ErrorCode::VALIDATION_ERROR,
            'reason' => 'Required fields are missing.',
            'missing_fields' => ['page', 'limit'],
        ], JSON_THROW_ON_ERROR)));

        self::assertInstanceOf(ValidationException::class, $exception);
        self::assertSame(['page', 'limit'], $exception->missingFields);
        self::assertSame('Required fields are missing. Missing: page, limit.', $exception->getMessage());
    }

    public function testRateLimitReadsRetryAfter(): void
    {
        $exception = ErrorMapper::fromResponse(new Response(429, [
            'Retry-After' => '3',
            'X-RateLimit-Limit' => '10',
        ], json_encode([
            'error' => ErrorCode::RATE_LIMITED,
            'retry_after' => 3,
        ], JSON_THROW_ON_ERROR)));

        self::assertInstanceOf(RateLimitException::class, $exception);
        self::assertSame(3, $exception->retryAfter);
    }

    public function testUsageLimit(): void
    {
        $exception = ErrorMapper::fromResponse(new Response(429, [], json_encode([
            'error' => ErrorCode::USAGE_LIMIT_EXCEEDED,
            'reason' => 'Request limit exceeded.',
        ], JSON_THROW_ON_ERROR)));

        self::assertInstanceOf(UsageLimitException::class, $exception);
        self::assertSame('Request limit exceeded.', $exception->getMessage());
        self::assertNull($exception->retryAfter);
    }

    public function testPlainUpstreamErrorUsesStatus(): void
    {
        $exception = ErrorMapper::fromResponse(new Response(418, [], 'nope'));

        self::assertInstanceOf(ApiException::class, $exception);
        self::assertSame('HTTP 418', $exception->getMessage());
        self::assertSame('', $exception->error);
    }

    private static function json(string $error, ?string $reason): string
    {
        $payload = ['error' => $error];
        if ($reason !== null) {
            $payload['reason'] = $reason;
        }

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }
}
