<?php

namespace Elonphp\UnitConversion\Traits;

use Elonphp\UnitConversion\Models\UnitConversion;
use Elonphp\UnitConversion\Services\UnitConversionExpander;
use Elonphp\UnitConversion\Services\UnitConverter;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

trait HasUnitConversion
{
    /**
     * Get all unit conversions for this entity.
     */
    public function unitConversions(): MorphMany
    {
        return $this->morphMany(UnitConversion::class, 'unitable');
    }

    /**
     * Get active unit conversions.
     */
    public function activeUnitConversions(): MorphMany
    {
        return $this->unitConversions()->active();
    }

    /**
     * Get a specific unit conversion by from_unit_code.
     */
    public function getUnitConversion(string $fromUnitCode): ?UnitConversion
    {
        return $this->unitConversions()
            ->where('from_unit_code', $fromUnitCode)
            ->active()
            ->first();
    }

    /**
     * Add or update a unit conversion.
     */
    public function setUnitConversion(
        string $fromUnitCode,
        string $toUnitCode,
        float $quantity
    ): UnitConversion {
        return $this->unitConversions()->updateOrCreate(
            ['from_unit_code' => $fromUnitCode],
            [
                'to_unit_code' => $toUnitCode,
                'quantity' => $quantity,
                'is_active' => true,
            ]
        );
    }

    /**
     * Remove a unit conversion.
     */
    public function removeUnitConversion(string $fromUnitCode): bool
    {
        return $this->unitConversions()
            ->where('from_unit_code', $fromUnitCode)
            ->delete() > 0;
    }

    /**
     * Convert quantity from one unit to another.
     */
    public function convertUnit(float $quantity, string $fromUnit, string $toUnit): float
    {
        return UnitConverter::make()
            ->for($this)
            ->qty($quantity)
            ->from($fromUnit)
            ->to($toUnit)
            ->convert();
    }

    /**
     * Get all available unit codes for this entity.
     */
    public function getAvailableUnitCodes(): Collection
    {
        return $this->activeUnitConversions()
            ->pluck('from_unit_code')
            ->unique();
    }

    /**
     * Sync unit conversions from array (without auto-expansion).
     *
     * @param array $conversions [['from_unit_code' => 'bag', 'to_unit_code' => 'kg', 'quantity' => 1.8], ...]
     * @deprecated Use setUnitConversionsWithExpansion() for auto-expansion
     */
    public function syncUnitConversions(array $conversions): void
    {
        // Get current from_unit_codes
        $currentCodes = $this->unitConversions()->pluck('from_unit_code')->toArray();
        $newCodes = array_column($conversions, 'from_unit_code');

        // Delete removed conversions
        $toDelete = array_diff($currentCodes, $newCodes);
        if (!empty($toDelete)) {
            $this->unitConversions()->whereIn('from_unit_code', $toDelete)->delete();
        }

        // Upsert conversions
        foreach ($conversions as $conversion) {
            $this->setUnitConversion(
                $conversion['from_unit_code'],
                $conversion['to_unit_code'],
                $conversion['quantity']
            );
        }
    }

    /**
     * 設定單位換算並自動展開所有組合
     *
     * 使用者只需設定基本換算關係，系統自動展開所有組合。
     * 例如：
     * - 設定: [['from' => 'ctn', 'to' => 'bag', 'qty' => 6], ['from' => 'bag', 'to' => 'twct', 'qty' => 1.8]]
     * - 表示: 1箱 = 6包, 1包 = 1.8台斤
     * - 系統會自動計算並儲存: 箱→包、箱→台斤、箱→公斤、箱→公克、包→台斤、包→公斤、包→公克...
     *
     * @param array $primaryConversions [['from' => 'bag', 'to' => 'twct', 'qty' => 1.8], ...]
     */
    public function setUnitConversionsWithExpansion(array $primaryConversions): void
    {
        UnitConversionExpander::for($this)->expand($primaryConversions);

        // 清除此實體的換算快取
        $this->clearConversionCache();
    }

    /**
     * 取得主要換算關係（使用者設定的，非系統衍生的）
     */
    public function getPrimaryUnitConversions(): Collection
    {
        return $this->unitConversions()
            ->where('is_derived', false)
            ->active()
            ->get();
    }

    /**
     * 取得衍生換算關係（系統自動計算的）
     */
    public function getDerivedUnitConversions(): Collection
    {
        return $this->unitConversions()
            ->where('is_derived', true)
            ->active()
            ->get();
    }

    /**
     * 取得從指定單位到目標單位的換算數量
     *
     * 使用雙層快取：
     * 1. 跨請求 Cache（Redis/File）
     * 2. 請求內 once()，避免重複讀 Cache
     */
    public function getConversionQuantity(string $fromUnitCode, string $toUnitCode): ?float
    {
        if ($fromUnitCode === $toUnitCode) {
            return 1.0;
        }

        $conversions = $this->getConversionMap();

        return $conversions[$fromUnitCode][$toUnitCode] ?? null;
    }

    /**
     * 取得此實體的換算對照表（帶快取）
     *
     * @return array<string, array<string, float>> ['from_code']['to_code'] => quantity
     */
    public function getConversionMap(): array
    {
        // 第二層：請求內快取（once），避免同一請求重複讀 Cache
        $cacheKey = $this->getConversionCacheKey();

        return once(function () use ($cacheKey) {
            // 第一層：跨請求快取（Cache）
            if (!config('unit-conversion.cache.enabled', true)) {
                return $this->buildConversionMap();
            }

            $ttl = config('unit-conversion.cache.entity_ttl')
                ?? config('unit-conversion.cache.ttl', 3600);

            return Cache::remember($cacheKey, $ttl, fn () => $this->buildConversionMap());
        });
    }

    /**
     * 建立換算對照表
     *
     * @return array<string, array<string, float>>
     */
    protected function buildConversionMap(): array
    {
        $map = [];

        foreach ($this->activeUnitConversions()->get() as $conversion) {
            $map[$conversion->from_unit_code][$conversion->to_unit_code] = (float) $conversion->quantity;
        }

        return $map;
    }

    /**
     * 取得此實體的換算快取 key
     */
    public function getConversionCacheKey(): string
    {
        $prefix = config('unit-conversion.cache.prefix', 'unit_conversion_');

        return $prefix . 'entity_' . $this->getMorphClass() . '_' . $this->getKey();
    }

    /**
     * 清除此實體的換算快取
     */
    public function clearConversionCache(): void
    {
        Cache::forget($this->getConversionCacheKey());
    }

    /**
     * 取得此實體可用的所有單位代碼（包含來源和目標）
     */
    public function getAllUnitCodes(): Collection
    {
        $fromCodes = $this->activeUnitConversions()->pluck('from_unit_code');
        $toCodes = $this->activeUnitConversions()->pluck('to_unit_code');

        return $fromCodes->merge($toCodes)->unique()->values();
    }
}
