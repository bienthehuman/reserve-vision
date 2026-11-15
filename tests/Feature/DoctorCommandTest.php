<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

// app:doctor (slice 1.3): run by deploy.sh and the production CI workflow.

beforeEach(function (): void {
    Storage::fake('photos');
    Storage::fake('proofs');
});

it('passes when the environment is healthy', function (): void {
    config(['queue.default' => 'database']);

    $this->artisan('app:doctor')
        ->expectsOutputToContain('Database connection')
        ->expectsOutputToContain('CREATE TABLE denied, as it should be')
        ->expectsOutputToContain('jobs: 0 pending, 0 failed')
        ->expectsOutputToContain('Storage: photos')
        ->expectsOutputToContain('Storage: proofs')
        ->expectsOutputToContain('All 6 checks passed.')
        ->assertExitCode(0);

    expect(Storage::disk('photos')->allFiles())->toBeEmpty()
        ->and(Storage::disk('proofs')->allFiles())->toBeEmpty();
});

it('fails when a disk cannot be written', function (): void {
    config(['filesystems.disks.proofs' => [
        'driver' => 'local',
        'root' => '/proc/doctor-cannot-write-here',
        'throw' => true,
    ]]);
    Storage::forgetDisk('proofs');

    $this->artisan('app:doctor')
        ->expectsOutputToContain('1 of 6 checks failed.')
        ->assertExitCode(1);
});

it('fails without a valid from address', function (): void {
    config(['mail.from.address' => null]);

    $this->artisan('app:doctor')
        ->expectsOutputToContain('MAIL_FROM_ADDRESS is missing')
        ->assertExitCode(1);
});

it('fails in production when mail is never delivered', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    config(['queue.default' => 'database', 'mail.default' => 'log']);

    $this->artisan('app:doctor')
        ->expectsOutputToContain('which never delivers')
        ->assertExitCode(1);
});

it('fails when the smtp host is missing', function (): void {
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '']);

    $this->artisan('app:doctor')
        ->expectsOutputToContain('MAIL_HOST and MAIL_PORT must be set')
        ->assertExitCode(1);
});
