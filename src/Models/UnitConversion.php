<?php

namespace Elonphp\UnitConversion\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Builder;

class UnitConversion extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:4',
        'is_derived' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Get the table name from config.
     */
    public function getTable(): string
    {
        return config('unit-conversion.tables.unit_conversions', 'cfg_unit_conversions');
    }

    /**
     * Get the parent unitable model (Product, Material, etc.).
     */
    public function unitable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the from unit.
     */
    public function fromUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'from_unit_code', 'code');
    }

    /**
     * Get the to unit.
     */
    public function toUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'to_unit_code', 'code');
    }

    /**
     * Scope: only active conversions.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: filter by from unit code.
     */
    public function scopeFromUnit(Builder $query, string $code): Builder
    {
        return $query->where('from_unit_code', $code);
    }

    /**
     * Scope: filter by to unit code.
     */
    public function scopeToUnit(Builder $query, string $code): Builder
    {
        return $query->where('to_unit_code', $code);
    }

    /**
     * Scope: only primary (user-set) conversions.
     */
    public function scopePrimary(Builder $query): Builder
    {
        return $query->where('is_derived', false);
    }

    /**
     * Scope: only derived (auto-expanded) conversions.
     */
    public function scopeDerived(Builder $query): Builder
    {
        return $query->where('is_derived', true);
    }
}
