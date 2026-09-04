<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Admin-panel upload disk
    |--------------------------------------------------------------------------
    |
    | GiftController's uploadAnimation/uploadThumbnail, its category-icon upload,
    | and LevelController's badge upload all read this rather than a hardcoded disk
    | name — local in dev, 'vultr' once real credentials exist below. Left at
    | 'public' means "not configured yet"; nothing else needs to change to switch.
    */
    'uploads_disk' => env('UPLOADS_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // S3-compatible Object Storage. Same driver as 's3' above, pointed at
        // Vultr's endpoint instead — this is what UPLOADS_DISK=vultr switches to.
        'vultr' => [
            'driver' => 's3',
            'key' => env('VULTR_ACCESS_KEY_ID'),
            'secret' => env('VULTR_SECRET_ACCESS_KEY'),
            'region' => env('VULTR_DEFAULT_REGION'),
            'bucket' => env('VULTR_BUCKET'),
            'endpoint' => env('VULTR_ENDPOINT'),
            'url' => env('VULTR_URL'),
            'use_path_style_endpoint' => env('VULTR_USE_PATH_STYLE_ENDPOINT', true),
            // Disk-level default; ImageUploadService also passes 'public' explicitly
            // per write, the same belt-and-suspenders mehfil's own code does — Vultr
            // Object Storage denies reads on anything uploaded without this.
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
