<?php

return [
    /*
     * Kill switch for NEW capability-driven media submissions. When true, the backend
     * rejects new submissions through the coordinator; jobs already accepted keep being
     * polled and finalized. Does not delete any data.
     */
    'kill_switch' => (bool) env('MEDIA_KILL_SWITCH', false),

    /*
     * Limited activation: when set, ONLY this user id may submit through the coordinator
     * (used to scope a production smoke test to a single test member). Everyone else keeps
     * the existing verified behavior. Null = no restriction.
     */
    'restricted_user_id' => env('MEDIA_COORDINATOR_USER_ID') !== null && env('MEDIA_COORDINATOR_USER_ID') !== ''
        ? (int) env('MEDIA_COORDINATOR_USER_ID')
        : null,
];
