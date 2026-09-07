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
    | App\Support\FileUploader, so moving every upload to another disk is this one
    | env value.
    |
    | `public` is the answer on this deployment: storage/app/public served through
    | the public/storage symlink, which `php artisan storage:link` creates and the
    | server now has. Uploads live outside the repository tree, so a `git pull`
    | deploy never touches them, and there is 50 GB of disk to use.
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

        /*
         * The private disk: driver documents, payment proofs, national-id photos.
         *
         * `serve` is deliberately **false**. Laravel's stock scaffolding ships it as
         * `true`, and because this disk declares no `url`, `FilesystemServiceProvider`
         * registered its route at the default `/storage/{path}` — the same path the
         * *public* disk's URLs use. That route is registered from `booted()`, so it won
         * over anything `routes/web.php` declares, and `ServeFile` refuses a private
         * disk without a signed URL: 403 in local, **404 in production**.
         *
         * The visible symptom was that every image any operator ever uploaded — dish
         * photos, restaurant logos, marketing banners, complaint attachments, avatars —
         * 404'd in both dashboards and the app, while the seeded `/catalog/...` art
         * loaded fine. It read exactly like a missing `storage:link`, and was not.
         *
         * Nothing wants this disk on the web: its files are reached through the
         * admin-gated endpoints in `PaymentController` and `DocumentController`.
         */
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
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
