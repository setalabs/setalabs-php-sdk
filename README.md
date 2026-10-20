# Setalabs PHP SDK

Client for the Setalabs gateway. Each API has its own uuid, key, and set of endpoints. The gateway address is shared.

The key secret is used only to sign requests. It is never sent.

## Installation

```bash
composer require setalabs/php-sdk
```

Until the package is published, require it from a local path:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../setalabs-php-sdk"
        }
    ],
    "require": {
        "setalabs/php-sdk": "@dev"
    }
}
```

## Requests

The default gateway address is `Setalabs\ValueObjects\Gateway::URL` (`https://gw.setalabs.com`). Pass `gateway` to use another one. An OpenAPI server URL already includes the API uuid; this SDK takes the uuid separately.

```php
use Setalabs\Client;
use Setalabs\Exception\ApiException;
use Setalabs\Exception\RateLimitException;
use Setalabs\Exception\ValidationException;

$client = new Client(
    apiUuid: '05141812-7772-403e-9ca6-5e8e8bf22730',
    apiKey: 'your-key',
    apiSecret: 'your-secret',
);

$local = new Client(
    apiUuid: '05141812-7772-403e-9ca6-5e8e8bf22730',
    apiKey: 'your-key',
    apiSecret: 'your-secret',
    gateway: 'http://localhost:8080',
);

$response = $client->get('/catalog', [
    'language' => 'en',
]);

$data = $response->json();
```

The path is the endpoint path from the OpenAPI document (`/catalog`, `/product/{id}`, `/search`), without the `/v1/api/{uuid}` prefix. Substitute path parameters yourself: `/product/12345`.

Another API on the same gateway:

```php
$other = $client->withApi('another-api-uuid', 'other-key', 'other-secret');
```

If the key has no secret, pass an empty string. The request is not signed.

```php
$client->post('/orders', ['sku' => 'A1']);
$client->request('PUT', '/orders/1', [
    'json' => ['status' => 'paid'],
    'query' => ['language' => 'en'],
    'timeout' => 10,
]);
```

A successful response is the upstream body and status, unchanged. The gateway returns the request id in `X-Request-Id`:

```php
$response->statusCode;
$response->requestId();
$response->json();
```

## Signature

When a secret is set, every request includes:

- `X-Api-Key`
- `X-Api-Timestamp` — Unix time in seconds
- `X-Api-Sign` — `HMAC-SHA256(key + secret + timestamp + path?query, secret)`, lowercase hex

`path?query` is the path the gateway sees, plus the raw query string. For example: `/v1/api/{uuid}/catalog?language=en`. Parameter order is preserved.

## Errors

A response with status 400 or higher throws an exception. The message comes from the gateway `reason`. Every API error exposes `statusCode`, `error`, `reason`, `requestId`, and the original `response`.

| HTTP | `error` | Exception | When |
| --- | --- | --- | --- |
| 400 | `bad_request` | `BadRequestException` | The API has no base URL |
| 401 | `unauthorized` | `UnauthorizedException` | Missing or invalid key, missing or invalid signature, missing timestamp |
| 403 | `forbidden` | `ForbiddenException` | Key is blocked, subscription has expired, no active tariff, or the key belongs to another API |
| 404 | `not_found` | `NotFoundException` | API or endpoint was not found |
| 422 | `validation_error` | `ValidationException` | Required fields are missing; names are in `missingFields` |
| 429 | `rate_limited` | `RateLimitException` | Plan RPS limit exceeded; wait `retryAfter` seconds |
| 429 | `usage_limit_exceeded` | `UsageLimitException` | Call quota for the period is exhausted |
| 500 | `internal_error` | `ServerException` | Gateway internal error |
| 502 | `bad_gateway` | `BadGatewayException` | Upstream is unavailable |

A network failure throws `TransportException`. `SetalabsException` is the base type for gateway and transport errors. Invalid client arguments throw `InvalidArgumentException`.

```php
try {
    $data = $client->get('/search', [
        'text' => 'phone',
        'page' => 1,
        'limit' => 24,
    ])->json();
} catch (ValidationException $e) {
    $e->missingFields;
} catch (RateLimitException $e) {
    sleep($e->retryAfter ?? 1);
} catch (ApiException $e) {
    $e->statusCode;
    $e->reason;
    $e->requestId;
}
```
