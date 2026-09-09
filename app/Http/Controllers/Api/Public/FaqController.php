<?php

namespace App\Http\Controllers\Api\Public;

use App\Enums\ContentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Public\FaqCategoryResource;
use App\Models\FaqCategory;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FaqController extends Controller
{
    use ApiResponse;

    public function categories(): JsonResponse
    {
        $categories = FaqCategory::where('status', ContentStatus::Published)
            ->orderBy('sort_order')
            ->get();

        return $this->successResponse(FaqCategoryResource::collection($categories));
    }

    public function index(Request $request): JsonResponse
    {
        $query = FaqCategory::with([
            'faqs' => fn ($q) => $q->where('status', ContentStatus::Published)->orderBy('sort_order'),
        ])->where('status', ContentStatus::Published)
            ->orderBy('sort_order');

        if ($request->filled('category')) {
            $query->where('slug', $request->string('category'));
        }

        $categories = $query->get();

        return $this->successResponse(FaqCategoryResource::collection($categories));
    }
}
