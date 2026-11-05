<?php

use App\Models\Device;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's approval of a till for offline sales (docs/deploy/TILLS.md):
 * on it, the till prepares itself in the background. Off for every till,
 * except one that already holds a sync token, which the owner registered
 * by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->boolean('offline_allowed')->default(false)->after('is_active');
        });

        $this->backfill();
    }

    public function backfill(): void
    {
        DB::table('devices')
            ->whereIn('id', DB::table('personal_access_tokens')
                ->where('tokenable_type', (new Device)->getMorphClass())
                ->select('tokenable_id'))
            ->update(['offline_allowed' => true]);
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('offline_allowed');
        });
    }
};
