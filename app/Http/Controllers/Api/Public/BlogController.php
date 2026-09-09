<?php

namespace App\Http\Controllers\Api\Public;

use App\Enums\ContentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Public\BlogCategoryResource;
use App\Http\Resources\Api\Public\BlogResource;
use App\Models\Blog;
use App\Models\BlogCategory;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlogController extends Controller
{
    use ApiResponse;

    public function categories(): JsonResponse
    {
        $categories = BlogCategory::where('status', ContentStatus::Published)
            ->orderBy('sort_order')
            ->get();

        return $this->successResponse(BlogCategoryResource::collection($categories));
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->integer('per_page', 12), 50);

        $query = Blog::with('category')
            ->where('status', ContentStatus::Published)
            ->where(function ($q): void {
                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
            });

        if ($request->filled('category')) {
            $query->whereHas('category', fn ($q) => $q->where('slug', $request->string('category')));
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search): void {
                $q->where('title', 'LIKE', "%{$search}%")
                    ->orWhere('excerpt', 'LIKE', "%{$search}%");
            });
        }

        $blogs = $query->orderByDesc('published_at')->paginate($perPage);

        return $this->paginatedResponse(BlogResource::collection($blogs));
    }

    public function show(string $slug): JsonResponse
    {
        $blog = Blog::with('category')
            ->where('slug', $slug)
            ->where('status', ContentStatus::Published)
            ->where(function ($q): void {
                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
            })
            ->first();

        if (! $blog) {
            return $this->errorResponse('Blog post not found.', [], 404);
        }

        return $this->successResponse(new BlogResource($blog));
    }
}
