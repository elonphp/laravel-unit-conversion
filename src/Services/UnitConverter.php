<?php

namespace Elonphp\UnitConversion\Services;

use Elonphp\UnitConversion\Contracts\Convertible;
use Elonphp\UnitConversion\Models\Unit;
use Elonphp\UnitConversion\Models\UnitConversion;
use Elonphp\UnitConversion\Exceptions\UnitNotFoundException;
use Elonphp\UnitConversion\Exceptions\ConversionNotFoundException;
use Elonphp\UnitConversion\Exceptions\UnitTypeMismatchException;
use Illuminate\Support\Facades\Cache;

class UnitConverter
{
    protected ?Convertible $entity = null;
    protected float $quantity = 0;
    protected ?string $fromUnitCode = null;
    protected ?string $toUnitCode = null;

    /**
     * Create a new instance.
     */
    public static function make(): static
    {
        return new static();
    }

    /**
     * Set the entity (product, material, etc.) for entity-specific conversion.
     */
    public function for(Convertible $entity): static
    {
        $this->entity = $entity;
        return $this;
    }

    /**
     * Set the quantity to convert.
     */
    public function qty(float $quantity): static
    {
        $this->quantity = $quantity;
        return $this;
    }

    /**
     * Set the source unit code.
     */
    public function from(string $unitCode): static
    {
        $this->fromUnitCode = $unitCode;
        return $this;
    }

    /**
     * Set the target unit code.
     */
    public function to(string $unitCode): static
    {
        $this->toUnitCode = $unitCode;
        return $this;
    }

    /**
     * Perform the conversion.
     */
    public function convert(): float
    {
        // Same unit, no conversion needed
        if ($this->fromUnitCode === $this->toUnitCode) {
            return $this->quantity;
        }

        $fromUnit = $this->getUnit($this->fromUnitCode);
        $toUnit = $this->getUnit($this->toUnitCode);

        // Both are standard units of the same type - direct conversion
        if ($fromUnit->is_standard && $toUnit->is_standard && $fromUnit->type === $toUnit->type) {
            return $this->convertStandard($fromUnit, $toUnit, $this->quantity);
        }

        // Need entity-specific conversion
        if (!$this->entity) {
            throw new ConversionNotFoundException(
                "Entity is required for non-standard unit conversion: {$this->fromUnitCode} to {$this->toUnitCode}"
            );
        }

        return $this->convertWithEntity();
    }

    /**
     * Convert between standard units.
     */
    protected function convertStandard(Unit $from, Unit $to, float $quantity): float
    {
        return $quantity * (float) $from->value / (float) $to->value;
    }

    /**
     * Convert using entity-specific conversion rules.
     *
     * 由於使用自動展開功能，所有換算組合都已預先計算並儲存，
     * 因此可以直接查詢 from→to 的換算關係。
     *
     * 使用雙層快取優化：
     * 1. 跨請求 Cache（Redis/File）
     * 2. 請求內 once()，避免重複讀 Cache
     */
    protected function convertWithEntity(): float
    {
        // 優先使用快取的換算對照表（方案 1+2）
        $conversionQty = $this->findEntityConversionQuantity($this->fromUnitCode, $this->toUnitCode);

        if ($conversionQty !== null) {
            return $this->quantity * $conversionQty;
        }

        // 備用方案：嘗試透過標準單位換算（相容舊資料或未展開的情況）
        $fromConversion = $this->findEntityConversion($this->fromUnitCode);

        if ($fromConversion) {
            $intermediateQty = $this->quantity * (float) $fromConversion->quantity;
            $intermediateUnitCode = $fromConversion->to_unit_code;

            if ($intermediateUnitCode === $this->toUnitCode) {
                return $intermediateQty;
            }

            $intermediateUnit = $this->getUnit($intermediateUnitCode);
            $toUnit = $this->getUnit($this->toUnitCode);

            if ($intermediateUnit->is_standard && $toUnit->is_standard && $intermediateUnit->type === $toUnit->type) {
                return $this->convertStandard($intermediateUnit, $toUnit, $intermediateQty);
            }
        }

        throw new ConversionNotFoundException(
            "No conversion path found from {$this->fromUnitCode} to {$this->toUnitCode}"
        );
    }

    /**
     * Find entity-specific conversion quantity using cached conversion map.
     */
    protected function findEntityConversionQuantity(string $fromCode, string $toCode): ?float
    {
        return $this->entity->getConversionQuantity($fromCode, $toCode);
    }

    /**
     * Find entity-specific conversion (for fallback path).
     */
    protected function findEntityConversion(string $fromCode, ?string $toCode = null): ?UnitConversion
    {
        $query = UnitConversion::query()
            ->where('unitable_type', $this->entity->getMorphClass())
            ->where('unitable_id', $this->entity->getKey())
            ->where('from_unit_code', $fromCode)
            ->active();

        if ($toCode) {
            $query->where('to_unit_code', $toCode);
        }

        return $query->first();
    }

    /**
     * Get unit by code with caching.
     */
    protected function getUnit(string $code): Unit
    {
        $cacheEnabled = config('unit-conversion.cache.enabled', true);
        $cacheKey = config('unit-conversion.cache.prefix', 'unit_conversion_') . 'unit_' . $code;
        $cacheTtl = config('unit-conversion.cache.ttl', 3600);

        if ($cacheEnabled) {
            $unit = Cache::remember($cacheKey, $cacheTtl, function () use ($code) {
                return Unit::where('code', $code)->first();
            });
        } else {
            $unit = Unit::where('code', $code)->first();
        }

        if (!$unit) {
            throw new UnitNotFoundException("Unit not found: {$code}");
        }

        return $unit;
    }

    /**
     * Get all units with optional filters.
     */
    public function getUnits(?string $type = null, bool $activeOnly = true): \Illuminate\Support\Collection
    {
        $query = Unit::query();

        if ($type) {
            $query->ofType($type);
        }

        if ($activeOnly) {
            $query->active();
        }

        return $query->orderBy('sort_order')->orderBy('code')->get();
    }

    /**
     * Get standard units that can be converted to each other.
     */
    public function getStandardUnits(?string $type = null): \Illuminate\Support\Collection
    {
        $query = Unit::standard()->active();

        if ($type) {
            $query->ofType($type);
        }

        return $query->orderBy('sort_order')->orderBy('code')->get();
    }

    /**
     * Clear unit cache.
     */
    public function clearCache(): void
    {
        $prefix = config('unit-conversion.cache.prefix', 'unit_conversion_');

        // Clear all cached units
        $units = Unit::pluck('code');
        foreach ($units as $code) {
            Cache::forget($prefix . 'unit_' . $code);
        }
    }
}
