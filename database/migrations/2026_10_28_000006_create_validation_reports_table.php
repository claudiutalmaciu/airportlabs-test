<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('validation_reports', function (Blueprint $table) {
            $table->id();
            $table->json('stats');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('validation_reports');
    }
};
