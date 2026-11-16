<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/** Why a property can't be published (PublishProperty). The message lists what is still missing. */
final class CannotPublish extends BusinessRuleException
{
    /**
     * @param  list<string>  $missing  the checklist items that aren't done, as the property page words them
     */
    public static function missing(string $property, array $missing): self
    {
        return new self("{$property} can't be published yet. Still needed: ".implode('; ', $missing).'.');
    }
}
