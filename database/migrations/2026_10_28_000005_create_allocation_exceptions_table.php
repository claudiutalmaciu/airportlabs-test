<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allocation_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airport_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('flight_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('gate_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40);
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allocation_exceptions');
    }
};
