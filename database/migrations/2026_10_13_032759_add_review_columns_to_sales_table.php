<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Offline sales the server accepted as they happened, but the owner should look at
            // (price changed since the till's last catalog sync, renumbered invoice ...).
            $table->boolean('needs_review')->default(false)->after('synced_at');
            $table->json('review_reasons')->nullable()->after('needs_review');
            $table->foreignId('reviewed_by')->nullable()->after('review_reasons')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');

            $table->index(['shop_id', 'needs_review']);
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'needs_review']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['needs_review', 'review_reasons', 'reviewed_at']);
        });
    }
};
