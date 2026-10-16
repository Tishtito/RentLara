<?php

namespace App\Listeners;

use App\Events\Commands\AccountProfileSubmitted;
use App\Services\Accounts\AccountSettingsService;

class HandleAccountProfileSubmitted
{
    public function __construct(private AccountSettingsService $service) {}

    public function handle(AccountProfileSubmitted $event): void
    {
        $this->service->updateProfile($event->data);
    }
}
