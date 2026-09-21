<?php

return [
    /*
     * Kill switch for NEW capability-driven media submissions. When true, the backend
     * rejects new submissions through the coordinator; jobs already accepted keep being
     * polled and finalized. Does not delete any data.
     */
    'kill_switch' => (bool) env('MEDIA_KILL_SWITCH', false),

    /*
     * Limited-activation gate for a bounded smoke test. When true, the coordinator path is
     * restricted to `restricted_user_id`; if that id is empty/invalid the path is fail-safe
     * CLOSED for everyone (it never opens for all). When false (normal), no restriction.
     */
    'coordinator_restricted' => (bool) env('MEDIA_COORDINATOR_RESTRICTED', false),

    /*
     * The single test member id allowed through the coordinator while restricted. Used only
     * with coordinator_restricted=true to scope a production smoke test. Null = none.
     */
    'restricted_user_id' => env('MEDIA_COORDINATOR_USER_ID') !== null && env('MEDIA_COORDINATOR_USER_ID') !== ''
        ? (int) env('MEDIA_COORDINATOR_USER_ID')
        : null,

    /*
     * Local-only development affordance. When true AND APP_ENV=local, the provider endpoint
     * guard permits a loopback (127.0.0.0/8, ::1, localhost) mock over http/https so a studio
     * can be exercised end-to-end against a local fake provider. Production never sets this and
     * is never `local`, so the strict public-HTTPS SSRF guard always applies there.
     */
    'allow_local_providers' => (bool) env('MEDIA_ALLOW_LOCAL_PROVIDERS', false),
];
