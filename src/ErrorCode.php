<?php

declare(strict_types=1);

namespace Setalabs;

/**
 * Error codes returned by the Setalabs gateway.
 */
final class ErrorCode
{
    public const BAD_REQUEST = 'bad_request';
    public const UNAUTHORIZED = 'unauthorized';
    public const FORBIDDEN = 'forbidden';
    public const NOT_FOUND = 'not_found';
    public const VALIDATION_ERROR = 'validation_error';
    public const BAD_GATEWAY = 'bad_gateway';
    public const INTERNAL_ERROR = 'internal_error';
    public const RATE_LIMITED = 'rate_limited';
    public const USAGE_LIMIT_EXCEEDED = 'usage_limit_exceeded';

    private function __construct()
    {
    }
}
