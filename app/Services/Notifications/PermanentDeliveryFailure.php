<?php

namespace App\Services\Notifications;

/**
 * A send failure that retrying won't fix (number not on WhatsApp, template missing, bad token…).
 * NotificationSender records it as failed without asking the queue to retry.
 */
interface PermanentDeliveryFailure {}
