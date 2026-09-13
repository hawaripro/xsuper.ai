<?php

return [
    // Initial independent USD values use a fixed 1 USD = IDR 16,000 reference.
    // Administrators can replace every value in the pricing settings without a rebuild.
    'default_usd' => [
        '1_day' => 0.31,
        '1_week' => 1.25,
        '1_month' => 3.44,
        '3_months' => 8.44,
        '6_months' => 18.69,
        '12_months' => 31.19,
    ],
];
