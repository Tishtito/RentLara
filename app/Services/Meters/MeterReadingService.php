<?php

namespace App\Services\Meters;

use App\Enums\InvoiceStatus;
use App\Events\MeterReadingRecorded;
use App\Models\InvoiceItem;
use App\Models\Lease;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Meter readings captured by the landlord or caretaker each cycle.
 * A meter only counts up; a replaced physical meter gets a new meters row instead.
 */
class MeterReadingService
{
    public function record(
        Meter $meter,
        CarbonInterface $date,
        string $reading,
        ?int $recordedBy = null,
        bool $isEstimated = false,
        ?string $photoPath = null,
    ): MeterReading {
        $this->guardOrder($meter, $date, $reading);

        $record = $meter->readings()->create([
            'account_id' => $meter->account_id,
            'reading_date' => $date->toDateString(),
            'reading' => $reading,
            'recorded_by' => $recordedBy,
            'is_estimated' => $isEstimated,
            'photo_path' => $photoPath,
        ]);

        MeterReadingRecorded::dispatch($record);

        return $record;
    }

    /**
     * Save a batch of readings taken on one day (the readings screen), all or nothing. A reading for the
     * same meter and day that hasn't been billed yet is corrected instead (typo fix); a billed one can't
     * change. Errors are keyed readings.N.reading to match the request.
     *
     * @param  array<int, array{meter: Meter, reading: string}>  $readings
     *
     * @throws ValidationException
     */
    public function recordMany(CarbonInterface $date, array $readings, ?int $recordedBy = null): void
    {
        DB::transaction(function () use ($date, $readings, $recordedBy) {
            foreach (array_values($readings) as $i => ['meter' => $meter, 'reading' => $value]) {
                try {
                    $existing = $meter->readings()->whereDate('reading_date', $date->toDateString())->first();

                    if (! $existing) {
                        $this->record($meter, $date, $value, $recordedBy);

                        continue;
                    }

                    $this->ensureNotBilled($existing);

                    $this->guardOrder($meter, $date, $value);
                    $existing->forceFill(['reading' => $value, 'recorded_by' => $recordedBy])->save();
                    MeterReadingRecorded::dispatch($existing);
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages(["readings.{$i}.reading" => $e->errors()['reading'] ?? [$e->getMessage()]]);
                }
            }
        });
    }

    /**
     * Fix a mistyped reading. Not when it's already on a bill (bills never change); it must stay between
     * the readings before and after it.
     *
     * @throws ValidationException
     */
    public function correct(int $readingId, string $value, ?int $correctedBy = null): MeterReading
    {
        return DB::transaction(function () use ($readingId, $value, $correctedBy) {
            $reading = MeterReading::query()->with('meter')->lockForUpdate()->findOrFail($readingId);
            $this->ensureNotBilled($reading);
            $this->guardOrder($reading->meter, $reading->reading_date, $value, $reading->id);

            $reading->forceFill(['reading' => $value, 'recorded_by' => $correctedBy ?? $reading->recorded_by])->save();
            MeterReadingRecorded::dispatch($reading);

            return $reading;
        });
    }

