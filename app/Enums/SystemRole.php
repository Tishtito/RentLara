<?php

namespace App\Enums;

/**
 * Built-in role templates, shared by every account (Spatie roles with account_id = NULL). Super admins
 * can change their permissions (not Owner's); landlords duplicate them into custom roles of their own.
 */
enum SystemRole: string
{
    case Owner = 'Owner';
    case Manager = 'Manager';
    case Caretaker = 'Caretaker';
    case Accountant = 'Accountant';

    /** Owner always has every permission and can't be edited (so an account can't lock itself out). */
    public function isLocked(): bool
    {
        return $this === self::Owner;
    }

    /** @return list<Permission> permissions a template starts with (only used when it is first created) */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),
            self::Manager => array_values(array_filter(Permission::cases(), fn (Permission $p) => $p !== Permission::RolesManage)),
            self::Caretaker => [
                Permission::DashboardView, Permission::PropertiesView, Permission::TenantsView,
                Permission::ReadingsView, Permission::ReadingsRecord, Permission::PaymentsView,
                Permission::PaymentsRecord, Permission::InvoicesView, Permission::RatesView,
            ],
            self::Accountant => [
                Permission::DashboardView, Permission::PropertiesView, Permission::PropertiesViewAll,
                Permission::TenantsView, Permission::PaymentsView, Permission::PaymentsRecord,
                Permission::InvoicesView, Permission::InvoicesManage, Permission::BillingView, Permission::BillingRun, Permission::RatesView,
            ],
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => 'Everything, including roles and the team. Every account keeps at least one.',
            self::Manager => 'Runs the account day to day; can\'t change roles.',
            self::Caretaker => 'Readings and payments for the properties they look after.',
            self::Accountant => 'Payments, bills and billing runs across all properties.',
        };
    }
}
