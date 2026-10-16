<?php

namespace App\Providers;

use App\Listeners\RecordAuditEntry;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use App\Models\TenantAuthToken;
use App\Models\User;
use App\Support\AccountlessUserProvider;
use App\Support\CurrentAccount;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Fresh account context per request and per queued job.
        $this->app->scoped(CurrentAccount::class);

        // Scalar at /docs is the docs UI; Scramble only serves the OpenAPI JSON (see boot()).
        Scramble::ignoreDefaultRoutes();
    }

    public function boot(): void
    {
        // Tenant guard provider that can load a tenant before the account context exists.
        Auth::provider('accountless-eloquent', fn ($app, array $config) => new AccountlessUserProvider(
            $app['hash'], $config['model'],
        ));

        // Short, stable names in notification_deliveries.notifiable_type.
        // Tenant tokens must load their owner without an account context (see the model).
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Relation::enforceMorphMap([
            'account' => Account::class,
            'invoice' => Invoice::class,
            'payment' => Payment::class,
            'tenant' => Tenant::class,
            'tenant_auth_token' => TenantAuthToken::class,
            'user' => User::class,
        ]);

        // Activity log: every accepted intent event (runs after the event's own listener).
        Event::listen('App\\Events\\Commands\\*', [RecordAuditEntry::class, 'handle']);

        // Super admins pass every policy check.
        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);

        $this->configureApiDocs();

        // Catch N+1 queries while developing.
        Model::preventLazyLoading(! $this->app->isProduction());
    }

    /**
     * API reference: Scramble builds /docs/api.json from the code, Scalar renders it at /docs.
     * Both are open in local; elsewhere only when API_DOCS_ENABLED (default: staging/testing).
     */
    private function configureApiDocs(): void
    {
        Scramble::registerJsonSpecificationRoute('docs/api.json');

        Gate::define('viewApiDocs', fn (?User $user = null) => (bool) config('app.api_docs'));
        Gate::define('viewScalar', fn (?User $user = null) => (bool) config('app.api_docs'));

        Scramble::configure()->withOperationTransformers(function (Operation $operation, RouteInfo $route) {
            if (! in_array('account', $route->route->gatherMiddleware(), true)) {
                return;
            }

            $operation->addParameters([
                (new Parameter('X-Account-Id', 'header'))
                    ->setSchema(Schema::fromType(new IntegerType))
                    ->description('Super admins only: act inside this landlord account. Ignored for landlords and caretakers.'),
            ]);
        });
    }
}
