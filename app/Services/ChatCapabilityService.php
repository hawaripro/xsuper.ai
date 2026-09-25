<?php

namespace App\Services;

use App\Media\AssetService;
use App\Models\AiModelProfile;
use App\Models\UsageRate;
use App\Models\User;
use App\Support\StudioLink;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ChatCapabilityService
{
    private const TEXT_MIMES = ['text/plain', 'text/markdown', 'text/csv', 'application/json'];

    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    private const FILE_LIMIT = 15 * 1024 * 1024;

    private const TEXT_LIMIT = 2 * 1024 * 1024;

    private const TOTAL_LIMIT = 32 * 1024 * 1024;

    public function __construct(private readonly AiProxyService $proxy, private readonly AssetService $assets) {}

    /** Member-facing sale prices only; never expose provider bindings or cost snapshots. */
    public function models(User $user, bool $all = false): array
    {
        $models = $all ? $this->proxy->getAllModelsFiltered() : $this->proxy->getModels();
        $sellable = UsageRate::sellableModelIds();
        $prices = UsageRate::active()->where('service', 'api')->whereIn('model', $sellable)
            ->whereIn('meter', ['input_tokens', 'output_tokens'])->get()->groupBy('model');

        return collect($models)
            ->filter(fn (array $model): bool => $user->isAdmin() || in_array($model['id'], $sellable, true))
            ->map(function (array $model) use ($prices, $user): array {
                if (! $user->isAdmin()) {
                    unset($model['provider']);
                }
                if (isset($prices[$model['id']])) {
                    $rates = $prices[$model['id']]->keyBy('meter');
                    $model['price'] = [
                        'input_usd' => round((float) $rates['input_tokens']->price_usd, 4),
                        'output_usd' => round((float) $rates['output_tokens']->price_usd, 4),
                        'input_idr' => round((float) $rates['input_tokens']->price_idr),
                        'output_idr' => round((float) $rates['output_tokens']->price_idr),
                    ];
                }

                return $model;
            })->values()->all();
    }

    public function resolve(User $user, string $model): array
    {
        // `is_active === false` mirrors EnsureActive: an unloaded column is null, not inactive.
        abort_unless($user->hasPermission('chat') && ($user->isAdmin() || $user->is_active !== false), 403, 'Chat access is unavailable.');
        abort_if(! $user->isAdmin() && ! in_array($model, UsageRate::sellableModelIds(), true), 422, 'Harga model ini belum tersedia.');
        $public = collect($this->models($user))->firstWhere('id', $model);
        abort_unless($public, 403, 'The selected chat model is unavailable.');
        $profile = AiModelProfile::query()->with('provider')->where('model_id', $model)->firstOrFail();
        $protocol = $profile->provider->base_url !== null ? $profile->provider->protocol : 'openai';
        if (! in_array($protocol, ['openai', 'anthropic', 'fal'], true)) {
            throw ValidationException::withMessages(['model' => 'This provider does not implement chat completion.']);
        }
        $modalities = $this->flags($profile->input_modalities ?? ['text']);
        $flags = $this->flags($profile->capabilities ?? []);
        if (! in_array('text', $modalities, true)) {
            throw ValidationException::withMessages(['model' => 'This model does not accept text chat input.']);
        }
        $images = in_array($protocol, ['openai', 'anthropic'], true)
            && (in_array('image', $modalities, true) || in_array('vision', $flags, true));
        $declaredPdf = count(array_intersect(['pdf', 'document', 'documents', 'pdf_input'], [...$modalities, ...$flags])) > 0;
        $pdf = ($protocol === 'anthropic' && ($images || $declaredPdf)) || ($protocol === 'openai' && $declaredPdf);
        $mimes = [...self::TEXT_MIMES, ...($images ? self::IMAGE_MIMES : []), ...($pdf ? ['application/pdf'] : [])];
        $uploadPolicy = $this->assets->policy();
        $documentPolicy = $uploadPolicy['roles']['document'];
        $imagePolicy = $uploadPolicy['roles']['image_ref'];
        $textLimit = min(self::TEXT_LIMIT, $documentPolicy['max_text_bytes']);
        $limits = array_fill_keys(self::TEXT_MIMES, $textLimit)
            + ($images ? array_fill_keys(self::IMAGE_MIMES, min(self::FILE_LIMIT, $imagePolicy['max_bytes'])) : [])
            + ($pdf ? ['application/pdf' => min(self::FILE_LIMIT, $documentPolicy['max_bytes'])] : []);
        $extensions = array_values(array_filter($documentPolicy['accepted_extensions'], fn (string $extension): bool => $pdf || $extension !== 'pdf'));
        if ($images) {
            $extensions = [...$extensions, ...$imagePolicy['accepted_extensions']];
        }
        $provider = $profile->provider;
        $interruptible = extension_loaded('curl');
        // "Last check succeeded" needs a recorded check; a healthy flag alone (seeded or migrated data) is unverified.
        $checked = $provider->status === 'healthy' && $provider->last_checked_at !== null;

        return [
            'profile' => $profile,
            'metadata' => [
                'model' => $user->isAdmin() ? [...$public, 'provider_name' => $profile->provider_name ?: $provider->name] : $public,
                'input' => [
                    'images' => $images, 'documents' => true, 'native_pdf' => $pdf, 'text' => true,
                    'max_files' => 8, 'max_file_bytes' => max($limits),
                    'max_total_file_bytes' => self::TOTAL_LIMIT, 'max_text_bytes' => self::TEXT_LIMIT,
                    'max_upload_request_bytes' => $uploadPolicy['max_request_bytes'],
                    'accepted_mimes' => $mimes, 'accepted_extensions' => $extensions, 'limits_by_mime' => $limits,
                ],
                'tools' => [
                    'file_analysis' => ['available' => true, 'reason' => 'Selected files are sent as user context; PDF requires native model support.'],
                    'image_generation' => [
                        'available' => false, 'control' => 'link',
                        'reason' => 'Image generation runs separately in Studio with its own model and reviewed price.',
                        'href' => $user->hasPermission('image_generator') ? StudioLink::to('image') : null,
                    ],
                    'web_search' => ['available' => false, 'reason' => 'No web search executor is connected to chat.'],
                    'code_interpreter' => ['available' => false, 'reason' => 'No code execution sandbox is connected to chat.'],
                ],
                'streaming' => [
                    'incremental' => $protocol !== 'fal', 'can_stop' => $interruptible,
                    'stop_reason' => $interruptible
                        ? 'Stop interrupts delivery and transport; it does not confirm upstream cancellation or a refund.'
                        : 'Interruptible provider transport is unavailable on this server.',
                ],
                'artifact' => ['manual_create' => true, 'ai_revision' => false],
                'provider_status' => [
                    'state' => ! $interruptible ? 'unavailable' : ($checked ? 'configured' : 'unverified'),
                    'reason' => ! $interruptible ? 'Interruptible provider transport is unavailable on this server.'
                        : ($checked
                            ? 'The last provider check succeeded. This is not a generation guarantee.'
                            : 'The provider is enabled, but a successful current generation has not been verified.'),
                    'last_checked_at' => $provider->last_checked_at?->toISOString(),
                ],
            ],
        ];
    }

    public function tools(array $selected, array $metadata): array
    {
        $tools = ['file_analysis' => true, 'image_generation' => false, 'web_search' => false, 'code_interpreter' => false];
        foreach ($selected as $name => $enabled) {
            if (! array_key_exists($name, $tools) || ! is_bool($enabled)) {
                throw ValidationException::withMessages(['tools' => 'Tool selections must contain known boolean controls.']);
            }
            if ($enabled && ! ($metadata['tools'][$name]['available'] ?? false)) {
                throw ValidationException::withMessages(['tools.'.$name => $metadata['tools'][$name]['reason'] ?? 'This tool is unavailable.']);
            }
            $tools[$name] = $enabled;
        }

        return $tools;
    }

    public function messages(array $messages, array $metadata, bool $ownedFilesOnly): array
    {
        $bytes = 0;
        $validated = [];
        foreach ($messages as $message) {
            $role = $message['role'];
            if ($ownedFilesOnly && $role === 'system') {
                throw ValidationException::withMessages(['messages' => 'Workspace context must be submitted as user content, not system instructions.']);
            }
            // ConvertEmptyStringsToNull turns an attachment-only turn's '' into null; both mean no text.
            $content = $message['content'] ?? '';
            if (is_string($content)) {
                $bytes += strlen($content);
            } elseif (is_array($content) && array_is_list($content)) {
                foreach ($content as $part) {
                    if (! is_array($part)) {
                        throw ValidationException::withMessages(['messages' => 'A message contains an invalid content part.']);
                    }
                    if (($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null)) {
                        $bytes += strlen($part['text']);
                        continue;
                    }
                    if ($ownedFilesOnly || $role !== 'user') {
                        throw ValidationException::withMessages(['messages' => 'Upload files and select their owned attachment IDs.']);
                    }
                    $kind = $part['type'] ?? null;
                    $data = $kind === 'image_url'
                        ? ($part['image_url']['url'] ?? null)
                        : (in_array($kind, ['file', 'input_file'], true) ? ($part['file']['file_data'] ?? $part['file_data'] ?? null) : null);
                    if (! is_string($data) || ! preg_match('/\Adata:([a-z0-9.+-]+\/[a-z0-9.+-]+);base64,([A-Za-z0-9+\/=\r\n]+)\z/i', $data, $match)) {
                        throw ValidationException::withMessages(['messages' => 'Only typed inline images or native PDF documents are accepted.']);
                    }
                    $mime = strtolower($match[1]);
                    if (! in_array($mime, $metadata['input']['accepted_mimes'], true)
                        || ($kind === 'image_url' && ! in_array($mime, self::IMAGE_MIMES, true))
                        || ($kind !== 'image_url' && $mime !== 'application/pdf')) {
                        throw ValidationException::withMessages(['messages' => 'This model does not support the attached file type.']);
                    }
                    if (strlen($match[2]) > (int) ceil(self::FILE_LIMIT / 3) * 4) {
                        throw ValidationException::withMessages(['messages' => 'The attached file exceeds the upload limit.']);
                    }
                    $decoded = base64_decode($match[2], true);
                    if ($decoded === false || $decoded === '' || strlen($decoded) > self::FILE_LIMIT
                        || (new \finfo(FILEINFO_MIME_TYPE))->buffer($decoded) !== $mime) {
                        throw ValidationException::withMessages(['messages' => 'The attached bytes do not match their declared file type.']);
                    }
                    $bytes += strlen($decoded);
                }
            } else {
                throw ValidationException::withMessages(['messages' => 'Message content must be text or typed content parts.']);
            }
            if ($bytes > self::TOTAL_LIMIT) {
                throw ValidationException::withMessages(['messages' => 'The conversation context exceeds the input size limit.']);
            }
            $validated[] = ['role' => $role, 'content' => $content];
        }

        return $validated;
    }

    public function attachments(Collection $attachments, array $metadata, array $tools): array
    {
        if ($attachments->isNotEmpty() && ! $tools['file_analysis']) {
            throw ValidationException::withMessages(['tools.file_analysis' => 'Enable file analysis to include selected attachments, or remove those attachments.']);
        }
        if ($attachments->count() > $metadata['input']['max_files']) {
            throw ValidationException::withMessages(['attachment_ids' => 'Too many files are selected.']);
        }
        $totalBytes = 0;
        $textBytes = 0;
        $snapshot = [];
        foreach ($attachments as $attachment) {
            $asset = $attachment->asset;
            $limit = $metadata['input']['limits_by_mime'][$asset->mime] ?? null;
            if ($limit === null || $asset->size_bytes > $limit || ! $asset->signature_ok) {
                throw ValidationException::withMessages(['attachment_ids' => 'A selected file is incompatible with this model or exceeds its size limit.']);
            }
            $totalBytes += $asset->size_bytes;
            if ($totalBytes > self::TOTAL_LIMIT) {
                throw ValidationException::withMessages(['attachment_ids' => 'The selected files exceed the combined input limit.']);
            }
            $name = $asset->original_name ?: ($asset->metadata['original_name'] ?? 'Attachment');
            $entry = [
                'id' => $attachment->id, 'asset_id' => $asset->id, 'name' => $name,
                'mime' => $asset->mime, 'size_bytes' => $asset->size_bytes,
            ];
            if (in_array($asset->mime, self::TEXT_MIMES, true)) {
                $textBytes += $asset->size_bytes;
                if ($textBytes > self::TEXT_LIMIT) {
                    throw ValidationException::withMessages(['attachment_ids' => 'Combined text files exceed the 2 MiB context limit.']);
                }
                $text = Storage::disk($asset->storage_disk)->get($asset->storage_path);
                if (! is_string($text) || strlen($text) > self::TEXT_LIMIT || ! mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) {
                    throw ValidationException::withMessages(['attachment_ids' => 'The selected text file is not valid bounded UTF-8 text.']);
                }
                $entry['text'] = $text;
            }
            $snapshot[] = $entry;
        }

        return $snapshot;
    }

    private function flags(array $values): array
    {
        $flags = [];
        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $flags[] = strtolower($value);
            } elseif (is_string($key) && $value === true) {
                $flags[] = strtolower($key);
            }
        }

        return $flags;
    }
}
