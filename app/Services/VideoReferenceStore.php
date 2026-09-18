<?php

namespace App\Services;

use App\Exceptions\AiProxyException;
use App\Models\VideoJob;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class VideoReferenceStore
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    private const MAX_DIMENSION = 8192;

    private const MAX_PIXELS = 40_000_000;

    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function validate(UploadedFile $image): array
    {
        if (! $image->isValid() || $image->getSize() < 1 || $image->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['reference_image' => 'Choose a JPEG, PNG or WebP image no larger than 10 MiB.']);
        }
        $bytes = file_get_contents($image->getRealPath(), false, null, 0, self::MAX_BYTES + 1);
        $mime = is_string($bytes) ? $this->mime($bytes) : null;
        if ($mime === null) {
            throw ValidationException::withMessages(['reference_image' => 'Choose a valid JPEG, PNG or WebP image, at most 8192 pixels per side and 40 megapixels.']);
        }

        return ['bytes' => $bytes, 'mime' => $mime];
    }

    public function persist(string $jobId, array $reference): string
    {
        $path = self::path($jobId, $reference['mime']);
        try {
            if (! Storage::disk('local')->put($path, $reference['bytes'], ['visibility' => 'private'])) {
                throw new AiProxyException('The reference image could not be saved.', 503);
            }
        } catch (Throwable $exception) {
            $this->delete($path);
            throw $exception;
        }

        return $path;
    }

    public function existingPath(VideoJob $job): ?string
    {
        if (! $job->has_reference || ! isset(self::EXTENSIONS[$job->reference_mime_type])) {
            return null;
        }
        $path = self::path($job->job_id, $job->reference_mime_type);

        return $job->reference_path === $path && Storage::disk('local')->exists($path) ? $path : null;
    }

    public function dataUri(VideoJob $job): string
    {
        $path = $this->existingPath($job);
        if ($path === null) {
            throw new AiProxyException('The private reference image is unavailable.', 503);
        }
        $stream = Storage::disk('local')->readStream($path);
        if (! is_resource($stream)) {
            throw new AiProxyException('The private reference image could not be read.', 503);
        }
        try {
            $bytes = stream_get_contents($stream, self::MAX_BYTES + 1);
        } finally {
            fclose($stream);
        }
        if (! is_string($bytes) || $this->mime($bytes) !== $job->reference_mime_type) {
            throw new AiProxyException('The private reference image is invalid.', 503);
        }

        return 'data:'.$job->reference_mime_type.';base64,'.base64_encode($bytes);
    }

    public function delete(string $path): bool
    {
        try {
            return Storage::disk('local')->delete($path);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private static function path(string $jobId, string $mime): string
    {
        return 'video-references/'.hash('sha256', $jobId).'.'.self::EXTENSIONS[$mime];
    }

    private function mime(string $bytes): ?string
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            return null;
        }
        $info = @getimagesizefromstring($bytes);
        $mime = $info['mime'] ?? null;
        if (! isset(self::EXTENSIONS[$mime]) || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1
            || $info[0] > self::MAX_DIMENSION || $info[1] > self::MAX_DIMENSION
            || $info[0] > intdiv(self::MAX_PIXELS, $info[1])) {
            return null;
        }

        return $mime;
    }
}
