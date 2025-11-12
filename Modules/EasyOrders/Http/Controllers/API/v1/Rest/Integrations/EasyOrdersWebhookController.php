<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Http\Controllers\API\v1\Rest\Integrations;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\EasyOrders\Http\Requests\WebhookRequest;
use Modules\EasyOrders\Services\WebhookService;

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

		$temp = $this->service->handle($request->all(), (string) $secret, $request->headers->all());
		return response()->json([
			'message' => 'accepted',
			'temp_order_id' => $temp->id,
			'status' => $temp->status,
		]);
	}
}


