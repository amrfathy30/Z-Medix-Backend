<?php

namespace App\Trait;

use Illuminate\Http\JsonResponse;

trait ApiResponseTrait
{
    /**
     * Send a success response.
     */
    public function success(mixed $data = null, string $message = '', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message ?: __('Success'),
            'data' => $data,
            'errors' => [],
        ], $status);
    }

    /**
     * Send an error response.
     */
    public function error(string $message = '', int $status = 400, array $errors = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message ?: __('Error'),
            'data' => null,
            'errors' => $errors,
        ], $status);
    }
}
