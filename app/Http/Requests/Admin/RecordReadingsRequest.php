<?php

namespace App\Http\Requests\Admin;

use App\Models\Meter;
use App\Rules\InCurrentAccount;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class RecordReadingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller checks the policy per meter
    }

    public function rules(): array
    {
        return [
            /**
             * The day the meters were read (default today).
             *
             * @example 2026-09-30
             */
            'reading_date' => ['nullable', 'date', 'before_or_equal:today'],
            'readings' => ['required', 'array', 'min:1', 'max:200'],
            'readings.*.meter_id' => ['required', 'integer', 'distinct', new InCurrentAccount(Meter::class)],
            /**
             * What the meter shows. Must not be below the previous reading.
             *
             * @example 180
             */
            'readings.*.reading' => ['required', 'decimal:0,2', 'min:0', 'max:9999999999'],
        ];
    }

    public function attributes(): array
    {
        return ['readings.*.reading' => 'reading', 'readings.*.meter_id' => 'meter'];
    }

    public function readingDate(): CarbonImmutable
    {
        return $this->filled('reading_date')
            ? CarbonImmutable::parse($this->input('reading_date'))
            : CarbonImmutable::now(config('app.timezone'))->startOfDay();
    }

    /** @return array<int, array{meter_id: int, reading: string}> */
    public function readings(): array
    {
        return array_map(fn (array $r) => [
            'meter_id' => (int) $r['meter_id'],
            'reading' => number_format((float) $r['reading'], 2, '.', ''),
        ], array_values($this->input('readings')));
    }
}
