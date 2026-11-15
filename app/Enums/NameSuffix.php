<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Generational suffix of a person's name (`users.suffix`, nullable). A closed
 * list, so "Jr", "Jr.", "junior" and "II" don't become four spellings of the
 * same thing. Mirrors the `users_suffix_valid` CHECK.
 */
enum NameSuffix: string
{
    case Jr = 'jr';
    case Sr = 'sr';
    case Ii = 'ii';
    case Iii = 'iii';
    case Iv = 'iv';
    case V = 'v';

    /** How it is written after the last name. */
    public function label(): string
    {
        return match ($this) {
            self::Jr => 'Jr.',
            self::Sr => 'Sr.',
            self::Ii => 'II',
            self::Iii => 'III',
            self::Iv => 'IV',
            self::V => 'V',
        };
    }
}