    /**
     * A meter's readings, newest first, with the bill each one is on (if any).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function history(Meter $meter, int $limit = 60): Collection
    {
        $readings = $meter->readings()->with('recordedBy:id,name')->orderByDesc('reading_date')->limit($limit)->get();
        $ids = $readings->modelKeys();
        $items = InvoiceItem::query()
            ->where(fn ($q) => $q->whereIn('current_reading_id', $ids)->orWhereIn('previous_reading_id', $ids))
            ->whereHas('invoice', fn ($q) => $q->where('status', '!=', InvoiceStatus::Void))
            ->with('invoice:id,invoice_number')
            ->get(['id', 'invoice_id', 'current_reading_id', 'previous_reading_id']);

        return $readings->map(function (MeterReading $r) use ($items) {
            $bill = $items->first(fn ($i) => $i->current_reading_id === $r->id)
                ?? $items->first(fn ($i) => $i->previous_reading_id === $r->id);

            return [
                'id' => $r->id,
                'reading_date' => $r->reading_date->toDateString(),
                'reading' => $r->reading,
                'is_estimated' => (bool) $r->is_estimated,
                'recorded_by' => $r->recordedBy?->name,
                /** The bill that used it (as its new reading, or as the reading usage counted from). */
                'invoice' => $bill ? ['id' => $bill->invoice->id, 'invoice_number' => $bill->invoice->invoice_number] : null,
                'editable' => $bill === null && ! $this->isBilled($r),
            ];
        });
    }

    /** @throws ValidationException the reading is on a bill */
    private function ensureNotBilled(MeterReading $reading): void
    {
        if ($this->isBilled($reading)) {
            throw ValidationException::withMessages(['reading' => 'This reading is already on a bill and can\'t be changed.']);
        }
    }

    private function isBilled(MeterReading $reading): bool
    {
        return InvoiceItem::query()->where('current_reading_id', $reading->id)->orWhere('previous_reading_id', $reading->id)->exists();
    }

    /**
     * The readings a lease's next bill uses for one meter: the one used up to now and the new one.
     * ONE rule for billing (InvoiceBuilder) and the readings screen, so they can't disagree.
     *
     *  - previous: the reading this lease was last billed on; on a first bill the latest reading on or
     *    before move-in (the move-in opening reading); otherwise the meter's initial reading.
     *  - current: the latest reading on or before $upTo, the billing day (usage is billed up to the
     *    billing day); only "ready" when it is newer than previous.
     *
     * @return array{previous: ?MeterReading, from: string, current: ?MeterReading, ready: bool}
     */
    public function pendingReading(Lease $lease, Meter $meter, CarbonInterface $upTo): array
    {
        $lastBilledId = InvoiceItem::query()
            ->where('meter_id', $meter->id)
            ->whereHas('invoice', fn ($q) => $q->where('lease_id', $lease->id)->where('status', '!=', InvoiceStatus::Void))
            ->latest('id')
            ->value('current_reading_id');

        $previous = $lastBilledId
            ? $meter->readings()->find($lastBilledId)
            // First bill: the opening reading at move-in, or for a tenant added while already living
            // there, the reading taken the day Rentflow started (onboarded_on).
            : $this->readingOnOrBefore($meter, $lease->onboarded_on ?? $lease->start_date);

        $current = $this->readingOnOrBefore($meter, CarbonImmutable::parse($upTo));
        $ready = $current !== null
            && $current->id !== $previous?->id
            && (! $previous || $current->reading_date->gt($previous->reading_date));

        return [
            'previous' => $previous,
            'from' => $previous?->reading ?? $meter->initial_reading,
            'current' => $ready ? $current : null,
            'ready' => $ready,
        ];
    }

    /** Latest reading on or before a date (the "current reading" on a bill). */
    public function readingOnOrBefore(Meter $meter, CarbonInterface $date): ?MeterReading
    {
        return $meter->readings()
            ->whereDate('reading_date', '<=', $date->toDateString())
            ->orderByDesc('reading_date')
            ->first();
    }

    /** Latest reading strictly before a date (the "previous reading" on a bill). */
    public function readingBefore(Meter $meter, CarbonInterface $date): ?MeterReading
    {
        return $meter->readings()
            ->whereDate('reading_date', '<', $date->toDateString())
            ->orderByDesc('reading_date')
            ->first();
    }

    /** A meter only counts up: a reading can't be below the one before it or above the one after it. */
    private function guardOrder(Meter $meter, CarbonInterface $date, string $reading, ?int $ignoreId = null): void
    {
        $previous = $this->readingBefore($meter, $date)?->reading ?? $meter->initial_reading;
        $next = $meter->readings()->whereDate('reading_date', '>', $date->toDateString())
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->orderBy('reading_date')->value('reading');

        if (Money::toCents($reading) < Money::toCents($previous)) {
            throw ValidationException::withMessages([
                'reading' => "Reading {$reading} is lower than the previous reading {$previous}.",
            ]);
        }

        if ($next !== null && Money::toCents($reading) > Money::toCents($next)) {
            throw ValidationException::withMessages([
                'reading' => "Reading {$reading} is higher than the later reading {$next}.",
            ]);
        }
    }
}
