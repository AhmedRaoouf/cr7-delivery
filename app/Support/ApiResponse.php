<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/** Builds the success/error envelopes doc 08 §1 defines, so every controller returns the same shape. */
class ApiResponse
{
    public static function success(mixed $data = null, ?string $message = null, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json(array_filter([
            'success' => true,
            'data' => $data,
            'message' => $message,
            'meta' => $meta ?: null,
        ], static fn ($value) => $value !== null), $status);
    }

    public static function error(string $errorCode, string $message, int $status = 422, array $errors = [], array $meta = []): JsonResponse
    {
        return response()->json(array_filter([
            'success' => false,
            'error' => $errorCode,
            'message' => $message,
            'errors' => $errors ?: null,
            'meta' => $meta ?: null,
        ], static fn ($value) => $value !== null), $status);
    }
}
