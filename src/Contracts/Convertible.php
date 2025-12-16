<?php

namespace Elonphp\UnitConversion\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Interface for models that support unit conversion.
 *
 * This interface is designed to be compatible with Laravel's Eloquent Model.
 * The getMorphClass() and getKey() methods are already provided by Model,
 * so implementing this interface only requires using the HasUnitConversion trait.
 */
interface Convertible
{
    /**
     * Get the morph class name for polymorphic relations.
     * Note: No return type to maintain compatibility with Laravel's Model::getMorphClass()
     */
    public function getMorphClass();

    /**
     * Get the primary key value.
     * Note: No return type to maintain compatibility with Laravel's Model::getKey()
     */
    public function getKey();

    /**
     * Get unit conversions for this entity.
     */
    public function unitConversions(): MorphMany;
}
