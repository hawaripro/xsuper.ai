<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton settings for the media tools. Currently holds the encrypted
 * YouTube cookies.txt used to authenticate downloads from a burner account.
 */
class MediaToolSetting extends Model
{
    public const SINGLETON_ID = 1;

    protected $fillable = ['youtube_cookies', 'youtube_cookies_updated_at', 'updated_by'];

    protected $hidden = ['youtube_cookies'];

    protected function casts(): array
    {
        return [
            'youtube_cookies' => 'encrypted',
            'youtube_cookies_updated_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => self::SINGLETON_ID]);
    }

    public function hasYoutubeCookies(): bool
    {
        return is_string($this->youtube_cookies) && trim($this->youtube_cookies) !== '';
    }
}
