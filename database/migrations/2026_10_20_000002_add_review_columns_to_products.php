<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Items created while receiving (docs/PHARMACY.md §4.9, as built): an item
 * missing from the catalog can be created from a goods received note or
 * purchase order line.
 *
 * - needs_review: created by someone who may receive goods but not manage
 *   products. It can be ordered and received, but not sold (search, the
 *   offline catalog and checkout leave it out) until an admin reviews it.
 * - created_while_receiving + created_on (morph): the GRN or order it was
 *   first entered on, set when that document is saved (a new document has
 *   no id while its line is being filled in).
 * - reviewed_at / reviewed_by: who cleared needs_review, and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('needs_review')->default(false)->after('is_active');
            $table->boolean('created_while_receiving')->default(false)->after('needs_review');
            $table->nullableMorphs('created_on');
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['shop_id', 'needs_review']);
        });
    }

    public function down(): void
    {
        // SQLite rebuilds the table to drop a foreign key column; other tables point at products.
        // Inside a transaction SQLite ignores foreign_keys = OFF, but honours deferred checks.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }

        Schema::withoutForeignKeyConstraints(fn () => Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'needs_review']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropMorphs('created_on');
            $table->dropColumn(['needs_review', 'created_while_receiving', 'reviewed_at']);
        }));
    }
};
