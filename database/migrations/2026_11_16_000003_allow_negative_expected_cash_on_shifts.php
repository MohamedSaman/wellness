<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shift's expected cash can be below zero (docs/PHARMACY.md, branch 18):
 * a doctor paid, or cash paid out, from a drawer whose float was entered
 * as less than it held. MySQL refused to close such a shift, because
 * shifts.expected_cash was unsigned (SQLite does not enforce it). The column
 * becomes signed, like shifts.difference: no row is rewritten and every
 * value it held stays valid. The only change to an existing column in this
 * branch, made because the alternative (refusing a payment the drawer
 * "should not" afford) would stop cash that really left the drawer from
 * being recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->bigInteger('expected_cash')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Fails while a shift holds a negative expected cash: that is the point of up().
        Schema::table('shifts', function (Blueprint $table) {
            $table->unsignedBigInteger('expected_cash')->nullable()->change();
        });
    }
};
