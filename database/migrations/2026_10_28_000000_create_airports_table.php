<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('airports', function (Blueprint $table) {
            $table->id();
            $table->string('icao_code', 4)->unique();
            $table->string('name');
            $table->string('city')->nullable();
            // Airport reference point, used to centre the live aircraft map.
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->timestamps();
        });

        $airports = [
            ['EDDF', 'Frankfurt Airport', 'Frankfurt', 50.0333, 8.5706],
            ['EDDM', 'Munich Airport', 'Munich', 48.3538, 11.7861],
            ['EGLL', 'London Heathrow Airport', 'London', 51.47, -0.4543],
            ['LFPG', 'Paris Charles de Gaulle Airport', 'Paris', 49.0097, 2.5479],
            ['EHAM', 'Amsterdam Airport Schiphol', 'Amsterdam', 52.3105, 4.7683],
            ['LROP', 'Bucharest Henri Coandă International Airport', 'Bucharest', 44.5711, 26.085],
            ['LRCL', 'Avram Iancu Cluj International Airport', 'Cluj-Napoca', 46.7852, 23.6862],
            ['LTFM', 'Istanbul Airport', 'Istanbul', 41.2753, 28.7519],
            ['OMDB', 'Dubai International Airport', 'Dubai', 25.2532, 55.3657],
            ['LEMD', 'Adolfo Suárez Madrid–Barajas Airport', 'Madrid', 40.4983, -3.5676],
            ['LSZH', 'Zurich Airport', 'Zurich', 47.4582, 8.5555],
            ['LOWW', 'Vienna International Airport', 'Vienna', 48.1103, 16.5697],
            ['KJFK', 'John F. Kennedy International Airport', 'New York', 40.6413, -73.7781],
        ];

        $now = now();

        DB::table('airports')->insert(array_map(fn (array $airport) => [
            'icao_code' => $airport[0],
            'name' => $airport[1],
            'city' => $airport[2],
            'latitude' => $airport[3],
            'longitude' => $airport[4],
            'created_at' => $now,
            'updated_at' => $now,
        ], $airports));
    }

    public function down(): void
    {
        Schema::dropIfExists('airports');
    }
};
