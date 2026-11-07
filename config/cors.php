<?php

/**
 * CORS — فقط مبدأ فرانت‌اند (Vite) مجاز است.
 * توکن ورود در هدر Authorization فرستاده می‌شود، پس کوکی اشتراکی لازم نیست؛
 * با این حال credentials برای توسعه محلی باز گذاشته شده است.
 */
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_filter([
        env('FRONTEND_URL', 'http://localhost:5173'),
        'http://localhost:5173',
        'http://127.0.0.1:5173',
    ]),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Content-Type', 'Authorization', 'Accept', 'X-Requested-With'],
    'exposed_headers' => [],
    'max_age' => 3600,
    'supports_credentials' => true,
];
