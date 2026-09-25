<?php

namespace App\Media\Adapters;

use App\Exceptions\AiProviderNotSent;
use App\Exceptions\AiProviderRequestRejected;
use App\Exceptions\AiProxyException;
use App\Media\AdapterSupport;
use App\Media\Contracts\AssignsProviderTaskIds;
use App\Media\Contracts\MediaProviderAdapter;
use App\Media\Contracts\StagesProviderReferences;
use App\Media\MediaCapability;
use App\Media\MediaJsonSchema;
use App\Media\MediaReferenceStager;
use App\Media\StatusResult;
use App\Media\SubmitResult;
use App\Models\AiProviderProfile;
use App\Services\AiProviderTransport;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Executes a reviewed runware_v1 binding as one asynchronous task per job. The client-generated
 * taskUUID is created once in buildRequest() and persisted by the caller before the only POST, so
 * an unknown acceptance can be reconciled with getResponse. Runware has no cancellation and bills
 * only successful results; its per-result USD cost stays in the private provider result.
 */
final class RunwareAdapter implements AssignsProviderTaskIds, MediaProviderAdapter, StagesProviderReferences
{
    /** Request members owned by the adapter and the reviewed binding, never by member input. */
    private const TRANSPORT_FIELDS = ['taskType', 'taskUUID', 'model', 'webhookURL', 'uploadEndpoint', 'deliveryMethod', 'outputType', 'ttl', 'includeCost'];

    /** Platform operations are never a reviewed generation binding. */
    private const PLATFORM_TASKS = ['authentication', 'getResponse', 'getTaskDetails', 'accountManagement', 'mediaStorage',
        'imageUpload', 'modelSearch', 'modelUpload', 'ping', 'textInference', 'training'];

    /** Result members that hold a generated file per Runware task type (3D and some others use outputs.files[].url). */
    private const OUTPUT_URL_FIELDS = ['imageURL', 'videoURL', 'audioURL', 'guideImageURL', 'maskImageURL'];

    public function __construct(private readonly AiProviderTransport $transport, private readonly MediaReferenceStager $references) {}

    public function support(): AdapterSupport
    {
        return new AdapterSupport(async: true, polling: true, webhook: false, cancel: false);
    }

    public function buildRequest(MediaCapability $capability, array $inputs, string $upstreamModel): array
    {
        $bindings = $capability->providerBindings;
        $taskType = $bindings['task_type'] ?? null;
        $declared = $bindings['request_schema']['properties'] ?? null;
        if ($capability->contractVersion !== 2 || ($bindings['adapter'] ?? null) !== 'runware_v1'
            || ($bindings['endpoint'] ?? null) !== $upstreamModel || ! self::validModel($upstreamModel)
            || ($bindings['transport'] ?? null) !== 'async' || ! is_array($declared) || ! isset($declared['deliveryMethod'])
            || ! is_string($taskType) || preg_match('/^[A-Za-z0-9]{1,64}$/D', $taskType) !== 1 || in_array($taskType, self::PLATFORM_TASKS, true)) {
            throw new InvalidArgumentException('The published provider binding is invalid.');
        }
        $member = array_diff_key($inputs['inputs'] ?? [], array_flip(self::TRANSPORT_FIELDS));
        $constants = array_diff_key(is_array($bindings['constants'] ?? null) ? $bindings['constants'] : [],
            array_flip(['taskType', 'taskUUID', 'model', 'webhookURL', 'uploadEndpoint']));
        $taskId = (string) Str::uuid();
        // Results are only read back through getResponse, as downloadable URLs with the actual cost.
        $task = ['taskType' => $taskType, 'taskUUID' => $taskId, 'model' => $upstreamModel, ...$member, ...$constants,
            'deliveryMethod' => 'async', 'outputType' => 'URL', 'includeCost' => true];
        foreach (['outputType', 'includeCost', 'ttl'] as $field) {
            // Each task schema rejects undeclared members (e.g. captions have no outputType).
            if (! array_key_exists($field, $declared)) {
                unset($task[$field]);
            }
        }

        return [
            'task' => $task, 'task_uuid' => $taskId, 'request_schema' => $bindings['request_schema'],
            'owner_id' => isset($inputs['owner_id']) ? (int) $inputs['owner_id'] : null,
            'assets' => $inputs['asset_paths'] ?? MediaJsonSchema::assetReferences($capability->inputSchema, $member),
        ];
    }

