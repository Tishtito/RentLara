<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\AuthTokenController;
use App\Http\Controllers\Admin\BillingRunController;
use App\Http\Controllers\Admin\ChargeRateController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\GoogleLoginController as AdminGoogleLoginController;
use App\Http\Controllers\Admin\InvoiceController;
use App\Http\Controllers\Admin\LeaseController;
use App\Http\Controllers\Admin\MeterController;
use App\Http\Controllers\Admin\MeterReadingController;
use App\Http\Controllers\Admin\PasswordController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\PropertyController;
use App\Http\Controllers\Admin\RegistrationController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SessionController as AdminSessionController;
use App\Http\Controllers\Admin\SetupController;
use App\Http\Controllers\Admin\TeamController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\Admin\UnitController;
use App\Http\Controllers\Admin\WhatsAppConnectionController;
use App\Http\Controllers\Platform\AccountController as PlatformAccountController;
use App\Http\Controllers\Platform\AdminController as PlatformAdminController;
use App\Http\Controllers\Platform\AuditLogController;
use App\Http\Controllers\Platform\BillingRunController as PlatformBillingRunController;
use App\Http\Controllers\Platform\DeliveryController as PlatformDeliveryController;
use App\Http\Controllers\Platform\OverviewController as PlatformOverviewController;
use App\Http\Controllers\Platform\RoleTemplateController;
use App\Http\Controllers\Portal\GoogleLoginController as PortalGoogleLoginController;
use App\Http\Controllers\Portal\HomeController as PortalHomeController;
use App\Http\Controllers\Portal\InvitationController;
use App\Http\Controllers\Portal\InvoiceController as PortalInvoiceController;
use App\Http\Controllers\Portal\PaymentController as PortalPaymentController;
use App\Http\Controllers\Portal\SessionController as PortalSessionController;
use App\Http\Controllers\Portal\TokenController as PortalTokenController;
use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

// Admin API tokens (API clients and the docs at /docs).
Route::post('admin/auth/token', [AuthTokenController::class, 'store'])->middleware('throttle:6,1');
Route::delete('admin/auth/token', [AuthTokenController::class, 'destroy'])->middleware(['auth:sanctum', 'actor:user']);

// Cookie sessions for the React portals (GET /sanctum/csrf-cookie first).
// Admin passwords from an emailed link (team invitations, forgot password).
Route::post('admin/auth/forgot-password', [PasswordController::class, 'forgot'])->middleware('throttle:3,1');
Route::post('admin/auth/set-password', [PasswordController::class, 'set'])->middleware('throttle:6,1');

// Landlord self sign-up (admin portal only; tenants are invited by their landlord).
Route::post('admin/auth/register', [RegistrationController::class, 'store'])->middleware('throttle:3,1');
Route::post('admin/auth/login', [AdminSessionController::class, 'store'])->middleware('throttle:6,1');
Route::post('admin/auth/logout', [AdminSessionController::class, 'destroy'])->middleware(['auth:sanctum', 'actor:user']);
Route::post('portal/auth/login', [PortalSessionController::class, 'store'])->middleware('throttle:6,1');
Route::post('portal/auth/logout', [PortalSessionController::class, 'destroy'])->middleware('auth:tenant');

// "Continue with Google" (existing logins only): session for the browser, token for the apps.
Route::middleware('throttle:6,1')->group(function () {
    Route::post('admin/auth/google', [AdminGoogleLoginController::class, 'store']);
    Route::post('admin/auth/google/token', [AdminGoogleLoginController::class, 'token']);
    Route::post('portal/auth/google', [PortalGoogleLoginController::class, 'store']);
    Route::post('portal/auth/google/token', [PortalGoogleLoginController::class, 'token']);
});

// Invitation link from the email: who it's for, then set a password (logs in).
Route::get('portal/invitations/{token}', [InvitationController::class, 'show'])->middleware('throttle:30,1');
Route::post('portal/invitations/{token}', [InvitationController::class, 'store'])->middleware('throttle:6,1');

// Tenant API tokens for the Android/iOS apps (cross-site cookies don't work in their web views).
Route::post('portal/auth/token', [PortalTokenController::class, 'store'])->middleware('throttle:6,1');
Route::delete('portal/auth/token', [PortalTokenController::class, 'destroy'])->middleware(['auth:sanctum', 'actor:tenant']);

