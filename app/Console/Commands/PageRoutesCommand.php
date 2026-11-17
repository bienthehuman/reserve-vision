<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Routing\PageRoutes;
use Illuminate\Console\Command;

/**
 * Prints (or, with --write, saves) the list of GET routes the accessibility sweep is built from.
 * Run it in the dev stack, whose media disk is local like the tests': the production stack serves
 * photos from a bucket and so has one route fewer.
 */
final class PageRoutesCommand extends Command
{
    protected $signature = 'app:page-routes {--write : Save it as e2e/fixtures/page-routes.json}';

    protected $description = 'List the GET routes for the accessibility sweep (e2e/a11y-sweep.spec.ts)';

    public function handle(): int
    {
        if ($this->option('write')) {
            file_put_contents(base_path(PageRoutes::FIXTURE), PageRoutes::json());
            $this->components->info('Wrote '.PageRoutes::FIXTURE.' ('.count(PageRoutes::all()).' routes).');

            return self::SUCCESS;
        }

        $this->output->write(PageRoutes::json());

        return self::SUCCESS;
    }
}