    public function taskId(array $request): string
    {
        $taskId = $request['task_uuid'] ?? null;
        if (! is_string($taskId) || ! Str::isUuid($taskId) || ($request['task']['taskUUID'] ?? null) !== $taskId) {
            throw new InvalidArgumentException('The provider task identity is invalid.');
        }

        return $taskId;
    }

    /** Uploads each owned reference once through mediaStorage and writes its mediaUUID into the task at its schema path. */
    public function stageReferences(AiProviderProfile $provider, array $request): array
    {
        $task = $request['task'];
        $staged = [];
        foreach ($request['assets'] ?? [] as $reference) {
            $id = $reference['asset_id'];
            $mediaId = $staged[$id] ??= $this->references->stage($provider, $id, $request['owner_id'] ?? null);
            $target = &$task;
            foreach ($reference['path'] as $segment) {
                if (! is_array($target) || ! array_key_exists($segment, $target)) {
                    throw new InvalidArgumentException('The reference association is invalid.');
                }
                $target = &$target[$segment];
            }
            $target = $mediaId;
            unset($target);
        }

        return [...$request, 'task' => $task, 'assets' => []];
    }

    public function submit(AiProviderProfile $provider, array $request): SubmitResult
    {
        try {
            $request = $this->stageReferences($provider, $request);
            $taskId = $this->taskId($request);
        } catch (Throwable) {
            // Staging has not submitted a generation, so it can safely reject admission.
            return SubmitResult::rejected('The reference file could not be staged. No generation was submitted.');
        }
        // Staged IDs are in place; restore schema-declared (including empty) JSON objects.
        $task = MediaJsonSchema::objectMembers($request['request_schema'] ?? true, $request['task']);
        try {
            $result = $this->transport->runwareTasks($provider, [$task], 60);
        } catch (AiProviderRequestRejected $error) {
            return $this->rejected($provider, $task, $error->upstreamStatus, $error->providerCode);
        } catch (AiProviderNotSent $error) {
            // Validation, configuration, DNS pinning or the destination guard failed before anything was sent.
            return SubmitResult::rejected($error->responseStatus() === 422
                ? 'The media provider rejected this request. No generation was submitted.'
                : 'The media provider is temporarily unavailable. No generation was submitted.');
        } catch (AiProxyException) {
            // Anything raised once the request may have left the server can follow an accepted task.
            return SubmitResult::uncertain();
        }
        $errors = array_values(array_filter($result['errors'], static fn (array $error): bool => ($error['taskUUID'] ?? null) === $taskId));
        $accepted = array_filter($result['data'], static fn (array $item): bool => ($item['taskUUID'] ?? null) === $taskId) !== [];
        if ($errors === [] && ! $accepted && $result['errors'] !== []) {
            // A request-level error (no acknowledgement for this task) rejected the whole call.
            $errors = $result['errors'];
        }
        if ($errors !== []) {
            return $this->rejected($provider, $task, 200, self::code($errors[0]));
        }

        return $accepted ? SubmitResult::accepted($taskId) : SubmitResult::uncertain();
    }

