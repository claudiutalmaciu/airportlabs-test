<?php

use App\Support\GateCodes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airport_id')->constrained()->cascadeOnDelete();
            $table->string('code', 8);
            $table->string('terminal', 4);
            $table->timestamps();

            $table->unique(['airport_id', 'code']);
        });

        // Every airport gets its own set of gates (20 by default).
        $codes = GateCodes::generate((int) config('gates.count', 20));
        $now = now();

        foreach (DB::table('airports')->pluck('id') as $airportId) {
            DB::table('gates')->insert(array_map(fn (array $gate) => $gate + [
                'airport_id' => $airportId,
                'created_at' => $now,
                'updated_at' => $now,
            ], $codes));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gates');
    }
};
