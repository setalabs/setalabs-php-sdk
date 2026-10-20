<?php

declare(strict_types=1);

namespace Setalabs\Exception;

use Setalabs\Response;

class ApiException extends SetalabsException
{
    /**
     * @param list<string> $missingFields
     */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly string $error,
        public readonly ?string $reason = null,
        public readonly ?string $requestId = null,
        public readonly array $missingFields = [],
        public readonly ?int $retryAfter = null,
        public readonly ?Response $response = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
