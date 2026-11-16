<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Photos\PhotoProcessor;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Checks that this environment can run ReserveVision. Run by deploy.sh after
 * every deploy and by the production CI workflow (ADR 0007); safe to run in any
 * environment, and it leaves nothing behind.
 *
 * Exit code 0 when every check passes, 1 otherwise.
 */
final class DoctorCommand extends Command
{
    /** MySQL and MariaDB: "<command> denied to user". */
    private const int ER_TABLEACCESS_DENIED = 1142;

    /** SIGNAL SQLSTATE '45000' (MySQL and MariaDB). */
    private const int ER_SIGNAL_EXCEPTION = 1644;

    protected $signature = 'app:doctor';

    protected $description = 'Check the database users, queue table, storage disks, image processing and mail configuration';

    public function handle(): int
    {
        $rows = [];
        $failed = 0;

        foreach ($this->checks() as $name => $check) {
            try {
                $rows[] = [$name, '<fg=green>pass</>', $check()];
            } catch (Throwable $e) {
                $failed++;
                $rows[] = [$name, '<fg=red;options=bold>FAIL</>', Str::limit($e->getMessage(), 200)];
            }
        }

        $this->table(['Check', 'Result', 'Detail'], $rows);

        if ($failed > 0) {
            $this->error("{$failed} of ".count($rows).' checks failed.');

            return self::FAILURE;
        }

        $this->info('All '.count($rows).' checks passed.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, Closure(): string>
     */
    private function checks(): array
    {
        return [
            'Database connection' => $this->databaseConnection(...),
            'App user has no DDL' => $this->appUserHasNoDdl(...),
            'Audit log is append-only' => $this->auditLogIsAppendOnly(...),
            'Queue table' => $this->queueTable(...),
            'Storage: photos' => fn (): string => $this->diskRoundTrip('photos'),
            'Storage: proofs' => fn (): string => $this->diskRoundTrip('proofs'),
            'Image processing' => $this->imageProcessing(...),
            'Mail configuration' => $this->mailConfiguration(...),
        ];
    }

    private function databaseConnection(): string
    {
        $expected = $this->configString('database.connections.'.$this->configString('database.default').'.username');

        /** @var object{user: string}|null $row */
        $row = DB::selectOne('SELECT CURRENT_USER() AS user');
        $user = $row->user ?? '';

        if (! str_starts_with($user, "{$expected}@")) {
            throw new RuntimeException("Connected as [{$user}], expected [{$expected}@…] (ADR 0008).");
        }

        return "{$user} on ".DB::getDatabaseName();
    }

    private function appUserHasNoDdl(): string
    {
        $table = 'doctor_ddl_probe_'.Str::lower(Str::random(8));

        try {
            DB::statement("CREATE TABLE `{$table}` (id INT PRIMARY KEY)");
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === self::ER_TABLEACCESS_DENIED) {
                return 'CREATE TABLE denied, as it should be';
            }

            throw $e;
        }

        DB::statement("DROP TABLE IF EXISTS `{$table}`");

        throw new RuntimeException('The app user can CREATE TABLE. Grant it only SELECT, INSERT, UPDATE, DELETE (database/sql/create-users.sql).');
    }

    /**
     * Inserts a probe row inside a transaction, tries to UPDATE it, and rolls
     * back: nothing is left behind. The triggers answer with SQLSTATE 45000
     * (driver code 1644).
     */
    private function auditLogIsAppendOnly(): string
    {
        DB::beginTransaction();

        try {
            DB::table('audit_logs')->insert(['action' => 'doctor.probe', 'subject_type' => 'doctor', 'subject_id' => 0]);

            try {
                DB::table('audit_logs')->where('action', 'doctor.probe')->update(['action' => 'doctor.edited']);
            } catch (QueryException $e) {
                if (($e->errorInfo[1] ?? null) === self::ER_SIGNAL_EXCEPTION) {
                    return 'UPDATE rejected by the trigger, as it should be';
                }

                throw $e;
            }
        } finally {
            DB::rollBack();
        }

        throw new RuntimeException('audit_logs rows can be UPDATEd. The triggers are missing: run the migrations with log_bin_trust_function_creators=1 (ADR 0008).');
    }

    private function queueTable(): string
    {
        $connection = $this->configString('queue.default');
        $driver = $this->configString("queue.connections.{$connection}.driver");

        if ($driver !== 'database') {
            if (app()->isProduction()) {
                throw new RuntimeException("QUEUE_CONNECTION is [{$connection}] ({$driver}); production uses the database queue.");
            }

            return "{$connection} ({$driver}), no table to check";
        }

        $table = $this->configString("queue.connections.{$connection}.table");
        $pending = DB::table($table)->count();
        $failed = DB::table($this->configString('queue.failed.table'))->count();

        return "{$table}: {$pending} pending, {$failed} failed";
    }

    private function diskRoundTrip(string $name): string
    {
        $disk = Storage::disk($name);
        $path = 'doctor/'.Str::uuid()->toString().'.txt';
        $contents = Str::random(32);

        try {
            if (! $disk->put($path, $contents)) {
                throw new RuntimeException("Could not write {$path}.");
            }

            if ($disk->get($path) !== $contents) {
                throw new RuntimeException("Read back different contents from {$path}.");
            }
        } finally {
            rescue(fn () => $disk->delete($path), report: false);
        }

        if ($disk->exists($path)) {
            throw new RuntimeException("Could not delete {$path}.");
        }

        $driver = $this->configString("filesystems.disks.{$name}.driver");
        $where = $driver === 's3'
            ? 'bucket '.$this->configString("filesystems.disks.{$name}.bucket")
            : $this->configString("filesystems.disks.{$name}.root");

        return "{$driver}, {$where}: write, read, delete";
    }

    /**
     * Property photos (slice 4.2): GD must decode JPEG and PNG, read EXIF, and encode WebP.
     * Runs a small JPEG through the same resize code as an upload.
     */
    private function imageProcessing(): string
    {
        foreach (['imagecreatefromstring', 'imagejpeg', 'imagewebp', 'exif_read_data'] as $function) {
            if (! function_exists($function)) {
                throw new RuntimeException("PHP has no {$function}(): install the gd extension (with JPEG and WebP) and exif.");
            }
        }

        $image = imagecreatetruecolor(960, 640);
        ob_start();
        $encoded = $image !== false && imagejpeg($image);
        $jpeg = (string) ob_get_clean();

        if (! $encoded) {
            throw new RuntimeException('GD could not encode a JPEG.');
        }

        $photo = app(PhotoProcessor::class)->process($jpeg);

        foreach ([$photo->thumb, $photo->full] as $webp) {
            if (! str_starts_with($webp, 'RIFF') || substr($webp, 8, 4) !== 'WEBP') {
                throw new RuntimeException('GD did not produce a WebP file.');
            }
        }

        return "gd: JPEG to WebP, {$photo->width} × {$photo->height} and a thumbnail";
    }

    private function mailConfiguration(): string
    {
        $mailer = $this->configString('mail.default');
        $transport = $this->configString("mail.mailers.{$mailer}.transport");
        $from = config('mail.from.address');

        if (! is_string($from) || ! filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('MAIL_FROM_ADDRESS is missing or not an email address.');
        }

        if ($transport === 'smtp') {
            $host = config("mail.mailers.{$mailer}.host");
            $port = config("mail.mailers.{$mailer}.port");

            if (! is_string($host) || $host === '' || ! is_numeric($port)) {
                throw new RuntimeException('MAIL_HOST and MAIL_PORT must be set for the smtp mailer.');
            }

            return "smtp {$host}:{$port}, from {$from}";
        }

        if (in_array($transport, ['log', 'array'], true) && app()->isProduction()) {
            throw new RuntimeException("MAIL_MAILER is [{$mailer}], which never delivers; set an SMTP provider (DEPLOY.md).");
        }

        return "{$transport}, from {$from}";
    }

    private function configString(string $key): string
    {
        $value = config($key);

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Config [{$key}] is not set.");
        }

        return $value;
    }
}
