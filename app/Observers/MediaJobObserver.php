<?php

namespace App\Observers;

use App\Models\AudioJob;
use App\Models\ImageJob;
use App\Models\MediaToolJob;
use App\Models\Notification;
use App\Models\VideoJob;
use Illuminate\Database\Eloquent\Model;

final class MediaJobObserver
{
    public function updated(Model $job): void
    {
        if (! $job->wasChanged(['status', 'stage']) || $this->terminalState($job->status, $job->stage) === null) {
            return;
        }

        if ($this->terminalState($job->getRawOriginal('status'), $job->getRawOriginal('stage')) !== null) {
            return;
        }

        // Persist with the terminal write, not in a later broadcast callback. The
        // job lock serializes duplicate callbacks without a second inbox schema.
        $job->getConnection()->transaction(function () use ($job): void {
            $record = $job->newQuery()->lockForUpdate()->find($job->getKey());
            if (! $record || ! $record->user_id || ! $record->job_id) {
                return;
            }

            $state = $this->terminalState($record->status, $record->stage);
            $destination = match (true) {
                $record instanceof ImageJob => ['image', 'Gambar', '/generate-image'],
                $record instanceof VideoJob => ['video', 'Video', '/video'],
                $record instanceof AudioJob => ['audio', 'Audio', '/audio'],
                $record instanceof MediaToolJob && $record->kind === 'download' => ['download', 'Unduhan', '/downloads'],
                $record instanceof MediaToolJob && $record->kind === 'convert' => ['convert', 'Konversi', '/converter'],
                default => null,
            };
            if ($state === null || $destination === null) {
                return;
            }

            [$kind, $label, $path] = $destination;
            $action = $path.'?job='.rawurlencode((string) $record->job_id);
            $notifications = Notification::on($job->getConnectionName());
            if ((clone $notifications)->where('user_id', $record->user_id)
                ->where('kind', 'media')->where('action_url', $action)->exists()) {
                return;
            }

            $notifications->create([
                'user_id' => $record->user_id,
                'kind' => 'media',
                'title' => $label.match ($state) {
                    'completed' => ' selesai',
                    'cancelled' => ' dibatalkan',
                    default => ' gagal',
                },
                'body' => match ($state) {
                    'completed' => 'Hasil Anda sudah tersimpan. Buka studio untuk melihat atau mengunduhnya.',
                    'cancelled' => 'Pekerjaan telah dibatalkan. Buka riwayat untuk melihat detailnya.',
                    default => 'Pekerjaan tidak dapat diselesaikan. Buka riwayat untuk melihat detailnya.',
                },
                'action_url' => $action,
                'metadata' => [
                    'media_type' => $kind,
                    'job_id' => $record->job_id,
                    'status' => $state,
                ],
            ]);
        });
    }

    private function terminalState(?string $status, ?string $stage): ?string
    {
        if ($status === 'cancelled' || ($status === 'failed' && $stage === 'cancelled')) {
            return 'cancelled';
        }

        return in_array($status, ['completed', 'failed'], true) ? $status : null;
    }
}
