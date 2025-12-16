<?php

namespace Elonphp\UnitConversion\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Elonphp\UnitConversion\Services\UnitConverter make()
 * @method static \Elonphp\UnitConversion\Services\UnitConverter for(\Elonphp\UnitConversion\Contracts\Convertible $entity)
 * @method static \Elonphp\UnitConversion\Services\UnitConverter qty(float $quantity)
 * @method static \Elonphp\UnitConversion\Services\UnitConverter from(string $unitCode)
 * @method static \Elonphp\UnitConversion\Services\UnitConverter to(string $unitCode)
 * @method static float convert()
 * @method static \Illuminate\Support\Collection getUnits(?string $type = null, bool $activeOnly = true)
 * @method static \Illuminate\Support\Collection getStandardUnits(?string $type = null)
 * @method static void clearCache()
 *
 * @see \Elonphp\UnitConversion\Services\UnitConverter
 */
class UnitConverter extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'unit-converter';
    }
}
