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
         * Publicly visible, on a root named "private". That is deliberate and
         * it is written down: ADR-0011 and .ai/rules/storage.md. A Product's
         * image lands here and is served straight off disk, so anything else
         * put here is public from the moment it is written, with no error to
         * say so. A model that needs to keep something takes a disk of its own.
         *
         * No 'serve'. The framework's serve route demands a signature this
         * disk's images cannot carry — they are frozen into static pages that
         * outlive any signature — and it would answer /storage, which the
         * document-root link below already answers by reading the file.
         *
         * The 'url' is root-relative, not built from APP_URL. The admin's
         * upload preview fetches it, and a fetch is blocked outright when
         * APP_URL is http:// on an https:// site or names another host, so the
         * preview spins forever. Whatever needs an absolute address wraps it
         * in url(), which takes the host the visitor actually came in on.
         */
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'url' => '/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
         * Where Livewire stages an upload before anything decides to keep it,
         * including the files a merchant drags in and never saves. It exists
         * to be somewhere the `local` disk is not: no URL, no link, no
         * visibility. config/livewire.php points at it, and if that file goes
         * the staging directory returns to the public disk above.
         */
        'staging' => [
            'driver' => 'local',
            'root' => storage_path('app/staging'),
            'throw' => false,
            'report' => false,
        ],

        /*
         * There is no `public` disk. Laravel's ships rooted at
         * storage/app/public and addressed at /storage, and /storage is the
         * `local` disk's — so a file written to it would not 404, it would
         * resolve to whatever the `local` disk happens to have at the same
         * path, which is a Product's image. Silently serving the wrong
         * picture is worse than serving none, so the disk is gone and
         * `Storage::disk('public')` raises instead. Anything that wants a
         * public address takes the `local` disk.
         */
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

    /*
     * One link, pointed at the directory the files actually occupy. /storage
     * is the `local` disk's address, and this is what makes it one: without
     * it the webserver finds no file there, falls through to the framework,
     * and a Product image 404s on a page that is spending money on traffic.
     *
     * It is the only entry, and there is no second disk claiming /storage to
     * be linked anywhere else — see the note where `public` used to be.
     */
    'links' => [
        public_path('storage') => storage_path('app/private'),
    ],

];
