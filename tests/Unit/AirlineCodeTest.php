<?php

namespace Tests\Unit;

use App\Models\Flight;
use Tests\TestCase;

class AirlineCodeTest extends TestCase
{
    public function test_the_airline_code_is_the_callsign_prefix(): void
    {
        $this->assertSame('DLH', Flight::airlineCodeFrom('DLH400'));
        $this->assertSame('EZY', Flight::airlineCodeFrom('EZY12AB'));
    }

    public function test_callsigns_without_an_airline_prefix_have_no_code(): void
    {
        $this->assertNull(Flight::airlineCodeFrom(null));
        $this->assertNull(Flight::airlineCodeFrom('DABCD'));
        $this->assertNull(Flight::airlineCodeFrom('N123AB'));
        $this->assertNull(Flight::airlineCodeFrom('dlh400'));
    }

    public function test_known_airline_codes_have_a_commercial_name(): void
    {
        $this->assertSame('Lufthansa', Flight::airlineName('DLH'));
        $this->assertSame('TAROM', Flight::airlineName('ROT'));
        $this->assertNull(Flight::airlineName('XYZ'));
        $this->assertNull(Flight::airlineName(null));
    }
}
