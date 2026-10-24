<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adjustments and returns now have a fixed reason (a code shown as a label)
 * plus an optional note, instead of free text. Existing free text is kept:
 * it becomes the note and the reason becomes "other". Nothing is lost.
 *
 * Voids keep their own free-text reason on the sale; the copy on their stock
 * movements moves to the note like any other text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->string('reason_code', 30)->nullable()->after('reference_id');
        });

        Schema::table('sale_returns', function (Blueprint $table) {
            $table->string('reason_code', 30)->default('other')->after('return_no');
            $table->text('note')->nullable()->after('reason_code');
        });

        DB::table('stock_movements')->whereNotNull('reason')->orderBy('id')->chunkById(500, function ($movements): void {
            foreach ($movements as $movement) {
                $text = trim((string) $movement->reason);
                $note = trim((string) $movement->note);

                DB::table('stock_movements')->where('id', $movement->id)->update([
                    'reason_code' => in_array($movement->type, ['adjustment_in', 'adjustment_out', 'return'], true) ? 'other' : null,
                    'note' => match (true) {
                        $text === '' => $note === '' ? null : $note,
                        $note === '' || $note === $text => $text,
                        default => "{$text} — {$note}",
                    },
                ]);
            }
        });

        DB::table('sale_returns')->orderBy('id')->chunkById(500, function ($returns): void {
            foreach ($returns as $return) {
                $text = trim((string) $return->reason);

                DB::table('sale_returns')->where('id', $return->id)->update([
                    'reason_code' => 'other',
                    'note' => $text === '' ? null : $text,
                ]);
            }
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn('reason');
        });

        Schema::table('sale_returns', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->string('reason')->nullable()->after('reference_id');
        });

        Schema::table('sale_returns', function (Blueprint $table) {
            $table->string('reason')->default('')->after('return_no');
        });

        // Best effort: the code's label (or the note for "other") becomes the free text again.
        DB::table('stock_movements')->whereNotNull('reason_code')->update(['reason' => DB::raw('COALESCE(note, reason_code)')]);
        DB::table('sale_returns')->update(['reason' => DB::raw('COALESCE(note, reason_code)')]);

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn('reason_code');
        });

        Schema::table('sale_returns', function (Blueprint $table) {
            $table->dropColumn(['reason_code', 'note']);
        });
    }
};
