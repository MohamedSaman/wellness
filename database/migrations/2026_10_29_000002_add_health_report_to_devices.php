<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a till last reported with its sync requests (System health,
 * docs/deploy/CLOUD.md): how many offline sales it still holds to upload
 * (pending or refused), its clock against the server's (seconds, positive =
 * ahead) and when it said so. Null until the till reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->unsignedInteger('pending_uploads')->nullable()->after('last_seen_at');
            $table->integer('clock_offset_seconds')->nullable()->after('pending_uploads');
            $table->timestamp('reported_at')->nullable()->after('clock_offset_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['pending_uploads', 'clock_offset_seconds', 'reported_at']);
        });
    }
};
