<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentBlock;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ContentController extends Controller
{
    public const KEYS = [
        'home.hero',
        'home.faq',
        'system.announcement',
        'help.articles',
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['sometimes', 'string', Rule::in(self::KEYS)],
            'locale' => ['sometimes', 'string', Rule::in(['id', 'en'])],
        ]);

        $query = ContentBlock::query()
            ->with('editor:id,name,email')
            ->orderBy('key')
            ->orderBy('locale');

        if (isset($validated['key'])) {
            $query->where('key', $validated['key']);
        }

        if (isset($validated['locale'])) {
            $query->where('locale', $validated['locale']);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request, AuditService $audit): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', Rule::in(self::KEYS), Rule::unique('content_blocks')->where('locale', $request->input('locale', 'id'))],
            'locale' => ['required', 'string', Rule::in(['id', 'en'])],
            'draft' => ['required', 'array'],
        ]);
        $draft = $this->validatedPayload($validated['key'], $validated['draft']);

        $block = ContentBlock::create([
            'key' => $validated['key'],
            'locale' => $validated['locale'],
            'draft' => $draft,
            'updated_by' => $request->user()->id,
        ]);

        $audit->record($request->user(), 'content.created', $block, [
            'key' => $block->key,
            'locale' => $block->locale,
        ]);

        return response()->json(['data' => $block->fresh()->load('editor:id,name,email')], 201);
    }

    public function update(Request $request, ContentBlock $contentBlock, AuditService $audit): JsonResponse
    {
        $validated = $request->validate(['draft' => ['required', 'array']]);

        $contentBlock->forceFill([
            'draft' => $this->validatedPayload($contentBlock->key, $validated['draft']),
            'updated_by' => $request->user()->id,
        ])->save();

        $audit->record($request->user(), 'content.updated', $contentBlock, [
            'key' => $contentBlock->key,
            'locale' => $contentBlock->locale,
        ]);

        return response()->json(['data' => $contentBlock->fresh()->load('editor:id,name,email')]);
    }

    public function publish(Request $request, ContentBlock $contentBlock, AuditService $audit): JsonResponse
    {
        $published = $this->validatedPayload($contentBlock->key, $contentBlock->draft);

        $contentBlock->forceFill([
            'published' => $published,
            'is_published' => true,
            'published_at' => now(),
            'updated_by' => $request->user()->id,
        ])->save();

        $audit->record($request->user(), 'content.published', $contentBlock, [
            'key' => $contentBlock->key,
            'locale' => $contentBlock->locale,
        ]);

        return response()->json(['data' => $contentBlock->fresh()->load('editor:id,name,email')]);
    }

    public static function payloadIsValid(string $key, mixed $payload): bool
    {
        if (! is_array($payload) || ! in_array($key, self::KEYS, true)) {
            return false;
        }

        $validator = validator(['draft' => $payload], self::payloadRules($key));

        return ! $validator->fails();
    }

    private function validatedPayload(string $key, array $payload): array
    {
        $validator = validator(['draft' => $payload], self::payloadRules($key));

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated()['draft'];
    }

    private static function payloadRules(string $key): array
    {
        $rules = match ($key) {
            'home.hero' => [
                'draft' => ['required', 'array:headline,description,primary_action'],
                'draft.headline' => ['required', 'string', 'max:160'],
                'draft.description' => ['required', 'string', 'max:600'],
                'draft.primary_action' => ['sometimes', 'array:label,url'],
                'draft.primary_action.label' => ['required_with:draft.primary_action', 'string', 'max:80'],
                'draft.primary_action.url' => ['required_with:draft.primary_action', 'string', 'max:500', self::safeUrlRule()],
            ],
            'home.faq' => [
                'draft' => ['required', 'array:items'],
                'draft.items' => ['required', 'array', 'min:1', 'max:30'],
                'draft.items.*' => ['required', 'array:question,answer'],
                'draft.items.*.question' => ['required', 'string', 'max:300'],
                'draft.items.*.answer' => ['required', 'string', 'max:3000'],
            ],
            'system.announcement' => [
                'draft' => ['required', 'array:message,level,action'],
                'draft.message' => ['required', 'string', 'max:500'],
                'draft.level' => ['sometimes', 'string', Rule::in(['info', 'success', 'warning', 'critical'])],
                'draft.action' => ['sometimes', 'array:label,url'],
                'draft.action.label' => ['required_with:draft.action', 'string', 'max:80'],
                'draft.action.url' => ['required_with:draft.action', 'string', 'max:500', self::safeUrlRule()],
            ],
            'help.articles' => [
                'draft' => ['required', 'array:items'],
                'draft.items' => ['required', 'array', 'max:100'],
                'draft.items.*' => ['required', 'array:slug,title,summary,body'],
                'draft.items.*.slug' => ['required', 'string', 'alpha_dash:ascii', 'max:120', 'distinct'],
                'draft.items.*.title' => ['required', 'string', 'max:200'],
                'draft.items.*.summary' => ['required', 'string', 'max:500'],
                'draft.items.*.body' => ['required', 'string', 'max:20000'],
            ],
            default => ['draft' => ['prohibited']],
        };

        return $rules;
    }

    private static function safeUrlRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
                return;
            }

            $scheme = parse_url($value, PHP_URL_SCHEME);
            if (in_array(strtolower((string) $scheme), ['http', 'https'], true) && filter_var($value, FILTER_VALIDATE_URL)) {
                return;
            }

            $fail("The {$attribute} field must be a safe relative, HTTP, or HTTPS URL.");
        };
    }
}
