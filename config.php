<?php
// config.php - simple config for AppVerse (file-based storage)
return (object)[
    // Admin username (don't change unless you know what you do)
    'admin_user' => 'admin',

    // Hashed password (default is "admin"). Replace quickly after install.
    // Use password_hash('your-new-password', PASSWORD_DEFAULT) to generate a new value.
    'admin_pass_hash' => password_hash('admin', PASSWORD_DEFAULT),

    // Where apps are stored (JSON file)
    'apps_file' => __DIR__ . '/data/apps.json',

    // Where uploaded APKs will be stored (folder must be writable)
    'apks_dir' => __DIR__ . '/apks',

    // Maximum upload size for APK (bytes) - adjust as needed (50MB default)
    'max_apk_size' => 50 * 1024 * 1024
];
