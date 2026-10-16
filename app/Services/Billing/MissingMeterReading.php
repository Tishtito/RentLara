<?php

namespace App\Services\Billing;

use RuntimeException;

/** A lease can't be billed yet: a metered charge has no meter or no new reading. */
class MissingMeterReading extends RuntimeException {}
