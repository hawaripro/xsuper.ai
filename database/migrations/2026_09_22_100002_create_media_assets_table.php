<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Controlled media asset registry. Every uploaded reference (and, over time, other
 * managed media) has an owner, validated size/mime/signature, private storage location,
 * and retention status. Backend checks ownership before issuing any URL or sending a
 * reference to a provider. Results keep their existing private stores; this table is the
 * owner-scoped registry that also backs cookie-independent signed provider delivery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('media_type', 16);           // image | video | audio
            $table->string('role', 32)->nullable();     // InputRole value when known
            $table->string('storage_disk', 32)->default('local');
            $table->string('storage_path', 512);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('mime', 128);
            $table->boolean('signature_ok')->default(false);
            $table->json('metadata')->nullable();
            $table->string('retention_status', 16)->default('active'); // active | expired | deleted
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['retention_status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
