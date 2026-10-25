<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\WalletRechargeException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\WalletRecharge\WalletRechargeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class WalletRechargeController extends Controller
{
    public function __construct(private readonly WalletRechargeService $recharge)
    {
    }

    public function capability(Request $request): JsonResponse
    {
        return $this->handle(function () use ($request): array {
            return $this->recharge->capability($this->user($request));
        });
    }

    public function create(Request $request): JsonResponse
    {
        return $this->handle(function () use ($request): array {
            return $this->recharge->createOrder($this->user($request), $request->all());
        });
    }

    public function index(Request $request): JsonResponse
    {
        return $this->handle(function () use ($request): array {
            $limit = (int) $request->query('limit', 20);

            return $this->recharge->listOrders($this->user($request), $limit);
        });
    }

    public function show(Request $request, string $orderNo): JsonResponse
    {
        return $this->handle(function () use ($request, $orderNo): array {
            return $this->recharge->getOrder($this->user($request), $orderNo);
        });
    }

    public function payment(Request $request, string $orderNo): JsonResponse
    {
        return $this->handle(function () use ($request, $orderNo): array {
            return $this->recharge->startPayment($this->user($request), $orderNo);
        });
    }

    public function refresh(Request $request, string $orderNo): JsonResponse
    {
        return $this->handle(function () use ($request, $orderNo): array {
            return $this->recharge->refreshOrder($this->user($request), $orderNo);
        });
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        if (!$user instanceof User) {
            throw WalletRechargeException::forbidden('未登录');
        }

        return $user;
    }

    private function handle(callable $handler): JsonResponse
    {
        try {
            return $this->success($handler());
        } catch (WalletRechargeException $e) {
            return $this->failResponse($e->httpStatus(), $e->errorCode(), $e->getMessage(), $e->data());
        } catch (Throwable) {
            return $this->failResponse(500, 'internal_error', '充值服务暂时不可用', []);
        }
    }

    private function failResponse(int $status, string $code, string $message, array $data): JsonResponse
    {
        return response()->json([
            'status' => 'fail',
            'code' => $code,
            'message' => $message,
            'data' => $data === [] ? null : $data,
            'error' => null,
        ], $status);
    }
}
