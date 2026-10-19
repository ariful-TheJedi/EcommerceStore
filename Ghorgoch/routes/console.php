<?php

use App\Auth\Domain\Services\BootstrapAdmin;
use App\Catalog\Domain\Services\MediaService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;

Artisan::command('catalog:cleanup-media', function (MediaService $media): void {
    $this->info('Removed '.$media->cleanup().' unreferenced catalog files.');
})->purpose('Remove managed catalog files older than the grace period that have no database references');

Artisan::command('auth:bootstrap-admin', function (BootstrapAdmin $bootstrap): int {
    try {
        $bootstrap->create();
    } catch (ValidationException $exception) {
        $this->error('Admin setup failed. Check SUPER_ADMIN_NAME, SUPER_ADMIN_EMAIL and SUPER_ADMIN_PASSWORD.');
        foreach ($exception->errors() as $messages) {
            foreach ($messages as $message) {
                $this->error($message);
            }
        }

        return 1;
    }

    $this->info('Super admin created. Use the staff login endpoint.');

    return 0;
})->purpose('Create the initial admin from environment configuration without overwriting users');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
