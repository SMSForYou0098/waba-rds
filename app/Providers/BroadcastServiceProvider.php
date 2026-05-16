<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Passport / Bearer: private channel auth must use the API stack (not web + session).
        Broadcast::routes([
            'middleware' => ['api', 'auth:api'],
        ]);

        require base_path('routes/channels.php');
    }
}
