# Laravel Unit Conversion

Laravel 單位換算套件，支援多語系、實體專屬單位換算與自動展開所有換算組合。

## 功能

- 通用單位定義與換算（公斤 ↔ 台斤）
- 實體專屬單位換算（A商品的1包 = 1.8台斤）
- **自動展開所有換算組合**（設定基本換算，系統自動計算所有組合）
- **雙層快取機制**（跨請求 Cache + 請求內 once()）
- 多語系支援（JSON 欄位）
- Polymorphic 關聯（跨專案通用）

## 安裝

```bash
composer require elonphp/laravel-unit-conversion
```

### 發布設定檔

```bash
php artisan vendor:publish --tag=unit-conversion-config
```

### 發布 Migration（可選）

```bash
php artisan vendor:publish --tag=unit-conversion-migrations
```

### 執行 Migration

```bash
php artisan migrate
```

### 匯入預設單位資料

```bash
# 匯入套件預設的 46 個單位
php artisan unit-conversion:seed

# 使用自訂 CSV 檔案
php artisan unit-conversion:seed --file=/path/to/custom.csv

# 清空資料表後重新匯入
php artisan unit-conversion:seed --fresh
```

#### 在 DatabaseSeeder 中自動匯入

如果希望 `migrate:fresh --seed` 時自動匯入單位資料：

```php
// database/seeders/DatabaseSeeder.php
public function run(): void
{
    $this->call([
        // ... 其他 seeders
    ]);

    // 匯入單位資料
    $this->command->call('unit-conversion:seed');
}
```

#### 發布預設資料（可選）

```bash
# 發布 CSV 檔案到專案，方便自訂
php artisan vendor:publish --tag=unit-conversion-data
```

## 設定 MorphMap

在 `AppServiceProvider` 中設定（建議使用 `enforceMorphMap` 確保一致性）：

```php
use Illuminate\Database\Eloquent\Relations\Relation;

public function boot(): void
{
    Relation::enforceMorphMap([
        'product' => \App\Models\Product::class,
        'material' => \App\Models\Material::class,
    ]);
}
```

## 使用方式

### Model 設定

```php
use Elonphp\UnitConversion\Contracts\Convertible;
use Elonphp\UnitConversion\Traits\HasUnitConversion;

class Product extends Model implements Convertible
{
    use HasUnitConversion;
}
```

### 設定商品單位換算（自動展開）

```php
// 設定主要換算，系統自動展開所有組合
$product->setUnitConversionsWithExpansion([
    ['from' => 'ctn', 'to' => 'bag', 'qty' => 6],      // 1箱 = 6包
    ['from' => 'bag', 'to' => 'twct', 'qty' => 1.8],   // 1包 = 1.8台斤
]);

// 系統會自動產生所有換算組合：
// - ctn → bag, bag, twct, g, kg, mg, twta
// - bag → ctn, twct, g, kg, mg, twta
// - twct → ctn, bag, g, kg, mg, twta
// - ... 以及所有反向換算
```

### 查詢換算值

```php
// 直接取得換算值
$qty = $product->getConversionQuantity('ctn', 'kg');  // 6.48

// 取得主要換算（使用者設定的）
$primary = $product->getPrimaryUnitConversions();

// 取得衍生換算（系統自動計算的）
$derived = $product->getDerivedUnitConversions();

// 取得所有可用單位
$codes = $product->getAllUnitCodes();  // ['ctn', 'bag', 'twct', 'g', 'kg', ...]
```

### 單位換算

```php
use Elonphp\UnitConversion\Facades\UnitConverter;

// 透過 Facade
$result = UnitConverter::make()
    ->for($product)
    ->qty(3)
    ->from('bag')
    ->to('kg')
    ->convert();

// 透過 Model 方法
$result = $product->convertUnit(3, 'bag', 'kg');

// 標準單位換算（不需 entity）
$result = UnitConverter::make()
    ->qty(1)
    ->from('kg')
    ->to('twct')
    ->convert(); // 1.667
```

### 查詢單位

```php
// 取得所有單位
$units = UnitConverter::make()->getUnits();

// 取得特定類型
$massUnits = UnitConverter::make()->getUnits('mass');

// 取得標準單位
$standardUnits = UnitConverter::make()->getStandardUnits('mass');
```

### 取得單位名稱（多語系）

```php
$unit = Unit::where('code', 'kg')->first();

$unit->getName();           // 依當前 locale
$unit->getName('en');       // kilogram
$unit->getName('zh_Hant');    // 公斤
$unit->getLabel();          // kg 公斤
```

## 換算流程範例

```
進貨 3 包，商品設定 1包=1.8台斤，庫存單位是公斤

由於使用自動展開，系統已預先計算好 bag → kg = 1.08
直接查表：3 包 × 1.08 公斤/包 = 3.24 公斤
```

## 快取機制

套件內建雙層快取機制，大幅減少資料庫查詢：

### 快取架構

| 層級 | 類型 | 生命週期 | 說明 |
|------|------|---------|------|
| 第一層 | Laravel Cache | 跨請求（預設 1 小時） | 使用 Redis/File 等驅動 |
| 第二層 | once() | 單次請求內 | 避免重複讀取 Cache |

### 快取流程

```
請求進來
    │
    ▼
once() 檢查（請求內快取）
    │ 未命中
    ▼
Cache::remember() 檢查（跨請求快取）
    │ 未命中
    ▼
查詢 DB → 建立對照表 → 存入 Cache
    │
    ▼
回傳 ['ctn']['kg'] => 7.2 直接查表（O(1)）
```

### 快取設定

```php
// config/unit-conversion.php
'cache' => [
    'enabled' => true,
    'ttl' => 3600,           // 單位定義快取時間（秒）
    'entity_ttl' => 3600,    // 實體換算快取時間（秒），設 null 則用 ttl
    'prefix' => 'unit_conversion_',
],
```

### 快取失效

換算關係更新時會自動清除快取：

```php
// 設定換算時自動清除快取
$product->setUnitConversionsWithExpansion([...]);

// 手動清除特定實體的快取
$product->clearConversionCache();
```

### 快取相關方法

```php
// 取得換算對照表（帶快取）
$map = $product->getConversionMap();
// 回傳：['ctn' => ['kg' => 7.2, 'bag' => 6], 'bag' => ['kg' => 1.2], ...]

// 取得快取 key
$key = $product->getConversionCacheKey();
// 回傳：unit_conversion_entity_product_123

// 清除快取
$product->clearConversionCache();
```

## 資料表結構

### cfg_units

| 欄位 | 說明 |
|------|------|
| code | 單位代碼（kg, twct, bag...） |
| type | 類型（mass, volume, count...） |
| value | 對基準單位的換算值（計數單位可為 null） |
| translations | 多語系名稱 JSON |
| is_standard | 是否為標準單位（可自動換算） |

### cfg_unit_conversions

| 欄位 | 說明 |
|------|------|
| unitable_type | 實體類型（product, material...） |
| unitable_id | 實體 ID |
| from_unit_code | 來源單位 |
| to_unit_code | 目標單位 |
| quantity | 換算數量 |
| is_derived | 是否為系統自動展開 |

## License

MIT
