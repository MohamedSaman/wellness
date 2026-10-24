<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Invoice numbers a till reserved while online, to number its own sales offline.
        Schema::create('invoice_number_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('first_number');
            $table->unsignedBigInteger('last_number');
            $table->timestamps();

            $table->index(['device_id', 'first_number', 'last_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_number_blocks');
    }
};
