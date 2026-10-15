<?php

namespace App\Models;

use App\Enums\BillingType;
use App\Models\Concerns\BelongsToAccount;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Landlord billing settings: electricity / water price per unit, garbage fee, etc.
 *
 * VERSIONED, NEVER EDITED: once a rate row exists, only effective_to may change.
 * To change a price, call ChargeRate::change(), which closes the current row the
 * day before the new one starts. Old bills therefore always match their rate.
 */
class ChargeRate extends Model
{
    use BelongsToAccount;

    /** Columns that define the price; frozen after creation. */
    private const FROZEN = ['property_id', 'unit_id', 'charge_code', 'billing_type', 'rate', 'effective_from'];

    protected $fillable = [
        'account_id', 'property_id', 'unit_id', 'charge_code', 'label', 'billing_type',
        'rate', 'unit_of_measure', 'effective_from', 'effective_to', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'billing_type' => BillingType::class,
            'rate' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (ChargeRate $rate) {
            if ($rate->isDirty(self::FROZEN)) {
                throw new LogicException('Charge rates are versioned: use ChargeRate::change() instead of editing.');
            }
        });
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function scopeEffectiveOn(Builder $query, CarbonInterface|string $date): Builder
    {
        $date = Carbon::parse($date)->toDateString();

        return $query->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }

    /**
     * The rate that applies to a unit for one charge on a date:
     * a unit-specific override wins over the property-wide rate.
     */
    public static function resolve(Unit $unit, string $chargeCode, CarbonInterface|string $date): ?self
    {
        return static::query()
            ->where('property_id', $unit->property_id)
            ->where('charge_code', $chargeCode)
            ->where(fn ($q) => $q->whereNull('unit_id')->orWhere('unit_id', $unit->id))
            ->effectiveOn($date)
            ->orderByRaw('unit_id IS NULL')   // override (non-null unit_id) first
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * Every rate that applies to a unit on a date, one per charge_code,
     * overrides taking precedence. Used by the billing job.
     */
    public static function allFor(Unit $unit, CarbonInterface|string $date): Collection
    {
        return static::query()
            ->where('property_id', $unit->property_id)
            ->where(fn ($q) => $q->whereNull('unit_id')->orWhere('unit_id', $unit->id))
            ->effectiveOn($date)
            ->orderByRaw('unit_id IS NULL')
            ->orderByDesc('effective_from')
            ->get()
            ->unique('charge_code')
            ->values();
    }

    /**
     * Set a new price from a date: closes the current version and opens a new one.
     * Pass $unit to change a single unit's override instead of the property-wide rate.
     */
    public static function change(
        Property $property,
        string $chargeCode,
        float|string $newRate,
        CarbonInterface|string $from,
        ?Unit $unit = null,
        array $attributes = [],
    ): self {
        $from = Carbon::parse($from)->startOfDay();

        return DB::transaction(function () use ($property, $chargeCode, $newRate, $from, $unit, $attributes) {
            $current = static::query()
                ->where('property_id', $property->id)
                ->where('unit_id', $unit?->id)
                ->where('charge_code', $chargeCode)
                ->whereNull('effective_to')
                ->lockForUpdate()
                ->first();

            if ($current && $current->effective_from->gte($from)) {
                throw new LogicException('New rate must start after the current rate\'s start date.');
            }

            $current?->update(['effective_to' => $from->copy()->subDay()]);

            return static::create(array_merge([
                'account_id' => $property->account_id,
                'property_id' => $property->id,
                'unit_id' => $unit?->id,
                'charge_code' => $chargeCode,
                'label' => $current?->label ?? ucfirst($chargeCode),
                'billing_type' => $current?->billing_type ?? BillingType::Fixed,
                'unit_of_measure' => $current?->unit_of_measure,
            ], $attributes, [
                'rate' => $newRate,
                'effective_from' => $from->toDateString(),
                'effective_to' => null,
            ]));
        });
    }

    public function isMetered(): bool
    {
        return $this->billing_type === BillingType::Metered;
    }
}
