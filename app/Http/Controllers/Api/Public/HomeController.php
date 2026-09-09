<?php

namespace App\Http\Controllers\Api\Public;

use App\Enums\ContentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Public\BlogResource;
use App\Http\Resources\Api\Public\FaqCategoryResource;
use App\Http\Resources\Api\Public\SettingResource;
use App\Models\Blog;
use App\Models\FaqCategory;
use App\Models\Setting;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class HomeController extends Controller
{
    use ApiResponse;

    public function __invoke(): JsonResponse
    {
        $settings = Setting::where('is_public', true)->get();

        $settingsGrouped = $settings->groupBy('group')->map(
            fn ($group) => SettingResource::collection($group)
        );

        $latestBlogs = Blog::with('category')
            ->where('status', ContentStatus::Published)
            ->where(function ($q): void {
                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
            })
            ->orderByDesc('published_at')
            ->limit(6)
            ->get();

        $faqCategories = FaqCategory::with([
            'faqs' => fn ($q) => $q->where('status', ContentStatus::Published)->orderBy('sort_order'),
        ])->where('status', ContentStatus::Published)
            ->orderBy('sort_order')
            ->get();

        return $this->successResponse([
            'settings' => $settingsGrouped,
            'latest_blogs' => BlogResource::collection($latestBlogs),
            'faqs' => FaqCategoryResource::collection($faqCategories),
        ]);
    }
}
