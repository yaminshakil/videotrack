<?php

return [
    // Admin panel credentials. There is deliberately no fallback for the
    // password: an unset ADMIN_PASSWORD locks the admin panel (and only the
    // admin panel) rather than handing it to whoever guessed "admin123".
    'admin_username' => env('ADMIN_USERNAME', 'admin'),
    'admin_password' => env('ADMIN_PASSWORD', ''),
];
