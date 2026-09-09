<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Public\PageSectionResource;
use App\Models\Page;
use App\Models\PageSection;
use App\Support\Api\ApiResponse;
use App\Support\Content\Definitions\ContentDefinitionRegistry;
use Illuminate\Http\JsonResponse;

class PageSectionController extends Controller
{
    use ApiResponse;

    public function index(string $pageKey): JsonResponse
    {
        $page = Page::query()->where('key', $pageKey)->published()->first();

        if ($page === null) {
            return $this->errorResponse('Page not found.', [], 404);
        }

        $registry = app(ContentDefinitionRegistry::class);
        $sectionsQuery = PageSection::query()
            ->ofPage($page)
            ->published()
            ->with(['media', 'page', 'items' => fn ($query) => $query->published()->ordered(), 'items.media'])
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($registry->has($pageKey)) {
            $definedKeys = array_map(
                fn ($section) => $section->sectionKey,
                $registry->forPage($pageKey)->orderedSections(),
            );
            $sectionsQuery->whereIn('section_key', $definedKeys);
        }

        $sections = $sectionsQuery->get();

        return $this->successResponse([
            'page_key' => $pageKey,
            'sections' => PageSectionResource::collection($sections),
        ]);
    }
}
