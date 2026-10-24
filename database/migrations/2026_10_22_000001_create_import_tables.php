<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Excel / CSV import (docs/PHARMACY.md §10, branch 7).
 *
 * - import_runs: the import log. One row per uploaded file: who, when, the
 *   file (kept under storage/app/private/imports), its type (items,
 *   suppliers, opening stock, opening invoices), the column mapping used,
 *   whether existing rows are skipped or updated, its status (uploaded,
 *   previewed, queued, running, completed, failed), the preview's and the
 *   result's counts, progress, and the error report written by the preview.
 * - import_mappings: the column mapping a shop last saved per import type,
 *   offered again on the next file with the same headings.
 * - variant_unit_locations: selling a unit loose per location (client
 *   answers, 2026-10-20: syrup in ml at Doctor's Medicines, bottles only at
 *   General Pharmacy). A row overrides the unit's own is_sellable at that
 *   location; no row means the unit's own setting. Written by the import
 *   and the product form; read by checkout from branch 8.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();   // who uploaded and confirmed
            $table->string('type', 30);                                         // ImportType
            $table->string('status', 20)->default('uploaded');                  // ImportStatus
            $table->string('file_name');                                        // as uploaded
            $table->string('file_path');                                        // on the local disk
            $table->char('file_hash', 64);                                      // sha256, to spot the same file again
            $table->json('headings')->nullable();                               // the file's column headings
            $table->json('mapping')->nullable();                                // field key => column index
            $table->string('existing', 10)->default('skip');                    // ImportExisting: skip | update
            $table->unsignedInteger('total_rows')->default(0);
            $table->json('preview_counts')->nullable();
            $table->json('result_counts')->nullable();
            $table->string('error_report_path')->nullable();
            $table->text('message')->nullable();                                // why it failed
            $table->timestamp('previewed_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'created_at']);
            $table->index(['shop_id', 'type', 'file_hash']);
        });

        Schema::create('import_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->json('mapping');                                            // field key => heading text
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['shop_id', 'type']);
        });

        Schema::create('variant_unit_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->boolean('is_sellable');
            $table->timestamps();

            $table->unique(['variant_unit_id', 'location_id']);
            $table->index('location_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variant_unit_locations');
        Schema::dropIfExists('import_mappings');
        Schema::dropIfExists('import_runs');
    }
};
