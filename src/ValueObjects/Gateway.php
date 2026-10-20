<?php

declare(strict_types=1);

namespace Setalabs\ValueObjects;

final class Gateway
{
    public const URL = 'https://gw.setalabs.com';

    public const API_PREFIX = '/v1/api';

    private function __construct()
    {
    }
}
