<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_tool_settings', function (Blueprint $table): void {
            $table->id();
            // Netscape cookies.txt for authenticated YouTube downloads, stored
            // encrypted at rest; passed to yt-dlp per job so datacenter IPs pass
            // the "confirm you're not a bot" gate.
            $table->text('youtube_cookies')->nullable();
            $table->timestamp('youtube_cookies_updated_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_tool_settings');
    }
};
