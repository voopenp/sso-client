<?php

return [
    'url' => env('VOOPE_SSO_URL'),
    'client_id' => env('VOOPE_SSO_CLIENT_ID'),
    'client_secret' => env('VOOPE_SSO_CLIENT_SECRET'),
    'redirect' => env('VOOPE_SSO_REDIRECT'),
    'rota_callback' => env('VOOPE_SSO_ROTA_CALLBACK', 'sso.callback'),
    'revalidar_a_cada' => 900,
    'timeout' => 10,
];
