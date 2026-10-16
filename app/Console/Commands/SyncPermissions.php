<?php

namespace App\Console\Commands;

use App\Services\Authorization\PermissionCatalog;
use Illuminate\Console\Command;

class SyncPermissions extends Command
{
    protected $signature = 'permissions:sync';

    protected $description = 'Write the permission catalogue and built-in role templates to the database (safe to repeat)';

    public function handle(PermissionCatalog $catalog): int
    {
        $catalog->sync();
        $this->info('Permissions and role templates are up to date.');

        return self::SUCCESS;
    }
}
