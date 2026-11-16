<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\Isolation\CrossAccountWrite;
use App\Models\AuditLog;
use App\Models\LandlordAccount;
use App\Models\User;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use UnitEnum;

/**
 * Writes `audit_logs` rows (docs/CONVENTIONS.md "Audit log"). Every
 * landlord-side action records exactly one row per change it makes, inside
 * its own transaction, so a rolled-back action leaves no row.
 *
 * - `action` is `subject.verb` in snake_case: `room.rate_changed`,
 *   `invitation.created`, `account.verification_revoked`.
 * - `actor_id` is the logged-in user; null for the operator and the system
 *   (console commands, jobs).
 * - `landlord_account_id` is the current landlord account; with no landlord
 *   user, the subject's account (or the subject itself, for an account).
 * - Only the fields that changed are stored, and secrets never are: a changed
 *   secret is stored as REDACTED, so the row says it changed but not to what.
 */
final class AuditLogger
{
    /** Never stored, on any model. Compared case-insensitively. */
    public const array SECRET_FIELDS = ['password', 'remember_token', 'token', 'token_hash'];

    public const string REDACTED = '[redacted]';

    private const string ACTION_PATTERN = '/^[a-z]+(_[a-z]+)*\.[a-z]+(_[a-z]+)*$/';

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly CurrentLandlordAccount $current,
    ) {}

    /**
     * @param  array<string, mixed>  $before  the subject's fields before the change (extra unchanged fields are fine)
     * @param  array<string, mixed>  $after  the same fields after it
     * @param  User|null  $actor  only when the actor isn't logged in yet: a person accepting an invitation by signing up
     * @return AuditLog|null null when nothing changed: no change, no row
     */
    public function record(string $action, Model $subject, array $before, array $after, ?User $actor = null): ?AuditLog
    {
        if (preg_match(self::ACTION_PATTERN, $action) !== 1) {
            throw new InvalidArgumentException("Audit action [{$action}] must be subject.verb in snake_case, like room.rate_changed.");
        }

        [$before, $after] = $this->changes($before, $after);

        if ($before === [] && $after === []) {
            return null;
        }

        $subjectId = $subject->getKey();

        if (! is_int($subjectId)) {
            throw new InvalidArgumentException('Audit subjects must be saved models with an integer key.');
        }

        return AuditLog::create([
            'actor_id' => $actor === null ? $this->actorId() : $actor->id,
            'landlord_account_id' => $this->accountId($subject),
            'action' => $action,
            'subject_type' => Str::snake(class_basename($subject)),
            'subject_id' => $subjectId,
            'before' => $before,
            'after' => $after,
        ]);
    }

    /**
     * The fields whose values differ, each side normalized for JSON, with
     * secrets replaced.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    private function changes(array $before, array $after): array
    {
        $changedBefore = [];
        $changedAfter = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $field) {
            $old = array_key_exists($field, $before) ? self::normalize($before[$field]) : null;
            $new = array_key_exists($field, $after) ? self::normalize($after[$field]) : null;

            if ($old === $new && array_key_exists($field, $before) === array_key_exists($field, $after)) {
                continue;
            }

            if (self::isSecret($field)) {
                $old = $old === null ? null : self::REDACTED;
                $new = $new === null ? null : self::REDACTED;
            }

            if (array_key_exists($field, $before)) {
                $changedBefore[$field] = $old;
            }

            if (array_key_exists($field, $after)) {
                $changedAfter[$field] = $new;
            }
        }

        return [$changedBefore, $changedAfter];
    }

    private static function isSecret(string $field): bool
    {
        return in_array(strtolower($field), self::SECRET_FIELDS, true);
    }

    private static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            default => $value,
        };
    }

    private function actorId(): ?int
    {
        $user = $this->auth->guard()->user();

        return $user instanceof User ? $user->id : null;
    }

    private function accountId(Model $subject): int
    {
        $subjectAccount = $subject instanceof LandlordAccount ? $subject->id : $subject->getAttribute('landlord_account_id');
        $subjectAccount = is_numeric($subjectAccount) ? (int) $subjectAccount : null;

        if (! $this->current->applies()) {
            return $subjectAccount ?? throw new InvalidArgumentException('Audit subjects must belong to a landlord account.');
        }

        $currentId = $this->current->idOrNull();

        if ($currentId === null || ($subjectAccount !== null && $subjectAccount !== $currentId)) {
            throw CrossAccountWrite::for($subject, $subjectAccount, $currentId);
        }

        return $currentId;
    }
}
