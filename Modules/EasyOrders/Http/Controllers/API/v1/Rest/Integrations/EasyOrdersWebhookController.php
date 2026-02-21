<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Http\Controllers\API\v1\Rest\Integrations;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\EasyOrders\Http\Requests\WebhookRequest;
use Modules\EasyOrders\Services\WebhookService;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class EasyOrdersWebhookController extends Controller
{
	public function __construct(private readonly WebhookService $service)
	{
	}

	public function store(WebhookRequest $request): JsonResponse
	{
		$secret = $request->header('secret');

		// IP allowlist check
		$ip = $request->ip();
		if (!$this->service->isIpAllowed($ip)) {
			return response()->json(['message' => 'Forbidden'], 403);
		}

		if (!$this->service->verifySecret($secret)) {
			return response()->json(['message' => 'Unauthorized'], 401);
		}

		try {
			$webhookLogId = $this->service->receiveWebhook($request->all(), (string) $secret, $request->headers->all());
		} catch (HttpExceptionInterface $e) {
			// Preserve intended HTTP error responses (401/422/etc).
			throw $e;
		} catch (\Throwable $e) {
			// Last resort: return 200 to avoid permanent loss (EasyOrders does not retry).
			error_log('EasyOrders webhook receive failed: '.$e->getMessage());
			$webhookLogId = null;
		}

		return response()->json([
			'message' => 'accepted',
			'webhook_log_id' => $webhookLogId,
		]);
	}
}


