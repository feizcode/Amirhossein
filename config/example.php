<?php
return [
    'environment' => 'production',
    'database' => ['dsn' => 'mysql:host=localhost;dbname=feizaiir_amir;charset=utf8mb4', 'user' => 'feizaiir_amir', 'password' => 'SET_DATABASE_PASSWORD'],
    'password_hash' => 'SET_FAMILY_PASSWORD_HASH',
    'app_key' => 'SET_RANDOM_64_HEX_CHARACTERS',
    'secure_cookies' => true,
    'session_days' => 7,
];
