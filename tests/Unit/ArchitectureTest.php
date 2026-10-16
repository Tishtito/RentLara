<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the architecture in docs/ARCHITECTURE.md:
 *   - models are data only (no hooks, queries or writes; tenancy trait excepted)
 *   - controllers never write; their write actions dispatch an intent event
 */
class ArchitectureTest extends TestCase
{
    private const MODEL_FORBIDDEN = [
        'booted(' => 'model event hooks',
        'static::creating' => 'model event hooks',
        'static::saving' => 'model event hooks',
        'static::updating' => 'model event hooks',
        '::query(' => 'queries',
        'DB::' => 'queries',
        '->save(' => 'writes',
        '->update(' => 'writes',
        '::create(' => 'writes',
        '->increment(' => 'writes',
    ];

    /**
     * Controllers allowed to call a service synchronously instead of dispatching an intent event,
     * because the response must carry the result. Keep this list short and explain each entry.
     */
    private const SYNC_CONTROLLERS = [
        'AuthTokenController.php', // the issued token must be returned in the response
        'SessionController.php', // admin + portal cookie login: the session must exist before the response
        'TokenController.php', // tenant app tokens: the issued token must be returned in the response
        'GoogleLoginController.php', // admin + portal Google login: session / token must exist before the response
        'InvitationController.php', // accepting an invitation logs the tenant in: session / token in the response
        'PasswordController.php', // set-password logs the admin in: session / token in the response
    ];

    private const CONTROLLER_FORBIDDEN = ['DB::', '::create(', '->save(', '->update(', '->delete(', '->forceFill('];

    public function test_models_contain_no_domain_logic(): void
    {
        foreach (glob($this->path('app/Models/*.php')) as $file) {
            $source = file_get_contents($file);

            foreach (self::MODEL_FORBIDDEN as $needle => $what) {
                $this->assertStringNotContainsString($needle, $source, basename($file)." contains {$what} ({$needle}); move it to a service.");
            }
        }
    }

    public function test_controllers_dispatch_events_instead_of_writing(): void
    {
        foreach ($this->files('app/Http/Controllers') as $file) {
            $source = file_get_contents($file);

            foreach (self::CONTROLLER_FORBIDDEN as $needle) {
                $this->assertStringNotContainsString($needle, $source, basename($file)." writes directly ({$needle}); dispatch an intent event.");
            }

            if (in_array(basename($file), self::SYNC_CONTROLLERS, true)) {
                continue;
            }

            preg_match_all('/public function (store|update|destroy)\(.*?(?=\n    public function |\n}\s*$)/s', $source, $actions);

            foreach ($actions[0] as $i => $body) {
                $this->assertMatchesRegularExpression('/::dispatch\(|event\(/', $body, basename($file)."@{$actions[1][$i]} must dispatch an intent event.");
            }
        }
    }

    private function path(string $relative): string
    {
        return dirname(__DIR__, 2).'/'.$relative;
    }

    /** @return list<string> */
    private function files(string $dir): array
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->path($dir)));

        return array_values(array_filter(
            array_map(fn ($f) => $f->getPathname(), iterator_to_array($iterator)),
            fn ($f) => str_ends_with($f, '.php'),
        ));
    }
}
