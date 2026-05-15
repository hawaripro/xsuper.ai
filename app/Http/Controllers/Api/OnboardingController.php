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
            'completed' => !is_null($user->onboarding_mode),
        ]);
    }

    /**
     * List all active templates, optionally filtered by category or mode.
     */
    public function templates(Request $request)
    {
        $query = PromptTemplate::active()->orderBy('sort_order');

        if ($category = $request->query('category')) {
            $query->byCategory($category);
        }

        if ($mode = $request->query('mode')) {
            $query->byMode($mode);
        }

        $templates = $query->get(['id', 'category', 'title', 'prompt_text', 'mode']);

        // Group by category
        $grouped = $templates->groupBy('category');

        return response()->json([
            'templates' => $templates,
            'grouped' => $grouped,
            'categories' => $grouped->keys(),
        ]);
    }
}
