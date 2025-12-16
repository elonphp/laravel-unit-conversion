<?php

namespace Elonphp\UnitConversion\Services;

use Elonphp\UnitConversion\Contracts\Convertible;
use Elonphp\UnitConversion\Models\Unit;
use Elonphp\UnitConversion\Models\UnitConversion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 單位換算自動展開服務
 *
 * 使用者只需設定基本換算關係，系統自動展開所有組合。
 * 例如：
 * - 使用者設定: 1箱 = 6包, 1包 = 1.8台斤
 * - 系統自動展開: 箱→包, 箱→台斤, 箱→公斤, 箱→公克, 包→台斤, 包→公斤, 包→公克, 以及所有反向換算
 */
class UnitConversionExpander
{
    protected Convertible $entity;
    protected Collection $primaryConversions;
    protected Collection $allUnits;
    protected array $conversionGraph = [];

    /**
     * Create a new expander instance.
     */
    public static function for(Convertible $entity): static
    {
        $instance = new static();
        $instance->entity = $entity;
        return $instance;
    }

    /**
     * 根據主要換算關係展開所有組合
     *
     * @param array $primaryConversions [['from' => 'bag', 'to' => 'twct', 'qty' => 1.8], ...]
     */
    public function expand(array $primaryConversions): void
    {
        if (empty($primaryConversions)) {
            $this->clearAllConversions();
            return;
        }

        DB::transaction(function () use ($primaryConversions) {
            // 1. 刪除所有現有換算（衍生的和非衍生的）
            $this->clearAllConversions();

            // 2. 收集所有涉及的單位代碼
            $unitCodes = $this->collectUnitCodes($primaryConversions);

            // 3. 載入所有單位資訊
            $this->allUnits = Unit::whereIn('code', $unitCodes)->get()->keyBy('code');

            // 4. 補充相關的標準單位
            $this->addRelatedStandardUnits();

            // 5. 建立換算圖
            $this->buildConversionGraph($primaryConversions);

            // 6. 計算所有單位對的換算值
            $allConversions = $this->calculateAllPairs();

            // 7. 儲存主要換算（is_derived = false）
            $this->savePrimaryConversions($primaryConversions);

            // 8. 儲存衍生換算（is_derived = true）
            $this->saveDerivedConversions($allConversions, $primaryConversions);
        });
    }

    /**
     * 清除實體的所有換算設定
     */
    protected function clearAllConversions(): void
    {
        UnitConversion::query()
            ->where('unitable_type', $this->entity->getMorphClass())
            ->where('unitable_id', $this->entity->getKey())
            ->delete();
    }

    /**
     * 收集所有涉及的單位代碼
     */
    protected function collectUnitCodes(array $primaryConversions): array
    {
        $codes = [];
        foreach ($primaryConversions as $conv) {
            $codes[] = $conv['from'];
            $codes[] = $conv['to'];
        }
        return array_unique($codes);
    }

    /**
     * 補充相關的標準單位（同類型的標準單位可以互相換算）
     */
    protected function addRelatedStandardUnits(): void
    {
        // 收集涉及的單位類型
        $types = $this->allUnits->pluck('type')->unique()->filter()->toArray();

        if (empty($types)) {
            return;
        }

        // 查詢同類型的所有標準單位
        $standardUnits = Unit::whereIn('type', $types)
            ->where('is_standard', true)
            ->where('is_active', true)
            ->get();

        // 合併到 allUnits
        foreach ($standardUnits as $unit) {
            if (!$this->allUnits->has($unit->code)) {
                $this->allUnits[$unit->code] = $unit;
            }
        }
    }

    /**
     * 建立換算圖（鄰接表形式）
     *
     * 圖的邊代表換算關係，權重是換算值
     * 例如: graph['bag']['twct'] = 1.8 表示 1 bag = 1.8 twct
     */
    protected function buildConversionGraph(array $primaryConversions): void
    {
        $this->conversionGraph = [];

        // 1. 加入使用者設定的主要換算
        foreach ($primaryConversions as $conv) {
            $from = $conv['from'];
            $to = $conv['to'];
            $qty = (float) $conv['qty'];

            // 正向
            $this->conversionGraph[$from][$to] = $qty;
            // 反向
            $this->conversionGraph[$to][$from] = 1 / $qty;
        }

        // 2. 加入同類型標準單位間的換算
        $unitsByType = $this->allUnits->filter(fn($u) => $u->is_standard)->groupBy('type');

        foreach ($unitsByType as $type => $units) {
            $unitCodes = $units->pluck('code')->toArray();

            // 每對標準單位都可以互相換算
            foreach ($unitCodes as $fromCode) {
                foreach ($unitCodes as $toCode) {
                    if ($fromCode === $toCode) continue;

                    $fromUnit = $this->allUnits[$fromCode];
                    $toUnit = $this->allUnits[$toCode];

                    // 換算公式: result = qty × from.value / to.value
                    $rate = (float) $fromUnit->value / (float) $toUnit->value;
                    $this->conversionGraph[$fromCode][$toCode] = $rate;
                }
            }
        }
    }

