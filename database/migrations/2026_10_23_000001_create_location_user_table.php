<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Users' allowed locations (docs/PHARMACY.md §4.4, client answers
 * 2026-10-20): a user with rows here may sell and use tills only at those
 * locations; a user with none (the owner, every retail user) may use every
 * location. Written by LocationService::setAllowedLocations().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['location_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_user');
    }
};
