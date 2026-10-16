<?php

namespace App\Enums;

/**
 * Everything an admin login can be allowed to do inside a landlord account. Roles are made of these
 * (Spatie laravel-permission, per account). Add new ones here, then run `php artisan permissions:sync`.
 */
enum Permission: string
{
    case DashboardView = 'dashboard.view';

    case PropertiesView = 'properties.view';
    case PropertiesViewAll = 'properties.view_all';
    case PropertiesManage = 'properties.manage';

    case TenantsView = 'tenants.view';
    case TenantsManage = 'tenants.manage';
    case LeasesManage = 'leases.manage';

    case ReadingsView = 'readings.view';
    case ReadingsRecord = 'readings.record';

    case PaymentsView = 'payments.view';
    case PaymentsRecord = 'payments.record';
    case InvoicesView = 'invoices.view';
    case InvoicesManage = 'invoices.manage';
    case BillingView = 'billing.view';
    case BillingRun = 'billing.run';

    case RatesView = 'rates.view';
    case RatesManage = 'rates.manage';
    case SettingsBilling = 'settings.billing';
    case WhatsAppManage = 'whatsapp.manage';

    case TeamView = 'team.view';
    case TeamManage = 'team.manage';
    case RolesManage = 'roles.manage';

    public function label(): string
    {
        return match ($this) {
            self::DashboardView => 'See the dashboard',
            self::PropertiesView => 'See properties and units',
            self::PropertiesViewAll => 'See all properties (not only the ones they look after)',
            self::PropertiesManage => 'Add and edit properties, units and meters',
            self::TenantsView => 'See tenants',
            self::TenantsManage => 'Edit tenants and send invitations',
            self::LeasesManage => 'Move tenants in and out',
            self::ReadingsView => 'See meter readings',
            self::ReadingsRecord => 'Record meter readings',
            self::PaymentsView => 'See payments',
            self::PaymentsRecord => 'Record payments',
            self::InvoicesView => 'See bills',
            self::InvoicesManage => 'Resend and void bills',
            self::BillingView => 'See billing runs',
            self::BillingRun => 'Run billing',
            self::RatesView => 'See prices',
            self::RatesManage => 'Change prices',
            self::SettingsBilling => 'Change the billing cycle',
            self::WhatsAppManage => 'Manage the WhatsApp connection',
            self::TeamView => 'See the team',
            self::TeamManage => 'Add, edit and remove team members',
            self::RolesManage => 'Create and edit roles',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::DashboardView => 'Dashboard',
            self::PropertiesView, self::PropertiesViewAll, self::PropertiesManage => 'Properties',
            self::TenantsView, self::TenantsManage, self::LeasesManage => 'Tenants',
            self::ReadingsView, self::ReadingsRecord => 'Meter readings',
            self::PaymentsView, self::PaymentsRecord, self::InvoicesView, self::InvoicesManage, self::BillingView, self::BillingRun => 'Money',
            self::RatesView, self::RatesManage, self::SettingsBilling, self::WhatsAppManage => 'Settings',
            self::TeamView, self::TeamManage, self::RolesManage => 'Team',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
