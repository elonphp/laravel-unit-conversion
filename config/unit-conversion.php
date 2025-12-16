<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    |
    | Customize the table names used by this package.
    |
    */

    'tables' => [
        'units' => 'cfg_units',
        'unit_conversions' => 'cfg_unit_conversions',
    ],

    /*
    |--------------------------------------------------------------------------
    | Base Units
    |--------------------------------------------------------------------------
    |
    | Define the base unit (value=1) for each measurement type.
    | Non-standard units (e.g., count) should not have a base unit
    | because they cannot be converted to each other.
    | 非通用標準單位不能設基準，因為不能互換。
    |
    */

    'base_units' => [
        'mass' => 'g',
        'volume' => 'L',
        'length' => 'm',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Locale
    |--------------------------------------------------------------------------
    |
    | The default locale used when retrieving unit translations.
    | Set to null to use app()->getLocale().
    |
    */

    'default_locale' => null,

    /*
    |--------------------------------------------------------------------------
    | Fallback Locale
    |--------------------------------------------------------------------------
    |
    | The fallback locale when translation is not found.
    |
    */

    'fallback_locale' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Cache settings for unit data.
    |
    | - enabled: 是否啟用快取
    | - ttl: 單位定義（cfg_units）的快取時間（秒）
    | - entity_ttl: 實體換算關係（cfg_unit_conversions）的快取時間（秒）
    |              設為 null 則使用 ttl 的值
    | - prefix: 快取 key 前綴
    |
    */

    'cache' => [
        'enabled' => true,
        'ttl' => 3600, // seconds - 單位定義快取
        'entity_ttl' => 3600, // seconds - 實體換算關係快取（設 null 則用 ttl）
        'prefix' => 'unit_conversion_',
    ],

];
