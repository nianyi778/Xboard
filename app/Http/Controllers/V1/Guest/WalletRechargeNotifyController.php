<?php

namespace App\Http\Controllers\V1\Guest;

use App\Exceptions\WalletRechargeException;
use App\Http\Controllers\Controller;
use App\Services\WalletRecharge\WalletRechargeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use Throwable;

/**
 * 独立充值回调。故意不复用订阅的 payment/notify 路由，也不进入
 * OrderService::open() 或任何套餐订单状态机。
 */
class WalletRechargeNotifyController extends Controller
{
    public function __construct(private readonly WalletRechargeService $recharge)
    {
    }

    public function notify(Request $request): JsonResponse
    {
        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw WalletRechargeException::invalidRequest('body must be a JSON object');
            }

            $this->recharge->handleNotify($payload);

            // 2xx 只代表“回调已被安全处理/已留存”，绝不代表可以凭它改前端状态。
            return response()->json(['status' => 'success', 'data' => null], 200);
        } catch (JsonException) {
            return $this->failResponse(400, 'invalid_request', 'body must be valid JSON');
        } catch (WalletRechargeException $e) {
            return $this->failResponse($e->httpStatus(), $e->errorCode(), $e->getMessage());
        } catch (Throwable) {
            return $this->failResponse(500, 'internal_error', 'callback processing failed');
        }
    }

    private function failResponse(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'status' => 'fail',
            'code' => $code,
            'message' => $message,
            'data' => null,
            'error' => null,
        ], $status);
    }
}
