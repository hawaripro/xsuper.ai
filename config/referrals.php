<?php

return [
    'enabled' => (bool) env('REFERRAL_PROGRAM_ENABLED', true),
    'reward_days' => (int) env('REFERRAL_REWARD_DAYS', 3),
    'cookie_name' => env('REFERRAL_COOKIE_NAME', 'xsuper_referral'),
    'cookie_minutes' => (int) env('REFERRAL_COOKIE_MINUTES', 60 * 24 * 30),
];
