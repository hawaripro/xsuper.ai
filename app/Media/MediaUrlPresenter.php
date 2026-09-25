<?php

namespace App\Media;

use App\Services\WorkspaceMediaOutputStore;
use Illuminate\Support\Facades\URL;
use stdClass;

/** Presentation only: retained originals and their ownership do not depend on a transport. */
final class MediaUrlPresenter
{
    public function __construct(private readonly bool $api = false) {}

    public function output(string $jobId, string $outputId, int $ownerId, bool $previewable): array
    {
        if (! $this->api) {
            return ['url' => $previewable ? '/api/media/workspace/jobs/'.rawurlencode($jobId).'/outputs/'.rawurlencode($outputId).'/preview' : null,
                'download_url' => WorkspaceMediaOutputStore::downloadUrl($jobId, $outputId)];
        }
        $expires = now()->addMinutes(60);

        return ['url' => $this->download($jobId, $outputId),
            'signed_url' => URL::temporarySignedRoute('api.media.outputs.signed', $expires,
                ['id' => $jobId, 'outputId' => $outputId, 'owner' => $ownerId]),
            'signed_url_expires_at' => $expires->toISOString()];
    }

    private function download(string $jobId, string $outputId): string
    {
        return route('api.media.outputs', ['id' => $jobId, 'outputId' => $outputId]);
    }

    /** Rewrite exact retained-output links, not arbitrary strings or another job's URLs. */
    public function result(mixed $value, string $jobId): mixed
    {
        if (! $this->api) {
            return $value;
        }
        if ($value instanceof stdClass) {
            $out = new stdClass;
            foreach (get_object_vars($value) as $key => $child) {
                $out->{$key} = $this->result($child, $jobId);
            }

            return $out;
        }
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $value[$key] = $this->result($child, $jobId);
            }

            return $value;
        }
        $prefix = '/api/media/workspace/jobs/'.rawurlencode($jobId).'/outputs/';
        if (is_string($value) && str_starts_with($value, $prefix) && str_ends_with($value, '/download')) {
            $output = substr($value, strlen($prefix), -strlen('/download'));
            if ($output !== '' && ! str_contains($output, '/')) {
                return $this->download($jobId, rawurldecode($output));
            }
        }

        return $value;
    }
}
