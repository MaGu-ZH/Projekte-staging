<?php
// Copy outside public/ as config.php. Never commit real credentials.
return [
    'base_url' => 'https://dropkiste.app',
    'storage_dir' => __DIR__ . '/storage',
    'admin_password_hash' => '', // Generate with password_hash(); empty disables login.
    'max_file_bytes' => 100 * 1024 * 1024,
    'max_storage_bytes' => 2 * 1024 * 1024 * 1024,
    'max_retention_days' => 30, // May be reduced; application always enforces <= 30.
    'max_recipients' => 20,
    'tombstone_retention_days' => 30,
    'cleanup_interval_seconds' => 60,
    'min_admin_password_length' => 14,
    'session_seconds' => 1800,
    'max_user_storage_bytes' => 1024 * 1024 * 1024,
    'max_active_transfers_per_user' => 20,
    'delivery_timeout_seconds' => 20,
    'max_direct_recipients' => 5,
    // Real credentials belong ONLY in /etc/dropkiste/config.php on your server.
    'smtp' => ['enabled'=>false, 'host'=>'', 'port'=>587, 'security'=>'starttls',
        'username'=>'', 'password'=>'', 'from'=>''],
    'sms' => ['enabled'=>false, 'provider'=>'twilio', 'account_sid'=>'',
        'auth_token'=>'', 'from'=>'', 'messaging_service_sid'=>''],
    'max_api_request_bytes' => 16384,
    'attempts_per_window' => 10,
    'rate_window_seconds' => 900,
    'max_parallel_downloads' => 3,
    'download_lease_seconds' => 3600,
    'download_chunk_bytes' => 65536,
];
