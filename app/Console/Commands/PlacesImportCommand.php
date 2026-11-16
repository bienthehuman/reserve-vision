<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Places\PlacesImporter;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Loads the PSGC places shipped in `database/data/psgc/` (slice 4.4; DEPLOY.md says when
 * to run it) and links properties that have no city or barangay yet. Idempotent: run by
 * `bin/setup` and `deploy.sh` every time. Runs as the `app` user: inserts and updates only.
 */
final class PlacesImportCommand extends Command
{
    protected $signature = 'places:import
        {--path= : A directory with provinces.csv, cities.csv and barangays.csv (default: database/data/psgc)}';

    protected $description = 'Import every province, city and barangay from the PSGC files, and link properties to them';

    public function handle(PlacesImporter $importer): int
    {
        $path = $this->option('path');
        $directory = is_string($path) && $path !== '' ? $path : database_path('data/psgc');

        try {
            $report = $importer->import($directory);
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($report->rows as $table => $rows) {
            $this->line(sprintf('%s: %d in the files, %d new', $table, $rows, $report->added[$table]));
        }

        if ($report->gone !== []) {
            $this->components->warn(count($report->gone).' places are no longer in the files and were kept:');
            foreach (array_slice($report->gone, 0, 20) as $place) {
                $this->line("  {$place}");
            }
            if (count($report->gone) > 20) {
                $this->line('  … and '.(count($report->gone) - 20).' more');
            }
        }

        $this->line("Properties linked: {$report->linkedCities} to a city, {$report->linkedBarangays} to a barangay");
        $this->line("Properties without a city: {$report->unlinkedCities}");
        $this->line("Properties without a barangay: {$report->unlinkedBarangays}");
        $this->components->info(sprintf('Places imported in %.1f s.', $report->seconds));

        return self::SUCCESS;
    }
}
