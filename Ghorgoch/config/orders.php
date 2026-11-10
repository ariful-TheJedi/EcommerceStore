<?php

return [
    'currency' => 'BDT',
    'cart_days' => 30,
    'max_lines' => 50,
    'max_quantity' => 100,
    'review_minutes' => 15,
    'delivery_fee' => '120.00',
    'district_fees' => ['dhaka' => '60.00'],
    'coverage_districts' => [],
    'customer_cancellation_states' => ['pending', 'confirmed'],
    'transitions' => [
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['processing', 'cancelled'],
        'processing' => ['shipped', 'cancelled'],
        'shipped' => ['delivered', 'returned'],
        'delivered' => ['returned'],
        'returned' => [],
        'cancelled' => [],
    ],
];
