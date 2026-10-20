<?php

declare(strict_types=1);

namespace Setalabs\Exception;

use Setalabs\ErrorCode;
use Setalabs\Response;

final class ErrorMapper
{
    public static function fromResponse(Response $response): ApiException
    {
        $payload = self::payload($response);
        $error = '';
        $reason = null;
        $missingFields = [];
        if (is_array($payload)) {
            $error = isset($payload['error']) ? (string) $payload['error'] : '';
            if (array_key_exists('reason', $payload) && $payload['reason'] !== null) {
                $reason = (string) $payload['reason'];
            }
            $missingFields = self::missingFields($payload['missing_fields'] ?? null);
        }

        $message = ($reason !== null && $reason !== '')
            ? $reason
            : ($error !== '' ? $error : 'HTTP ' . $response->statusCode);
        if ($missingFields !== []) {
            $message .= ' Missing: ' . implode(', ', $missingFields) . '.';
        }

        $class = self::classFor($response->statusCode, $error);
        $retryAfter = self::retryAfter($response, $payload);

        return new $class(
            $message,
            $response->statusCode,
            $error,
            $reason,
            $response->requestId(),
            $missingFields,
            $retryAfter,
            $response,
        );
    }

    /**
     * @return class-string<ApiException>
     */
    private static function classFor(int $statusCode, string $error): string
    {
        return match ($error) {
            ErrorCode::BAD_REQUEST => BadRequestException::class,
            ErrorCode::UNAUTHORIZED => UnauthorizedException::class,
            ErrorCode::FORBIDDEN => ForbiddenException::class,
            ErrorCode::NOT_FOUND => NotFoundException::class,
            ErrorCode::VALIDATION_ERROR => ValidationException::class,
            ErrorCode::RATE_LIMITED => RateLimitException::class,
            ErrorCode::USAGE_LIMIT_EXCEEDED => UsageLimitException::class,
            ErrorCode::BAD_GATEWAY => BadGatewayException::class,
            ErrorCode::INTERNAL_ERROR => ServerException::class,
            default => match ($statusCode) {
                400 => BadRequestException::class,
                401 => UnauthorizedException::class,
                403 => ForbiddenException::class,
                404 => NotFoundException::class,
                422 => ValidationException::class,
                429 => TooManyRequestsException::class,
                500 => ServerException::class,
                502, 503, 504 => BadGatewayException::class,
                default => ApiException::class,
            },
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function payload(Response $response): ?array
    {
        if ($response->body === '') {
            return null;
        }

        try {
            $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private static function missingFields(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $fields = [];
        foreach ($value as $field) {
            if (is_string($field) && $field !== '') {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private static function retryAfter(Response $response, ?array $payload): ?int
    {
        if (is_array($payload) && isset($payload['retry_after']) && is_numeric($payload['retry_after'])) {
            return (int) $payload['retry_after'];
        }

        $header = $response->header('Retry-After');
        if ($header !== null && is_numeric($header)) {
            return (int) $header;
        }

        return null;
    }
}
