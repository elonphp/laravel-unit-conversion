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
        Schema::create(config('unit-conversion.tables.units', 'cfg_units'), function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique()->comment('單位代碼: kg, g, twct, bag...');
            $table->string('type', 20)->index()->comment('度量類型: mass, length, volume, count');
            $table->decimal('value', 20, 10)->nullable()->comment('對基準單位的換算係數（計數單位可為 null）');
            $table->json('translations')->nullable()->comment('多語系名稱: {"en": "kilogram", "zh_Hant": "公斤"}');
            $table->boolean('is_standard')->default(false)->comment('是否為標準單位（可互換）');
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('unit-conversion.tables.units', 'cfg_units'));
    }
};
