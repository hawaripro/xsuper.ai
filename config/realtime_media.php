<?php

return [
    // A deployment may shorten, never extend, the reviewed 60-second session ceiling.
    'max_session_seconds' => (int) env('REALTIME_MEDIA_MAX_SESSION_SECONDS', 60),
    // Browser recordings of the received stream are ordinary owned video assets under the unchanged quota.
    'max_recording_bytes' => 104_857_600,
    'max_recordings_per_session' => 3,
];
