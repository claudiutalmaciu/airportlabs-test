<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flights', function (Blueprint $table) {
            $table->id();
            // The airport we tracked this flight for: its destination for arrivals, its origin for departures.
            $table->foreignId('airport_id')->constrained()->cascadeOnDelete();
            $table->enum('direction', ['arrival', 'departure']);
            $table->string('icao24', 6);
            $table->string('callsign', 8)->nullable();
            // ICAO airline designator from the callsign (DLH400 → DLH) and its name from config/airlines.php.
            // Airlines missing from that list keep just the code.
            $table->string('airline_code', 3)->nullable();
            $table->string('airline_name')->nullable();
            $table->string('departure_airport', 4)->nullable();
            $table->string('arrival_airport', 4)->nullable();
            $table->dateTime('first_seen');
            $table->dateTime('last_seen');
            $table->dateTime('observed_at');
            $table->enum('gate_status', ['pending', 'allocated', 'unallocated'])->default('pending');
            $table->timestamps();

            $table->unique(['airport_id', 'direction', 'icao24', 'first_seen']);
            $table->index(['airport_id', 'direction', 'gate_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flights');
    }
};