    /**
     * 使用 Floyd-Warshall 演算法計算所有單位對的換算值
     */
    protected function calculateAllPairs(): array
    {
        $unitCodes = $this->allUnits->keys()->toArray();
        $n = count($unitCodes);

        // 初始化距離矩陣（使用換算率而非距離）
        // dist[i][j] 表示 1 個 unit[i] = dist[i][j] 個 unit[j]
        $dist = [];
        foreach ($unitCodes as $i) {
            foreach ($unitCodes as $j) {
                if ($i === $j) {
                    $dist[$i][$j] = 1.0;
                } elseif (isset($this->conversionGraph[$i][$j])) {
                    $dist[$i][$j] = $this->conversionGraph[$i][$j];
                } else {
                    $dist[$i][$j] = null; // 無直接路徑
                }
            }
        }

        // Floyd-Warshall: 透過中間節點 k 找到所有路徑
        foreach ($unitCodes as $k) {
            foreach ($unitCodes as $i) {
                foreach ($unitCodes as $j) {
                    if ($i === $j) continue;

                    // 如果可以透過 k 到達
                    if ($dist[$i][$k] !== null && $dist[$k][$j] !== null) {
                        $throughK = $dist[$i][$k] * $dist[$k][$j];

                        // 如果原本沒有路徑，或透過 k 的路徑更短（這裡我們取最短路徑保持一致性）
                        if ($dist[$i][$j] === null) {
                            $dist[$i][$j] = $throughK;
                        }
                        // 如果已有路徑，保留原值（保持穩定性）
                    }
                }
            }
        }

        // 轉換為結果陣列
        $results = [];
        foreach ($unitCodes as $from) {
            foreach ($unitCodes as $to) {
                if ($from === $to) continue;
                if ($dist[$from][$to] !== null) {
                    $results[] = [
                        'from' => $from,
                        'to' => $to,
                        'qty' => $dist[$from][$to],
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * 儲存主要換算（使用者設定的）
     */
    protected function savePrimaryConversions(array $primaryConversions): void
    {
        foreach ($primaryConversions as $conv) {
            UnitConversion::create([
                'unitable_type' => $this->entity->getMorphClass(),
                'unitable_id' => $this->entity->getKey(),
                'from_unit_code' => $conv['from'],
                'to_unit_code' => $conv['to'],
                'quantity' => $conv['qty'],
                'is_derived' => false,
                'is_active' => true,
            ]);

            // 也儲存反向
            UnitConversion::create([
                'unitable_type' => $this->entity->getMorphClass(),
                'unitable_id' => $this->entity->getKey(),
                'from_unit_code' => $conv['to'],
                'to_unit_code' => $conv['from'],
                'quantity' => 1 / (float) $conv['qty'],
                'is_derived' => false,
                'is_active' => true,
            ]);
        }
    }

    /**
     * 儲存衍生換算（系統自動計算的）
     */
    protected function saveDerivedConversions(array $allConversions, array $primaryConversions): void
    {
        // 建立主要換算的 Set，用於排除
        $primarySet = [];
        foreach ($primaryConversions as $conv) {
            $primarySet[$conv['from'] . '->' . $conv['to']] = true;
            $primarySet[$conv['to'] . '->' . $conv['from']] = true;
        }

        foreach ($allConversions as $conv) {
            $key = $conv['from'] . '->' . $conv['to'];

            // 跳過主要換算（已經儲存過）
            if (isset($primarySet[$key])) {
                continue;
            }

            UnitConversion::create([
                'unitable_type' => $this->entity->getMorphClass(),
                'unitable_id' => $this->entity->getKey(),
                'from_unit_code' => $conv['from'],
                'to_unit_code' => $conv['to'],
                'quantity' => $conv['qty'],
                'is_derived' => true,
                'is_active' => true,
            ]);
        }
    }
}
