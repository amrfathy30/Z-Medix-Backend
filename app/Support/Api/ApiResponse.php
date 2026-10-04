<?php

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;

trait ApiResponse
{
    public function successResponse(mixed $data = null, string $message = 'Success.', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    /**
     * @param  array<string, mixed>  $errors
     * @param  string|null  $code  Stable machine-readable identifier for the failure.
     *                             Omitted from the payload when null, so existing
     *                             error responses are unchanged.
     */
    public function errorResponse(string $message = 'Error.', array $errors = [], int $status = 400, ?string $code = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ];

        if ($code !== null) {
            $payload['code'] = $code;
        }

        return response()->json($payload, $status);
    }

    public function paginatedResponse(ResourceCollection $resourceCollection, string $message = 'Success.'): JsonResponse
    {
        $paginator = $resourceCollection->resource;

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $resourceCollection,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
    }
}
