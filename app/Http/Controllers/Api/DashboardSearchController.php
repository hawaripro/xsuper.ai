<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiModelProfile;
use App\Models\PromptTemplate;
use App\Models\User;
use App\Services\AiProxyService;
use App\Services\WorkspaceMediaService;
use App\Support\StudioLink;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardSearchController extends Controller
{
    private const GROUP_LIMIT = 6;

    private const TOTAL_LIMIT = 40;

    // job_id is a native uuid on PostgreSQL, which has no LOWER(uuid); the cast is portable to SQLite.
    private const JOB_ID = 'CAST(job_id AS TEXT)';

    public function show(Request $request, AiProxyService $catalog, WorkspaceMediaService $workspace): JsonResponse
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:120']]);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $query = trim($validated['q'] ?? '');
        $needle = mb_strtolower($query);
        $access = $this->access($user);
        $groups = $this->destinations($user, $access, $needle);

        if ($query !== '') {
            // Bound parameters and an explicit escape work on PostgreSQL and SQLite.
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $needle).'%';
            $this->addGroup($groups, 'models', 'Model AI', $this->models($user, $access, $pattern, $needle, $catalog, $workspace));
            if ($access['history']) {
                $this->addGroup($groups, 'conversations', 'Riwayat chat', $this->conversations($user, $pattern));
            }
            foreach ([
                'image' => ['image_jobs', 'Gambar Anda'],
                'video' => ['video_jobs', 'Video Anda'],
                'audio' => ['audio_jobs', 'Audio Anda'],
                'avatar' => ['video_jobs', 'Avatar Anda'],
                'model3d' => ['three_d_jobs', 'Model 3D Anda'],
            ] as $kind => [$table, $label]) {
                if ($access[$kind]) {
                    $this->addGroup($groups, $kind, $label, $this->media($user, $table, $kind, $pattern));
                }
            }
            if ($access['download'] || $access['convert']) {
                $this->addGroup($groups, 'media-tools', 'File Anda', $this->tools($user, $access, $pattern));
            }
            if ($access['templates']) {
                $this->addGroup($groups, 'templates', 'Template Prompt', $this->templates($pattern));
            }
        }

        return response()->json(['query' => $query, 'groups' => $groups])
            ->header('Cache-Control', 'private, no-store');
    }

    private function access(User $user): array
    {
        // Same rule as EnsureActive/CheckExpiry: only an explicit false is inactive (an unloaded column is null).
        $active = $user->isAdmin() || ($user->is_active !== false && ! $user->isExpired());
        $chat = $active && $user->hasPermission('chat');

        return [
            'active' => $active,
            'chat' => $chat,
            'history' => $chat && $user->hasPermission('chat_history'),
            'templates' => $chat,
            'image' => $active && $user->hasPermission('image_generator'),
            'video' => $active && $user->hasPermission('video_generator'),
            // These pages retain permission-gated access to owned results after expiry.
            'audio' => $user->hasPermission('audio_generator'),
            'avatar' => $user->hasPermission('video_generator'),
            'model3d' => $user->hasPermission('image_generator'),
            'download' => $user->hasPermission('video_downloader'),
            'convert' => $user->hasPermission('media_converter'),
        ];
    }

    private function destinations(User $user, array $access, string $needle): array
    {
        $sections = [
            ['workspace', 'Workspace', [
                ['dashboard', 'Dashboard', 'Ringkasan akun dan aktivitas', '/dashboard', true, 'overview beranda'],
                ['library', 'Library', 'Unggahan dan semua hasil studio Anda', '/library', true, 'library file gambar video audio unduhan konversi'],
                ['history', 'Riwayat chat', 'Baca percakapan yang tersimpan', '/chat', $access['history'], 'history conversation percakapan'],
                ['templates', 'Template Prompt', 'Pilih prompt untuk percakapan baru', '/templates', $access['templates'], 'prompt library'],
                ['notifications', 'Notifikasi', 'Pesan dan pembaruan akun', '/notifications', true, 'notifications inbox'],
            ]],
            ['studios', 'Studio', [
                ['chat', 'Chat AI', 'Buka workspace percakapan', '/chat', $access['chat'], 'conversation percakapan'],
                ['studio', 'Studio Media', 'Semua model gambar, video, audio, avatar, dan 3D', StudioLink::to(),
                    $access['image'] || $access['video'] || $access['audio'] || $access['avatar'] || $access['model3d'], 'studio media model generate gambar video audio avatar 3d'],
                ['image', 'Studio gambar', 'Buat dan tinjau gambar', StudioLink::to('image'), $access['image'], 'image generate gambar'],
                ['video', 'Studio video', 'Buat dan tinjau video', StudioLink::to('video'), $access['video'], 'video generator'],
                ['audio', 'Studio audio', 'Suara, musik, dan hasil tersimpan', StudioLink::to('audio'), $access['audio'], 'audio music musik speech voice suara'],
                ['avatar', 'Studio avatar', 'Foto dan ucapan menjadi avatar berbicara', StudioLink::to('avatar'), $access['avatar'], 'avatar talking portrait wajah'],
                ['model3d', 'Studio 3D', 'Buat dan periksa model tiga dimensi', StudioLink::to('model3d'), $access['model3d'], '3d model mesh glb'],
            ]],
            ['tools', 'Alat media', [
                ['download', 'Video Downloader', 'Unduh media yang boleh Anda gunakan', '/downloads', $access['download'], 'download unduh'],
                ['convert', 'Media Converter', 'Ubah format file media', '/converter', $access['convert'], 'convert konversi format'],
            ]],
            ['account', 'Akun', [
                ['usage', 'Pemakaian', 'Tinjau pemakaian token dan wallet', '/token-usage', true, 'usage tokens wallet'],
                ['deposit', 'Deposit dan langganan', 'Kelola saldo dan masa aktif', '/deposit', true, 'subscription billing balance paket topup'],
                ['referral', 'Referral', 'Tinjau undangan dan hadiah', '/referral', true, 'invite reward undang'],
                ['support', 'Bantuan', 'Buka tiket dukungan Anda', '/bantuan', true, 'help support ticket'],
                ['profile', 'Profil', 'Kelola informasi akun', '/profile', true, 'profile account'],
            ]],
        ];
        if ($user->isAdmin()) {
            $sections[] = ['administration', 'Administrasi', [
                ['admin-overview', 'Admin overview', 'Ringkasan operasional', '/admin/overview', true, 'admin dashboard'],
                ['admin-users', 'Kelola pengguna', 'Akun dan izin anggota', '/admin/users', true, 'admin users members permissions'],
                ['admin-usage', 'Pemakaian anggota', 'Tinjau pemakaian seluruh anggota', '/admin/token-usage', true, 'admin usage token'],
                ['admin-operations', 'Operasional', 'Kelola order dan dukungan', '/admin/operations', true, 'admin operations orders support'],
                ['admin-content', 'Konten dan dukungan', 'Kelola konten dan komunikasi', '/admin/content', true, 'admin content feedback broadcast'],
                ['admin-ai', 'Katalog AI', 'Kelola model dan provider', '/admin/ai', true, 'admin models providers catalog'],
            ]];
            $sections[] = ['system', 'Sistem', [
                ['admin-system', 'Aktivitas sistem', 'Tinjau aktivitas dan audit', '/admin/system', true, 'admin system audit analytics'],
                ['admin-settings', 'Pengaturan', 'Kelola pengaturan aplikasi', '/admin/settings', true, 'admin settings pricing'],
            ]];
        }

        $groups = [];
        foreach ($sections as [$id, $label, $destinations]) {
            $results = [];
            foreach ($destinations as [$key, $title, $description, $url, $allowed, $keywords]) {
                if ($allowed && ($needle === '' || str_contains(mb_strtolower($title.' '.$description.' '.$keywords), $needle))) {
                    $results[] = $this->result('destination', $key, $title, $description, $url);
                }
            }
            $this->addGroup($groups, $id, $label, $results);
        }

        return $groups;
    }

    private function models(User $user, array $access, string $pattern, string $needle, AiProxyService $catalog, WorkspaceMediaService $workspace): array
    {
        $categories = array_values(array_filter(['chat', 'image', 'video', 'audio', 'avatar', 'model3d'], fn (string $kind): bool => $access[$kind]));
        if ($access['image'] || $access['video'] || $access['audio']) {
            // Catalog operations such as vision, transcription or training; the workspace applies their exact permission.
            $categories[] = 'other';
        }
        if (! $access['active'] || $categories === []) {
            return [];
        }
        $models = AiModelProfile::query()
            ->with('provider')
            ->where('is_enabled', true)->where('is_available', true)
            ->whereHas('provider', fn (EloquentBuilder $provider) => $provider->where('is_enabled', true))
            ->whereIn('category', $categories);
        $this->match($models, ['model_id', 'display_name', 'description_id', 'description_en'], $pattern);
        // Eligibility can reject unsupported provider profiles, so scan a bounded candidate window.
        $candidates = $models
            ->orderByRaw('CASE WHEN LOWER(model_id) = ? THEN 0 WHEN LOWER(display_name) = ? THEN 1 ELSE 2 END', [$needle, $needle])
            ->orderBy('sort_order')->orderBy('id')->limit(60)
            ->get();
        $publicModels = collect($catalog->filterModelsForTiers($candidates->map(fn (AiModelProfile $model): array => [
            'id' => $model->model_id,
            'name' => $model->display_name ?: $model->model_id,
            'provider' => $model->provider_name,
            'category' => $model->category,
        ])->all()))->keyBy('id');
        $results = [];
        foreach ($candidates as $model) {
            $public = $publicModels->get($model->model_id);
            if (! $public) {
                continue;
            }
            if ($model->category === 'chat') {
                // Native Chat deliberately retains its own model selector.
                $results[] = $this->result('model', $model->model_id, $public['name'], 'Chat AI', '/chat');
            } else {
                // The same eligibility as the unified studio: published contract, reviewed price, entitlement.
                $kinds = $workspace->eligibleOutputKinds($user, $model);
                if ($kinds === []) {
                    continue;
                }
                // Avatar models open the avatar studio; a model spanning several output kinds opens unfiltered.
                $kind = match (true) {
                    $model->category === 'avatar' => 'avatar',
                    $kinds === [$model->category] => $model->category,
                    default => null,
                };
                $results[] = $this->result('model', $model->model_id, $public['name'],
                    $model->category === 'other' ? 'Media' : ucfirst($model->category),
                    StudioLink::to($kind, ['model' => $model->model_id]));
            }
            if (count($results) === self::GROUP_LIMIT) {
                break;
            }
        }

        return $results;
    }

    private function conversations(User $user, string $pattern): array
    {
        $history = DB::table('chat_history')->where('user_id', $user->id)->where('conversation_id', '<>', '')
            ->select('conversation_id')->selectRaw('MAX(created_at) as last_message')->groupBy('conversation_id');
        // Chat metadata owns renamed titles; its tombstone hides a deleted conversation even if a stray message survives.
        $rows = DB::query()->fromSub($history, 'history')
            ->leftJoin('chat_conversations as metadata', function (JoinClause $join) use ($user): void {
                $join->on('metadata.conversation_key', '=', 'history.conversation_id')->where('metadata.user_id', '=', $user->id);
            })
            ->whereNull('metadata.deleted_at')
            ->where(function (Builder $matching) use ($user, $pattern): void {
                $matching->where(function (Builder $title) use ($pattern): void {
                    $title->where('metadata.title_is_custom', true);
                    $this->match($title, ['metadata.title'], $pattern);
                })->orWhereExists(function (Builder $query) use ($user, $pattern): void {
                    $query->selectRaw('1')->from('chat_history as matching')
                        ->where('matching.user_id', $user->id)
                        ->whereColumn('matching.conversation_id', 'history.conversation_id');
                    $this->match($query, ['matching.content', 'matching.model'], $pattern);
                });
            })
            ->select('history.conversation_id', 'metadata.title as metadata_title', 'metadata.title_is_custom')
            ->selectSub(DB::table('chat_history as first_message')
                ->selectRaw('SUBSTR(first_message.content, 1, 160)')
                ->where('first_message.user_id', $user->id)
                ->whereColumn('first_message.conversation_id', 'history.conversation_id')
                ->where('first_message.role', 'user')->orderBy('first_message.created_at')->orderBy('first_message.id')->limit(1), 'first_message')
            ->orderByDesc('history.last_message')->orderBy('history.conversation_id')->limit(self::GROUP_LIMIT)->get();

        return $rows->map(function (object $row): array {
            $title = $row->title_is_custom ? (string) $row->metadata_title : (string) $row->first_message;

            return $this->result('conversation', $row->conversation_id, trim($title) !== '' ? $title : 'Percakapan tanpa judul', 'Riwayat chat',
                '/chat?'.http_build_query(['conversation' => $row->conversation_id], '', '&', PHP_QUERY_RFC3986));
        })->all();
    }

    private function media(User $user, string $table, string $kind, string $pattern): array
    {
        $query = DB::table($table)->where('user_id', $user->id);
        if ($table === 'video_jobs') {
            $query->where('mode', $kind === 'avatar' ? '=' : '!=', 'avatar');
        }
        $titleColumn = $kind === 'model3d' ? 'model_label' : 'prompt';
        $this->match($query, [$titleColumn, 'model', self::JOB_ID], $pattern);

        return $query->select('job_id', 'model', 'status')->selectRaw('SUBSTR('.$titleColumn.', 1, 160) as title')
            ->orderByDesc('created_at')->orderByDesc('id')->limit(self::GROUP_LIMIT)->get()
            ->map(fn (object $row): array => $this->result($kind, $row->job_id, $row->title ?: $row->model,
                $row->model.' · '.$row->status, StudioLink::to($kind, ['job' => $row->job_id])))->all();
    }

    private function tools(User $user, array $access, string $pattern): array
    {
        $kinds = array_values(array_filter(['download', 'convert'], fn (string $kind): bool => $access[$kind]));
        $query = DB::table('media_tool_jobs')->where('user_id', $user->id)->whereIn('kind', $kinds);
        $this->match($query, ['title', 'input_name', self::JOB_ID], $pattern);

        return $query->select('job_id', 'kind', 'title', 'input_name', 'format', 'status')
            ->orderByDesc('created_at')->orderByDesc('id')->limit(self::GROUP_LIMIT)->get()
            ->map(function (object $row): array {
                $path = $row->kind === 'download' ? '/downloads' : '/converter';
                $name = basename(str_replace('\\', '/', (string) $row->input_name));

                return $this->result($row->kind, $row->job_id, $row->title ?: $name ?: strtoupper($row->format),
                    strtoupper($row->format).' · '.$row->status,
                    $path.'?'.http_build_query(['job' => $row->job_id], '', '&', PHP_QUERY_RFC3986));
            })->all();
    }

    private function templates(string $pattern): array
    {
        $query = PromptTemplate::active();
        $this->match($query, ['title', 'prompt_text', 'category'], $pattern);

        return $query->select('id', 'title', 'category')->orderBy('sort_order')->orderBy('id')->limit(self::GROUP_LIMIT)->get()
            ->map(fn (PromptTemplate $template): array => $this->result('template', (string) $template->id,
                $template->title, $template->category, '/templates?template='.$template->id))->all();
    }

    private function match(Builder|EloquentBuilder $query, array $columns, string $pattern): void
    {
        $query->where(function ($match) use ($columns, $pattern): void {
            foreach ($columns as $column) {
                $match->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$pattern]);
            }
        });
    }

    private function result(string $type, string $id, string $title, string $description, string $url): array
    {
        return [
            'id' => $type.':'.$id,
            'type' => $type,
            'title' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', $title)), 0, 160),
            'description' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', $description)), 0, 180),
            'url' => $url,
        ];
    }

    private function addGroup(array &$groups, string $id, string $label, array $results): void
    {
        $remaining = self::TOTAL_LIMIT - array_sum(array_map(fn (array $group): int => count($group['results']), $groups));
        $results = array_slice($results, 0, min(self::GROUP_LIMIT, max(0, $remaining)));
        if ($results !== []) {
            $groups[] = ['id' => $id, 'label' => $label, 'results' => $results];
        }
    }
}
