<?php

return [
    'enabled' => (bool) env('MEDIA_TOOLS_ENABLED', true),
    'python' => env('MEDIA_PYTHON_PATH'),
    'ffmpeg' => env('MEDIA_FFMPEG_PATH'),
    'ffprobe' => env('MEDIA_FFPROBE_PATH'),
    // Node >=25.9 is required: its permission mode also denies network access.
    'node' => env('MEDIA_NODE_PATH'),
    'max_upload_bytes' => 128 * 1024 * 1024,
    'max_output_bytes' => 256 * 1024 * 1024,
    'max_duration_seconds' => 600,
    'max_dimension' => 4096,
    'max_pixels' => 4096 * 4096,
    'max_concurrent_jobs' => 2,
    'process_timeout' => 360,
    // Background removal (rembg). The model dir persists the u2net weights across jobs.
    'rembg_model_dir' => env('MEDIA_REMBG_MODEL_DIR'),
    'rembg_tokens' => (int) env('MEDIA_REMBG_TOKENS', 15),
    // Default per-result token cost applied to newly synced media models that
    // arrive unpriced, so a categorized image/video/audio model becomes usable
    // immediately. Admins refine the real price in Pricing -> Model & Harga.
    'default_generation_tokens' => [
        'image' => (int) env('MEDIA_DEFAULT_IMAGE_TOKENS', 10),
        'video' => (int) env('MEDIA_DEFAULT_VIDEO_TOKENS', 100),
        'audio' => (int) env('MEDIA_DEFAULT_AUDIO_TOKENS', 20),
    ],
];
