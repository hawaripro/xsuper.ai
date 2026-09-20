<?php

return [
    // Base Library storage every non-admin account gets, in bytes (500 MB).
    'base_bytes' => (int) env('STORAGE_QUOTA_BASE_MB', 500) * 1024 * 1024,

    // Weekly purge removes non-admin Library media older than this many days.
    'retention_days' => (int) env('STORAGE_RETENTION_DAYS', 7),
];
