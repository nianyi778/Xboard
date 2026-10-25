<?php

namespace App\Http\Controllers\V1\Internal;

use App\Exceptions\CloudWalletException;
use App\Http\Controllers\Controller;
use App\Services\CloudWallet\CloudWalletService;
use App\Support\CloudWalletResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use Throwable;

class CloudWalletController extends Controller
{
    public function __construct(private readonly CloudWalletService $wallet)
    {
    }

    public function balance(Request $request): JsonResponse
    {
        return $this->handlePayload($request, fn (array $payload) => $this->wallet->balance($payload));
    }

    public function debit(Request $request): JsonResponse
    {
        return $this->handlePayload($request, fn (array $payload) => $this->wallet->debit($payload));
    }

    public function refund(Request $request): JsonResponse
    {
        return $this->handlePayload($request, fn (array $payload) => $this->wallet->refund($payload));
    }

    public function lookup(Request $request): JsonResponse
    {
        return $this->handlePayload($request, fn (array $payload) => $this->wallet->lookup($payload));
    }

    public function reconcile(Request $request): JsonResponse
    {
        return $this->handlePayload($request, fn (array $payload) => $this->wallet->reconcile($payload));
    }

    private function handlePayload(Request $request, callable $handler): JsonResponse
    {
        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw CloudWalletException::invalidRequest('body must be a JSON object');
            }

            return CloudWalletResponse::success($handler($payload));
        } catch (JsonException) {
            return CloudWalletResponse::fail(400, 'invalid_request', 'body must be valid JSON');
        } catch (CloudWalletException $e) {
            return CloudWalletResponse::fail($e->httpStatus(), $e->errorCode(), $e->getMessage());
        } catch (Throwable) {
            return CloudWalletResponse::fail(500, 'internal_error', 'internal error');
        }
    }
}
