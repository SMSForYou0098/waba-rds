<?php

namespace App\Providers;

use App\Models\Chat\ChatbotFlowVersion;
use App\Policies\ChatbotFlowPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Laravel\Passport\Passport;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        ChatbotFlowVersion::class => ChatbotFlowPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
      Passport::tokensCan([
            'purchase-plan' => 'Access purchase plan functionality',
            // Add more scopes as needed
        ]);
    }
}
