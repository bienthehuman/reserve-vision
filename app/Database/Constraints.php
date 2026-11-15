<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * CHECK constraints for migrations. Laravel's schema builder has no helper for
 * them, so they are added with ALTER TABLE (MySQL >= 8.0.16, MariaDB >= 10.2).
 * Every constraint is named, so a violation reads `reservations_move_in_before_move_out`
 * instead of `reservations_chk_1`.
 *
 * Migrations are frozen history: they pass their enum values as literals rather
 * than reading the PHP enums, which may change later. A schema test compares the
 * two (tests/Feature/Schema/EnumChecksTest.php).
 */
final class Constraints
{
    public static function check(string $table, string $name, string $expression): void
    {
        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
    }

    /**
     * Restricts a VARCHAR column to a set of values, as a MySQL ENUM would, but
     * changeable with a plain migration. Named `<table>_<column>_valid`.
     *
     * @param  list<string>  $values
     */
    public static function in(string $table, string $column, array $values): void
    {
        $list = implode(', ', array_map(function (string $value): string {
            if (preg_match('/^[a-z_]+$/', $value) !== 1) {
                throw new InvalidArgumentException("Enum value [{$value}] must be lower snake case.");
            }

            return "'{$value}'";
        }, $values));

        self::check($table, "{$table}_{$column}_valid", "`{$column}` IN ({$list})");
    }
}
