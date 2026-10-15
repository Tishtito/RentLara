<?php

namespace App\Providers;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantAuthToken;
use App\Models\User;
use App\Support\AccountlessUserProvider;
use App\Support\CurrentAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Fresh account context per request and per queued job.
        $this->app->scoped(CurrentAccount::class);
    }

    public function boot(): void
    {
        // Tenant guard provider that can load a tenant before the account context exists.
        Auth::provider('accountless-eloquent', fn ($app, array $config) => new AccountlessUserProvider(
            $app['hash'], $config['model'],
        ));

        // Short, stable names in notification_deliveries.notifiable_type.
        Relation::enforceMorphMap([
            'account' => Account::class,
            'invoice' => Invoice::class,
            'payment' => Payment::class,
            'tenant' => Tenant::class,
            'tenant_auth_token' => TenantAuthToken::class,
            'user' => User::class,
        ]);

        // Catch N+1 queries while developing.
        Model::preventLazyLoading(! $this->app->isProduction());
    }
}
