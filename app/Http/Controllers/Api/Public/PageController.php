<?php

namespace App\Http\Controllers\Api\Public;

use App\Enums\ContentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Public\PageResource;
use App\Models\Page;
use App\Support\Api\ApiResponse;
use App\Support\Content\Definitions\ContentDefinitionRegistry;
use Illuminate\Http\JsonResponse;

class PageController extends Controller
{
    use ApiResponse;

    /** The route segment is Page.key — the route parameter name is kept as `{slug}` for URL stability, but it is resolved as key only, with no separate slug column or fallback. */
    public function show(string $slug): JsonResponse
    {
        $page = Page::query()
            ->where('key', $slug)
            ->where('status', ContentStatus::Published)
            ->first();

        if (! $page) {
            return $this->errorResponse('Page not found.', [], 404);
        }

        $registry = app(ContentDefinitionRegistry::class);
        $sectionsQuery = $page->sections()
            ->where('status', ContentStatus::Published)
            ->with(['media', 'items' => fn ($query) => $query->published()->ordered(), 'items.media'])
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($registry->has($slug)) {
            $definedKeys = array_map(
                fn ($section) => $section->sectionKey,
                $registry->forPage($slug)->orderedSections(),
            );
            $sectionsQuery->whereIn('section_key', $definedKeys);
        }

        $page->setRelation('sections', $sectionsQuery->get());
        $page->sections->each->setRelation('page', $page);

        return $this->successResponse(new PageResource($page));
    }
}
