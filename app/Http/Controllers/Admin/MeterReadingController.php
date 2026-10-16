<?php

namespace App\Http\Controllers\Admin;

use App\Events\Commands\MeterReadingCorrectionSubmitted;
use App\Events\Commands\MeterReadingsSubmitted;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RecordReadingsRequest;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Services\Meters\MeterReadingService;
use App\Services\Meters\ReadingTasks;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

#[Group('Meter readings', weight: 7)]
class MeterReadingController extends Controller
{
    /**
     * Readings to capture.
     *
     * One task per unit whose bill is due in the next `days` days and has meters: each meter with its
     * rate, the reading it will be billed from (`previous_reading`) and the new reading if already
     * captured (`current_reading`, null = still to do). A unit still missing a reading when its bill is
     * due is skipped by the billing run. Caretakers see the properties they look after.
     */
    #[QueryParameter('property_id', description: 'Only this property.', type: 'int', example: 1)]
    #[QueryParameter('days', description: 'Bills due within this many days (default 7, max 31), or `all` for every running lease with meters.', type: 'string', example: '7')]
    #[QueryParameter('search', description: 'Unit number or tenant name.', type: 'string', example: '7-7')]
    public function index(Request $request, ReadingTasks $tasks): JsonResponse
    {
        Gate::authorize('viewAny', MeterReading::class);

        $list = $tasks->due(
            $request->user(),
            $request->integer('property_id') ?: null,
            $request->input('days') === 'all' ? null : min(31, max(0, $request->integer('days', 7))),
            search: $request->string('search')->toString() ?: null,
        );

        return response()->json([
            'data' => $list,
            'meta' => ['total' => $list->count(), 'done' => $list->where('done', true)->count()],
        ]);
    }

    /**
     * Save readings.
     *
     * Readings taken on one day (default today), several meters at once; all are saved or none.
     * A reading can't be lower than the previous one. Saving the same meter again on the same day
     * corrects the reading, unless it is already on a bill.
     */
    public function store(RecordReadingsRequest $request): JsonResponse
    {
        $readings = $request->readings();
        foreach (Meter::query()->with('unit.property')->whereKey(array_column($readings, 'meter_id'))->get() as $meter) {
            Gate::authorize('create', [MeterReading::class, $meter]);
        }

        MeterReadingsSubmitted::dispatch($request->readingDate(), $readings, $request->user()->id);

        return response()->json(['message' => 'Readings saved.'], 202);
    }

    /**
     * A meter's reading history.
     *
     * Newest first. `invoice` is the bill that used the reading; `editable` readings (not on a bill) can
     * be corrected.
     */
    public function history(Meter $meter, MeterReadingService $readings): JsonResponse
    {
        Gate::authorize('view', [MeterReading::class, $meter->loadMissing('unit.property')]);

        return response()->json(['data' => $readings->history($meter)]);
    }

    /**
     * Correct a reading.
     *
     * For a mistyped reading that isn't on a bill yet. It must stay between the readings before and after it.
     */
    public function correct(Request $request, MeterReading $reading): JsonResponse
    {
        Gate::authorize('create', [MeterReading::class, $reading->meter()->with('unit.property')->firstOrFail()]);
        $request->validate([
            /** @example 182 */
            'reading' => ['required', 'decimal:0,2', 'min:0', 'max:9999999999'],
        ]);

        MeterReadingCorrectionSubmitted::dispatch($reading->id, (string) $request->input('reading'), $request->user()->id);

        return response()->json(['message' => 'Reading corrected.'], 202);
    }
}
