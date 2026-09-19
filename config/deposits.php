<?php

return [
    'idr_per_usd' => (int) env('DEPOSIT_IDR_PER_USD', 16000),
    'minimum_idr' => (int) env('DEPOSIT_MINIMUM_IDR', 10000),
    'maximum_idr' => (int) env('DEPOSIT_MAXIMUM_IDR', 10000000),
    'checkout_minutes' => (int) env('DEPOSIT_CHECKOUT_MINUTES', 60),
    'qris_image_url' => env('DEPOSIT_QRIS_IMAGE_URL', '/assets/payments/qris-xsuper.png'),
];
