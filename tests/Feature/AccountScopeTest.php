<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\Unit;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AccountScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_queries_return_nothing_without_an_account_context(): void
    {
        Unit::factory()->count(2)->create();

        $this->assertSame(0, Unit::count());
        $this->assertSame(2, app(CurrentAccount::class)->withoutScope(fn () => Unit::count()));
    }

    public function test_each_landlord_only_sees_their_own_rows(): void
    {
        $mine = Unit::factory()->create();
        Unit::factory()->create();

        $ids = app(CurrentAccount::class)->actingAs($mine->account_id, fn () => Unit::pluck('id')->all());

        $this->assertSame([$mine->id], $ids);
    }

    public function test_saving_a_row_for_another_account_throws(): void
    {
        $mine = Property::factory()->create();
        $theirs = Property::factory()->create();

        $this->expectException(LogicException::class);

        app(CurrentAccount::class)->actingAs($mine->account_id, fn () => Unit::create([
            'account_id' => $theirs->account_id,
            'property_id' => $theirs->id,
            'unit_number' => '1-1',
        ]));
    }
}
