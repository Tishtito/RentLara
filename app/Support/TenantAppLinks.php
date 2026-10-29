<?php

namespace App\Support;

/**
 * Where tenants get the app (config app.tenant_app), used by the invitation email and WhatsApp
 * message. Without store links it's the portal URL, which installs as a web app.
 */
class TenantAppLinks
{
    public static function android(): ?string
    {
        return config('app.tenant_app.android_url') ?: null;
    }

    public static function ios(): ?string
    {
        return config('app.tenant_app.ios_url') ?: null;
    }

    public static function portal(): string
    {
        return rtrim((string) config('app.portal_url'), '/');
    }

    /** One line: "Android: https://… · iPhone: https://…", or the portal URL. */
    public static function summary(): string
    {
        $links = array_filter([
            'Android' => self::android(),
            'iPhone' => self::ios(),
        ]);

        if ($links === []) {
            return self::portal();
        }

        return implode(' · ', array_map(fn (string $label, string $url) => "{$label}: {$url}", array_keys($links), $links));
    }
}
