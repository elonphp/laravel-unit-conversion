<?php

namespace Elonphp\UnitConversion\Console;

use Elonphp\UnitConversion\Models\Unit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedUnitsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'unit-conversion:seed
                            {--file= : Path to custom CSV file}
                            {--fresh : Truncate table before seeding}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed unit data from CSV file';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $file = $this->option('file') ?? $this->getDefaultCsvPath();

        if (!file_exists($file)) {
            $this->error("CSV file not found: {$file}");
            return self::FAILURE;
        }

        $this->info("Reading units from: {$file}");

        // Read CSV
        $units = $this->parseCsv($file);

        if (empty($units)) {
            $this->error('No units found in CSV file');
            return self::FAILURE;
        }

        $this->info("Found " . count($units) . " units");

        // Fresh option - truncate table
        if ($this->option('fresh')) {
            if ($this->confirm('This will delete all existing units. Continue?', false)) {
                DB::table(config('unit-conversion.tables.units', 'cfg_units'))->truncate();
                $this->warn('Table truncated');
            } else {
                $this->info('Operation cancelled');
                return self::SUCCESS;
            }
        }

        // Import units
        $created = 0;
        $updated = 0;

        $bar = $this->output->createProgressBar(count($units));
        $bar->start();

        foreach ($units as $unitData) {
            $unit = Unit::updateOrCreate(
                ['code' => $unitData['code']],
                $unitData
            );

            if ($unit->wasRecentlyCreated) {
                $created++;
            } else {
                $updated++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Units seeded successfully!");
        $this->table(
            ['Action', 'Count'],
            [
                ['Created', $created],
                ['Updated', $updated],
                ['Total', count($units)],
            ]
        );

        return self::SUCCESS;
    }

    /**
     * Get the default CSV file path from package.
     */
    protected function getDefaultCsvPath(): string
    {
        return __DIR__ . '/../../database/data/units.csv';
    }

    /**
     * Parse CSV file to array.
     */
    protected function parseCsv(string $file): array
    {
        $units = [];
        $handle = fopen($file, 'r');

        if ($handle === false) {
            return [];
        }

        // Read header (skip BOM if present)
        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            return [];
        }

        // Remove BOM from first column if present
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

        // Map header to column indices
        $headerMap = array_flip($header);

        while (($row = fgetcsv($handle)) !== false) {
            // Skip empty rows
            if (empty($row[0])) {
                continue;
            }

            $unit = [
                'code' => $row[$headerMap['code']] ?? null,
                'type' => $row[$headerMap['type']] ?? null,
                'value' => $this->parseValue($row[$headerMap['value']] ?? null),
                'translations' => $this->parseJson($row[$headerMap['translations']] ?? '{}'),
                'is_standard' => (bool) ($row[$headerMap['is_standard']] ?? false),
                'is_active' => (bool) ($row[$headerMap['is_active']] ?? true),
                'sort_order' => (int) ($row[$headerMap['sort_order']] ?? 0),
            ];

            // Skip if no code
            if (empty($unit['code'])) {
                continue;
            }

            $units[] = $unit;
        }

        fclose($handle);

        return $units;
    }

    /**
     * Parse value field (handle empty values).
     */
    protected function parseValue(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    /**
     * Parse JSON string to array.
     */
    protected function parseJson(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
