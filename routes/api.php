<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Routes are split into domain-specific files under routes/api/.
| Each file manages its own use-imports and middleware.
|
*/

require __DIR__.'/api/auth.php';
require __DIR__.'/api/user.php';
require __DIR__.'/api/chat.php';
require __DIR__.'/api/campaign.php';
require __DIR__.'/api/messaging.php';
require __DIR__.'/api/meta.php';
require __DIR__.'/api/report.php';
require __DIR__.'/api/media.php';
require __DIR__.'/api/billing.php';
require __DIR__.'/api/settings.php';
require __DIR__.'/api/contact.php';
require __DIR__.'/api/template.php';
require __DIR__.'/api/webhook.php';
require __DIR__.'/api/dashboard.php';
require __DIR__.'/api/notification.php';
require __DIR__.'/api/utility.php';

// Utility: clear cache (dev/ops helper)
Route::get('/run-command', function () {
    Artisan::call('optimize:clear');
    return Artisan::output();
});
