<?php

namespace Elonphp\UnitConversion\Models;

use Elonphp\UnitConversion\Casts\JsonUnescapedUnicode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Unit extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'translations' => JsonUnescapedUnicode::class,
        'value' => 'decimal:10',
        'is_standard' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Get the table name from config.
     */
    public function getTable(): string
    {
        return config('unit-conversion.tables.units', 'cfg_units');
    }

    /**
     * Get translated name for current or specified locale.
     */
    public function getName(?string $locale = null): ?string
    {
        $locale = $locale ?? app()->getLocale();
        $fallback = config('unit-conversion.fallback_locale', 'en');

        return $this->translations[$locale]
            ?? $this->translations[$fallback]
            ?? $this->code;
    }

    /**
     * Get the label (code + name).
     */
    public function getLabel(?string $locale = null): string
    {
        return $this->code . ' ' . $this->getName($locale);
    }

    /**
     * Scope: filter by type.
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Scope: only standard units.
     */
    public function scopeStandard(Builder $query): Builder
    {
        return $query->where('is_standard', true);
    }

    /**
     * Scope: only active units.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Convert quantity from this unit to another unit.
     * Only works for standard units of the same type.
     */
    public function convertTo(Unit $toUnit, float $quantity): float
    {
        if ($this->type !== $toUnit->type) {
            throw new \InvalidArgumentException(
                "Cannot convert between different types: {$this->type} and {$toUnit->type}"
            );
        }

        if (!$this->is_standard || !$toUnit->is_standard) {
            throw new \InvalidArgumentException(
                "Both units must be standard units for direct conversion"
            );
        }

        return $quantity * (float) $this->value / (float) $toUnit->value;
    }
}
