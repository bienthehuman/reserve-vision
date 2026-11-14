<?php

declare(strict_types=1);

arch('app code declares strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('app classes are final by default')
    ->expect('App')
    ->classes()
    ->toBeFinal();

arch('no debugging or unsafe PHP functions')
    ->preset()->php();

arch('no security-sensitive functions')
    ->preset()->security();

// ADR 0005: the word is ambiguous in this domain, so it never appears.
// Pest's arch() inspects PHP symbols, not text, so this scans every file.
test('no "tenant" in app/, database/, resources/ or routes/', function (): void {
    $root = dirname(__DIR__, 2);
    $word = 'ten'.'ant'; // split so this file can't match itself if tests/ is ever scanned
    $hits = [];

    foreach (['app', 'database', 'resources', 'routes'] as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}", FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            $path = substr($file->getPathname(), strlen($root) + 1);

            if (stripos($path, $word) !== false || stripos((string) file_get_contents($file->getPathname()), $word) !== false) {
                $hits[] = $path;
            }
        }
    }

    expect($hits)->toBeEmpty();
});
