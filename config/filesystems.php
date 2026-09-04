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
    | Public upload disk
    |--------------------------------------------------------------------------
    |
    | Where product photos, restaurant logos, banners and avatars go. Read by
    | App\Support\FileUploader, so switching the whole application from local
    | storage to Cloudflare R2 is this one env value.
    |
    */

    'public_uploads' => env('PUBLIC_UPLOAD_DISK', 'public'),

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

        /*
         * Cloudflare R2, spoken to over the S3 API.
         *
         * This exists because the `public` disk cannot work on this deployment: it
         * writes to storage/app/public and serves through a public/storage symlink,
         * and on Hostinger shared hosting that link is not there — every uploaded
         * image answered 200 on upload and 404 on view. R2 has no symlink, no write
         * permission to get wrong, and a CDN in front of it.
         *
         * Two endpoints, and mixing them up is the usual first failure:
         *   R2_ENDPOINT — the S3 API endpoint, https://<account_id>.r2.cloudflarestorage.com
         *                 (private; this is what the SDK writes to)
         *   R2_URL      — the PUBLIC read URL: the bucket's r2.dev domain or a custom
         *                 domain. Without it every stored URL is unreachable.
         *
         * R2 ignores regions; `auto` is the required value, not a placeholder.
         */
        'r2' => [
            'driver' => 's3',
            'key' => env('R2_ACCESS_KEY_ID'),
            'secret' => env('R2_SECRET_ACCESS_KEY'),
            'region' => env('R2_DEFAULT_REGION', 'auto'),
            'bucket' => env('R2_BUCKET'),
            'url' => env('R2_URL'),
            'endpoint' => env('R2_ENDPOINT'),
            'use_path_style_endpoint' => env('R2_USE_PATH_STYLE_ENDPOINT', true),
            'visibility' => 'public',
            // Unlike the disks above, a failed write here must be loud. A silent
            // false is what let a broken upload look like a successful one.
            'throw' => true,
            'report' => true,
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
