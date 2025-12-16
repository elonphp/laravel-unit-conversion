<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create(config('unit-conversion.tables.unit_conversions', 'cfg_unit_conversions'), function (Blueprint $table) {
            $table->id();

            /**
             * morphs('unitable') 會自動建立：
             * - unitable_type (string): 實體類型，如 "product" (透過 morphMap 縮短)
             * - unitable_id (unsignedBigInteger): 實體 ID
             * - 複合索引 (unitable_type, unitable_id)
             */
            $table->morphs('unitable');

            $table->string('from_unit_code', 10)->comment('來源單位代碼: bag, ctn...');
            $table->string('to_unit_code', 10)->comment('目標單位代碼: kg, twct...');
            $table->decimal('quantity', 13, 4)->comment('換算數量: 1 from_unit = ? to_unit');
            $table->boolean('is_derived')->default(false)->comment('是否為系統自動展開的換算');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // 允許儲存所有單位組合 (box→bag, box→kg, box→g, ...)
            $table->unique(['unitable_type', 'unitable_id', 'from_unit_code', 'to_unit_code'], 'unit_conv_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('unit-conversion.tables.unit_conversions', 'cfg_unit_conversions'));
    }
};