// Admin portal: super admins, landlords and caretakers (users table).
Route::middleware(['auth:sanctum', 'actor:user', 'account'])->prefix('admin')->group(function () {
    Route::get('/user', [AuthTokenController::class, 'show']);

    Route::get('dashboard', DashboardController::class);
    Route::get('caretakers', [PropertyController::class, 'caretakers']);

    Route::get('permissions', [RoleController::class, 'permissions']);
    Route::get('roles', [RoleController::class, 'index']);
    Route::post('roles', [RoleController::class, 'store']);
    Route::put('roles/{role}', [RoleController::class, 'update'])->whereNumber('role');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->whereNumber('role');
    Route::get('team', [TeamController::class, 'index']);
    Route::post('team', [TeamController::class, 'store']);
    Route::put('team/{user}', [TeamController::class, 'update'])->whereNumber('user');
    Route::patch('team/{user}/status', [TeamController::class, 'status'])->whereNumber('user');
    Route::post('team/{user}/invitation', [TeamController::class, 'invite'])->whereNumber('user');
    Route::delete('team/{user}', [TeamController::class, 'destroy'])->whereNumber('user');
    Route::apiResource('properties', PropertyController::class)->only(['index', 'show', 'store', 'update']);
    Route::get('properties/{property}/units', [UnitController::class, 'index']);
    Route::post('properties/{property}/units', [UnitController::class, 'store']);
    Route::put('units/{unit}', [UnitController::class, 'update']);
    Route::post('units/{unit}/meters', [MeterController::class, 'store']);
    Route::get('properties/{property}/meter-setup', [MeterController::class, 'setup']);
    Route::post('properties/{property}/meters', [MeterController::class, 'fitMany']);
    Route::put('meters/{meter}', [MeterController::class, 'update']);

    Route::get('properties/{property}/charge-rates', [ChargeRateController::class, 'index']);
    Route::post('properties/{property}/charge-rates', [ChargeRateController::class, 'store']);
    Route::get('account', [AccountController::class, 'show']);
    Route::put('account/billing', [AccountController::class, 'updateBilling']);

    Route::get('setup', SetupController::class);
    Route::get('readings', [MeterReadingController::class, 'index']);
    Route::post('readings', [MeterReadingController::class, 'store']);
    Route::get('meters/{meter}/readings', [MeterReadingController::class, 'history']);
    Route::put('meter-readings/{reading}', [MeterReadingController::class, 'correct']);

    Route::post('units/{unit}/move-in', [LeaseController::class, 'moveIn']);
    Route::post('leases/{lease}/end', [LeaseController::class, 'end']);
    Route::apiResource('tenants', TenantController::class)->only(['index', 'show', 'update']);
    Route::post('tenants/{tenant}/invitation', [TenantController::class, 'invite']);

    Route::apiResource('payments', PaymentController::class)->only(['index', 'store']);
    Route::apiResource('invoices', InvoiceController::class)->only(['index', 'show']);
    Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf']);
    Route::post('invoices/{invoice}/resend', [InvoiceController::class, 'resend']);
    Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void']);
    Route::apiResource('billing-runs', BillingRunController::class)->only(['index', 'store']);

    Route::get('whatsapp', [WhatsAppConnectionController::class, 'show']);
    Route::put('whatsapp', [WhatsAppConnectionController::class, 'update']);
    Route::delete('whatsapp', [WhatsAppConnectionController::class, 'destroy']);
});

// Platform (super admins): every landlord account, billing, messaging, platform admins, activity log.
Route::middleware(['auth:sanctum', 'actor:user', 'super'])->prefix('platform')->group(function () {
    Route::get('overview', PlatformOverviewController::class);

    Route::get('accounts', [PlatformAccountController::class, 'index']);
    Route::post('accounts', [PlatformAccountController::class, 'store']);
    Route::get('accounts/{account}', [PlatformAccountController::class, 'show'])->whereNumber('account');
    Route::put('accounts/{account}', [PlatformAccountController::class, 'update'])->whereNumber('account');
    Route::patch('accounts/{account}/status', [PlatformAccountController::class, 'status'])->whereNumber('account');
    Route::post('accounts/{account}/billing-runs', [PlatformAccountController::class, 'bill'])->whereNumber('account');

    Route::get('billing-runs', [PlatformBillingRunController::class, 'index']);
    Route::get('billing-runs/{run}', [PlatformBillingRunController::class, 'show'])->whereNumber('run');

    Route::get('deliveries', [PlatformDeliveryController::class, 'index']);
    Route::get('deliveries/stats', [PlatformDeliveryController::class, 'stats']);
    Route::post('deliveries/retry-failed', [PlatformDeliveryController::class, 'retryFailed']);
    Route::post('deliveries/{delivery}/retry', [PlatformDeliveryController::class, 'retry'])->whereNumber('delivery');
    Route::get('whatsapp-connections', [PlatformDeliveryController::class, 'whatsapp']);

    Route::get('role-templates', [RoleTemplateController::class, 'index']);
    Route::put('role-templates/{role}', [RoleTemplateController::class, 'update'])->whereNumber('role');

    Route::get('admins', [PlatformAdminController::class, 'index']);
    Route::post('admins', [PlatformAdminController::class, 'store']);
    Route::patch('admins/{user}/status', [PlatformAdminController::class, 'status'])->whereNumber('user');
    Route::post('admins/{user}/invitation', [PlatformAdminController::class, 'invite'])->whereNumber('user');
    Route::delete('admins/{user}', [PlatformAdminController::class, 'destroy'])->whereNumber('user');

    Route::get('audit-log', [AuditLogController::class, 'index']);
    Route::get('audit-log/actions', [AuditLogController::class, 'actions']);
});

// Tenant portal: renters (tenants table). Cookie session (web) or API token (apps).
Route::middleware(['auth:tenant,sanctum', 'actor:tenant', 'account'])->prefix('portal')->group(function () {
    Route::get('/user', [PortalSessionController::class, 'show']);

    Route::get('home', PortalHomeController::class);
    Route::get('invoices', [PortalInvoiceController::class, 'index']);
    Route::get('invoices/{invoice}', [PortalInvoiceController::class, 'show'])->whereNumber('invoice');
    Route::get('invoices/{invoice}/pdf', [PortalInvoiceController::class, 'pdf'])->whereNumber('invoice');
    Route::get('payments', [PortalPaymentController::class, 'index']);
});

// Meta WhatsApp webhooks: one URL per landlord, authenticated by their verify token / app secret.
Route::middleware('throttle:120,1')->group(function () {
    Route::get('webhooks/whatsapp/{account:slug}', [WhatsAppWebhookController::class, 'verify'])->name('webhooks.whatsapp');
    Route::post('webhooks/whatsapp/{account:slug}', [WhatsAppWebhookController::class, 'store']);
});
