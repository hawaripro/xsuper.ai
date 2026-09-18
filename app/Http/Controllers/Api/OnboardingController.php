<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PromptTemplate;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    /**
     * Save user's onboarding mode selection.
     */
    public function saveMode(Request $request)
    {
        $request->validate([
            'mode' => 'required|string|in:coding_assistant,project_builder,content_creator,umkm_assistant,marketplace_helper,excel_office_helper,prompt_visual_generator',
        ]);

        $user = $request->user();
        $user->onboarding_mode = $request->mode;
        $user->save();

        return response()->json(['success' => true, 'mode' => $user->onboarding_mode]);
    }

    /**
     * Get user's onboarding status.
     */
    public function status(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'onboarding_mode' => $user->onboarding_mode,
            'completed' => ! is_null($user->onboarding_mode),
        ]);
    }

    /**
     * Browse active templates; facet counts follow search and mode, not category.
     */
    public function templates(Request $request)
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'template' => ['nullable', 'integer', 'min:1'],
            'category' => ['nullable', 'string', 'max:50'],
            'mode' => ['nullable', 'string', 'max:50'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:60'],
        ]);
        $query = PromptTemplate::active();
        if (isset($validated['template'])) {
            $query->whereKey($validated['template']);
        }

        if (($mode = trim($validated['mode'] ?? '')) !== '') {
            $query->byMode($mode);
        }

        if (($search = trim($validated['q'] ?? '')) !== '') {
            // An explicit escape character works on both PostgreSQL and SQLite.
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            $query->where(function ($match) use ($pattern) {
                $match->whereRaw("LOWER(title) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(prompt_text) LIKE ? ESCAPE '!'", [$pattern]);
            });
        }

        $categories = array_values(array_unique(array_merge(
            array_keys(PromptTemplate::CATEGORY_MODES),
            PromptTemplate::active()->distinct()->orderBy('category')->pluck('category')->all(),
        )));
        $counts = array_fill_keys($categories, 0);
        foreach ((clone $query)->selectRaw('category, COUNT(*) AS aggregate')->groupBy('category')->pluck('aggregate', 'category') as $category => $count) {
            $counts[$category] = (int) $count;
        }

        if (($category = trim($validated['category'] ?? '')) !== '') {
            $query->byCategory($category);
        }

        $templates = $query->orderBy('sort_order')->orderBy('id')->paginate(
            (int) ($validated['per_page'] ?? 24),
            ['id', 'category', 'title', 'prompt_text', 'mode'],
            'page',
            (int) ($validated['page'] ?? 1),
        );

        return response()->json([
            'templates' => $templates->items(),
            'categories' => $categories,
            'counts' => $counts,
            'pagination' => [
                'current_page' => $templates->currentPage(),
                'last_page' => $templates->lastPage(),
                'per_page' => $templates->perPage(),
                'total' => $templates->total(),
            ],
        ]);
    }
}
