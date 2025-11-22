<?php

declare(strict_types=1);

namespace Modules\Stox\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Modules\Stox\Entities\StoxAccount;
use Illuminate\Support\Facades\Log;
use Throwable;

class StoxApiService
{
    public function __construct(private readonly HttpFactory $http)
    {
    }

    /**
     * Send a new order to Stox and return the decoded response.
     *
     * @param array<string, mixed> $payload
     * @return array{success: bool, status: int|null, data: array|null, error: string|null}
     */
    public function sendOrder(StoxAccount $account, array $payload): array
    {
        // Stox expects a wrapper object: { orders: [ { ...order payload... } ] }
        $body = [
            'orders' => [$payload],
        ];

        return $this->performRequest($account, 'orders/store', $body);
    }

    /**
     * Perform a lightweight request to validate the credentials.
     *
     * @return array{success: bool, status: int|null, data: array|null, error: string|null}
     */
    public function testConnection(StoxAccount $account): array
    {
        return $this->performRequest($account, 'orders?page=1', [], 'get');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{success: bool, status: int|null, data: array|null, error: string|null}
     */
    private function performRequest(
        StoxAccount $account,
        string $path,
        array $payload,
        string $method = 'post'
    ): array {

        log::info('here _ 1ccccccc');
        log::info($payload);
        $request = $this->buildRequest($account);
        $response = null;

        try {
            log::info('here _ 4ccccccc');
            $response = $method === 'get'
                ? $request->get($path, $payload)
                : $request->{$method}($path, $payload);

            $body = $response->json();
            log::info('here _ 3ccccccc');
            log::info($body);
            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'data' => $body,
                'error' => $response->successful() ? null : $this->extractError($body),
            ];
        } catch (Throwable $exception) {
            return [
                'success' => false,
                'status' => $response?->status(),
                'data' => $response?->json(),
                'error' => $exception->getMessage(),
            ];
        }
    }

    private function buildRequest(StoxAccount $account): PendingRequest
    {
        return $this->http
            ->acceptJson()
            ->asJson()
            ->baseUrl(rtrim($account->base_url ?? config('stox.default_base_url'), '/'))
            ->withToken($account->bearer_token)
            ->timeout(20);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function extractError(?array $body): ?string
    {
        if ($body === null) {
            return null;
        }

        return Arr::get($body, 'message') ?? Arr::get($body, 'error') ?? null;
    }
}