    public function pollStatus(AiProviderProfile $provider, string $taskId, array $context = []): StatusResult
    {
        if (! Str::isUuid($taskId)) {
            throw new AiProxyException('The media provider job reference is invalid.', 422);
        }
        $result = $this->transport->runwareTasks($provider, [['taskType' => 'getResponse', 'taskUUID' => $taskId]], 20);
        $items = array_values(array_filter($result['data'], static fn (array $item): bool => ($item['taskUUID'] ?? null) === $taskId));
        $errors = array_values(array_filter($result['errors'], static fn (array $error): bool => ($error['taskUUID'] ?? null) === $taskId));
        foreach ($items as $item) {
            if (($item['status'] ?? null) === 'error') {
                $errors[] = is_array($item['error'] ?? null) ? $item['error'] : $item;
            }
        }
        if ($errors !== []) {
            if (collect($errors)->contains(static fn (array $error): bool => self::code($error) === 'taskNotFound')) {
                // Not proof of rejection: acceptance may not be visible yet. The caller keeps the reservation.
                throw new AiProxyException('The media provider has no record of this request yet.', 404);
            }
            // Any failed result fails the whole job, even when other results of a numberResults > 1 task succeeded:
            // MediaTokenBillingService settles or releases one reservation as a whole (no partial settlement), so the
            // member is refunded in full and the successful results are discarded, although Runware bills them.
            return StatusResult::failed('The media provider could not complete this request.');
        }
        if ($items === []) {
            throw new AiProxyException('The media provider status could not be confirmed.', 502);
        }
        $expected = max(1, (int) ($context['quantity'] ?? 1));
        $done = array_values(array_filter($items, static fn (array $item): bool => ($item['status'] ?? null) === 'success'));
        if (count($done) === count($items) && count($done) >= $expected) {
            return StatusResult::completed(self::outputUrls($done), $done);
        }

        return StatusResult::processing(self::progress($items, $expected));
    }

    public function cancel(AiProviderProfile $provider, string $taskId, array $context = []): array
    {
        return ['requested' => false, 'confirmed' => false];
    }

    public function normalizeError(Throwable $error): array
    {
        return [
            'public_code' => 'runware_media_error', 'public_message' => 'Media generation could not be completed.',
            'recoverable' => $error instanceof AiProxyException && in_array($error->responseStatus(), [503, 504], true),
            'internal' => $error::class,
        ];
    }

    /** Runware's published model identifier pattern (AIR such as runware:101@1), bounded. */
    public static function validModel(string $model): bool
    {
        return preg_match('/^[A-Za-z0-9._-]{1,100}(?::[A-Za-z0-9._\/@-]{1,160})?$/D', $model) === 1;
    }

    private function rejected(AiProviderProfile $provider, array $task, int $status, ?string $code): SubmitResult
    {
        $context = ['provider_id' => $provider->id, 'task_type' => $task['taskType'] ?? null, 'task_uuid' => $task['taskUUID'] ?? null,
            'model' => $task['model'] ?? null, 'http_status' => $status, 'code' => $code];
        // The SDK's quota class: HTTP 402, paymentRequired, or a code naming credits/quota/balance.
        if ($status === 402 || ($code !== null && ($code === 'paymentRequired' || preg_match('/credit|quota|balance/i', $code) === 1))) {
            Log::error('Runware rejected a generation because the account balance is insufficient. Top up the Runware account; the member was refunded and no generation ran.', $context);

            return SubmitResult::rejected('The media provider is temporarily unavailable. No generation was submitted.');
        }
        Log::warning('Runware rejected a generation request; the member was refunded and no generation ran.', $context);

        return SubmitResult::rejected('The media provider rejected this request. No generation was submitted.');
    }

    private static function code(array $error): ?string
    {
        $code = $error['code'] ?? null;

        return is_string($code) && preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $code) === 1 ? $code : null;
    }

    /** @return list<string> */
    private static function outputUrls(array $items): array
    {
        $urls = [];
        foreach ($items as $item) {
            foreach (self::OUTPUT_URL_FIELDS as $field) {
                if (is_string($item[$field] ?? null)) {
                    $urls[] = $item[$field];
                }
            }
            foreach (is_array($item['outputs']['files'] ?? null) ? $item['outputs']['files'] : [] as $file) {
                if (is_array($file) && is_string($file['url'] ?? null)) {
                    $urls[] = $file['url'];
                }
            }
        }

        return $urls;
    }

    /** Completion over every expected result, finished results included; null when nothing reports progress yet. */
    private static function progress(array $items, int $expected): ?int
    {
        $reported = false;
        $total = 0;
        foreach ($items as $item) {
            if (($item['status'] ?? null) === 'success') {
                $reported = true;
                $total += 100;
            } elseif (is_int($item['progress'] ?? null) || is_float($item['progress'] ?? null)) {
                $reported = true;
                $total += max(0, min(100, (int) $item['progress']));
            }
        }

        // A task that is still processing is never presented as complete.
        return $reported ? min(99, intdiv($total, max($expected, count($items)))) : null;
    }
}
