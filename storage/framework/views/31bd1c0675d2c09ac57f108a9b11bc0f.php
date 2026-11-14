<!DOCTYPE html>
<html lang="<?php echo e(str_replace('_', '-', app()->getLocale())); ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#5B3E96">
        
        <meta property="csp-nonce" nonce="<?php echo e(Vite::cspNonce()); ?>">

        <?php if (! (Vite::isRunningHot())): ?>
            <link rel="preload" as="font" type="font/woff2" crossorigin
                  href="<?php echo e(Vite::asset('node_modules/@fontsource-variable/plus-jakarta-sans/files/plus-jakarta-sans-latin-wght-normal.woff2')); ?>">
        <?php endif; ?>

        <?php echo app('Illuminate\Foundation\Vite')->reactRefresh(); ?>
        <?php echo app('Illuminate\Foundation\Vite')(['resources/js/app.tsx']); ?>
        <?php $__inertiaSsrResponse = app(\Inertia\Ssr\SsrState::class)->setPage($page)->dispatch();  if ($__inertiaSsrResponse) { echo $__inertiaSsrResponse->head; } ?>
    </head>
    <body>
        <?php $__inertiaSsrResponse = app(\Inertia\Ssr\SsrState::class)->setPage($page)->dispatch();  if ($__inertiaSsrResponse) { echo $__inertiaSsrResponse->body; } else { ?><script data-page="app" type="application/json"><?php echo json_encode($page, JSON_HEX_TAG); ?></script><div id="app"></div><?php } ?>
    </body>
</html>
<?php /**PATH /app/resources/views/app.blade.php ENDPATH**/ ?>