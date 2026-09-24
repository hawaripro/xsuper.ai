<?php

namespace App\Media;

use App\Media\Enums\InputRole;
use App\Media\Enums\MediaOperation;
use App\Media\Enums\OutputKind;
use InvalidArgumentException;
use Throwable;

/** The documented WMA profile is a media session, never a Queue invocation. */
final class FalRealtimeCapabilityImporter
{
    public function normalize(string $modelPublicId, string $endpointId, array $metadata, array $document, string $sourceHash): array
    {
        $blockers = [];
        $bindings = [];
        $capability = null;
        try {
            $profile = $document['x-fal-wma'] ?? [];
            if ($endpointId !== 'minimax/h3-max/director' || ($document['asyncapi'] ?? '') !== '3.1.0'
                || ($profile['profileVersion'] ?? '') !== '0.1' || ($profile['perspective'] ?? '') !== 'client'
                || ($document['servers']['session']['protocol'] ?? '') !== 'webrtc'
                || ($profile['configuredSession']['replay'] ?? '') !== 'never'
                || ($profile['sequence'] ?? []) != ['increment' => 1, 'field' => '/prompt_version', 'initial' => 1, 'scope' => 'session']) {
                throw new InvalidArgumentException('This AsyncAPI identity or WMA lifecycle differs from the implemented Director contract.');
            }
            $features = $profile['requiredFeatures'] ?? [];
            sort($features);
            if ($features !== ['configured-session/1', 'versioned-input/1']) {
                throw new InvalidArgumentException('The source requires an unimplemented WMA protocol feature.');
            }
            $configure = $this->message($document, 'client.configure');
            $update = $this->message($document, 'client.prompt');
            if (($configure['properties']['type']['const'] ?? '') !== 'configure'
                || ($configure['properties']['protocol_version']['const'] ?? null) !== 1
                || ($update['properties']['type']['const'] ?? '') !== 'prompt') {
                throw new InvalidArgumentException('The source configure/update protocol constants changed.');
            }
            $events = [];
            foreach (array_keys($document['channels']['control']['messages'] ?? []) as $name) {
                if (str_starts_with($name, 'server.')) {
                    $schema = $this->message($document, $name);
                    $type = $schema['properties']['type']['const'] ?? null;
                    if (! is_string($type)) {
                        throw new InvalidArgumentException('A WMA event has no fixed event type.');
                    }
                    $events[$type] = $schema;
                }
            }
            foreach (['configured', 'prompt_pending', 'prompt_applied', 'prompt_rejected', 'error', 'stream_exhausted'] as $type) {
                if (! isset($events[$type])) {
                    throw new InvalidArgumentException('A required WMA lifecycle event schema is missing.');
                }
            }
            $inputSchema = $this->publicSchema($configure, ['type', 'protocol_version', 'prompt_version']);
            $updateSchema = $this->publicSchema($update, ['type', 'prompt_version']);
            $bindings = [
                'adapter' => 'fal_wma_v1', 'endpoint' => $endpointId, 'transport' => 'realtime',
                'source_hash' => $sourceHash, 'max_session_seconds' => 60,
                'configure_schema' => $configure, 'update_schema' => $update, 'event_schemas' => $events,
                'public_update_schema' => $updateSchema, 'wma_profile' => $profile,
                'execution' => ['transport' => 'realtime', 'max_session_seconds' => 60, 'update_schema' => $updateSchema],
            ];
            $capability = new MediaCapability($modelPublicId, MediaOperation::RealtimeVideo, OutputKind::Video, 2,
                providerBindings: $bindings, inputSchema: $inputSchema,
                outputSchema: ['oneOf' => array_values($events), 'x-fal-media' => $document['x-fal-media'] ?? []]);
        } catch (Throwable $exception) {
            $blockers[] = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'The AsyncAPI control schemas could not be resolved safely.';
        }
        $warnings = [
            'This is live WebRTC video/audio, not a downloadable video-file response. Recording is optional and saves actual received bytes.',
            'One manually reviewed per-request price buys one session of at most 60 seconds. Resolution and session configuration affect upstream costs; no renewal or reconnect is automatic.',
            'Official WMA client/profile APIs are experimental. Source compatibility is not paid generation verification.',
        ];

        return ['capability' => $capability, 'operation' => MediaOperation::RealtimeVideo->value, 'provider_bindings' => $bindings,
            'limitations' => [...$blockers, ...$warnings], 'publishable' => $blockers === [],
            'report' => ['version' => 2, 'compatible' => $blockers === [], 'blockers' => $blockers, 'warnings' => $warnings,
                'test_kind' => 'offline_contract', 'live_verified' => false]];
    }

    private function message(array $document, string $name): array
    {
        $reference = $document['channels']['control']['messages'][$name] ?? null;
        if (! is_array($reference)) {
            throw new InvalidArgumentException('The AsyncAPI control message is missing.');
        }
        $message = MediaJsonSchema::resolve($reference, $document);
        $payload = is_array($message) ? ($message['payload'] ?? null) : null;
        $payload = is_array($payload) ? MediaJsonSchema::resolve($payload, $document) : null;
        if (! is_array($payload) || ($payload['type'] ?? null) !== 'object') {
            throw new InvalidArgumentException('The AsyncAPI control payload is not a typed object.');
        }

        return $payload;
    }

    private function publicSchema(array $schema, array $controlled): array
    {
        foreach ($controlled as $name) {
            unset($schema['properties'][$name]);
        }
        $schema['required'] = array_values(array_diff($schema['required'] ?? [], $controlled));

        return $this->assets($schema);
    }

    private function assets(array $schema, ?string $field = null): array
    {
        $role = match ($field) {
            'image_url' => InputRole::InitFrame,
            'end_image_url' => InputRole::EndFrame,
            'audio_url' => InputRole::AudioReference,
            default => null,
        };
        if ($role !== null && ($schema['type'] ?? null) === 'string') {
            // The same file leaf as queued contracts: an owned upload (UUID) or a public HTTPS link the provider fetches.
            $schema = [...array_intersect_key($schema, array_flip(['title', 'description', 'deprecated'])),
                'type' => 'string', 'minLength' => 1, 'maxLength' => 2048,
                'x-workspace-asset' => ['kind' => $field === 'audio_url' ? 'audio' : 'image', 'role' => $role->value, 'accepts_url' => true]];
        }
        foreach ($schema['properties'] ?? [] as $name => $child) {
            $schema['properties'][$name] = $this->assets($child, $name);
        }
        foreach (['anyOf', 'oneOf', 'allOf'] as $keyword) {
            foreach ($schema[$keyword] ?? [] as $index => $child) {
                $schema[$keyword][$index] = $this->assets($child, $field);
            }
        }
        if (is_array($schema['items'] ?? null)) {
            $schema['items'] = $this->assets($schema['items']);
        }

        return $schema;
    }
}
