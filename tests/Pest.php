<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/*
| Feature tests boot the app and wrap each test in a transaction on the default
| (`app`) connection. Never RefreshDatabase: `app` can't run DDL (ADR 0008);
| TestCase migrates once per run as `migrator` instead.
*/
pest()->extend(TestCase::class)
    ->use(DatabaseTransactions::class)
    ->in('Feature');
