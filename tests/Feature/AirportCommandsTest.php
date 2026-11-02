<?php

namespace Tests\Feature;

use App\Models\Airport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AirportCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_airport_gets_its_gates(): void
    {
        $this->artisan('airports:add', ['icao' => 'lirf', 'name' => 'Rome Fiumicino Airport', '--city' => 'Rome'])
            ->assertSuccessful();

        $airport = Airport::findByCode('LIRF');
        $this->assertSame('Rome Fiumicino Airport (LIRF)', $airport->label);
        $this->assertSame(20, $airport->gates()->count());

        $this->artisan('airports:add', ['icao' => 'LIRF', 'name' => 'Again'])->assertFailed();
        $this->artisan('airports:add', ['icao' => 'TOOLONG', 'name' => 'Bad'])->assertFailed();
    }

    public function test_restricting_a_gate_targets_the_chosen_airport(): void
    {
        $this->artisan('gates:restrict', ['gate' => 'A2', 'starts' => '2025-01-10', 'ends' => '2025-01-11', '--airport' => 'LROP'])
            ->assertSuccessful();

        $this->assertSame(1, Airport::findByCode('LROP')->gates()->where('code', 'A2')->first()->restrictions()->count());
        $this->assertSame(0, Airport::findByCode('EDDF')->gates()->where('code', 'A2')->first()->restrictions()->count());
    }
}
