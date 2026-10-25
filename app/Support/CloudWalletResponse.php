<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

class CloudWalletResponse
{
    public static function success(mixed $data): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    public static function fail(int $httpStatus, string $code, string $message): JsonResponse
    {
        return response()->json([
            'status' => 'fail',
            'code' => $code,
            'message' => $message,
            'data' => null,
        ], $httpStatus);
    }
}
