<?php

declare(strict_types=1);

namespace App\Support\Search;

use App\Actions\ReserveSpace;
use App\Enums\GenderPolicy;
use App\Enums\PropertyKind;
use App\Models\Amenity;
use App\Models\Barangay;
use App\Models\City;
use App\Models\Province;
use App\Support\BusinessDate;
use App\Support\Listings\Stay;
use App\Support\Pesos;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The search form as the query string `/listings?city=quezon-city&max_price=8000&move_in=2027-04-01`.
 * Every value is checked here and a bad one is dropped (never an error), so a stale or hand-edited link
 * still shows results. `toQuery()` is the clean form of what was understood: the pager and the form
 * use it, so garbage never travels on.
 */
final readonly class SearchFilters
{
    public const string SORT_PRICE = 'price';

    public const string SORT_NEWEST = 'newest';

    /** "Available for" when a date is chosen without a length: a month is the least anyone books. */
    public const int DEFAULT_MONTHS = 1;

    /**
     * @param  list<string>  $amenities  slugs of amenities that exist
     */
    public function __construct(
        public ?Province $province = null,
        public ?City $city = null,
        public ?Barangay $barangay = null,
        public ?int $minRate = null,
        public ?int $maxRate = null,
        public ?PropertyKind $kind = null,
        public ?string $gender = null,
        public array $amenities = [],
        public ?CarbonImmutable $moveIn = null,
        public int $months = self::DEFAULT_MONTHS,
        public string $sort = self::SORT_PRICE,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $city = self::slug($request->query('city'));
        $city = $city === null ? null : City::query()->where('slug', $city)->first();

        $province = $city?->province;
        if ($province === null) {
            $slug = self::slug($request->query('province'));
            $province = $slug === null ? null : Province::query()->where('slug', $slug)->first();
        }

        $barangayId = filter_var($request->query('barangay'), FILTER_VALIDATE_INT);
        $barangay = $city !== null && is_int($barangayId)
            ? Barangay::query()->where('city_id', $city->id)->whereKey($barangayId)->first()
            : null;

        $min = self::centavos($request->query('min_price'));
        $max = self::centavos($request->query('max_price'));
        if ($min !== null && $max !== null && $max < $min) {
            $max = null;
        }

        $kind = is_string($request->query('kind')) ? PropertyKind::tryFrom($request->query('kind')) : null;
        $gender = in_array($request->query('gender'), ['female', 'male'], true) ? (string) $request->query('gender') : null;

        // A date outside the booking window can't be reserved, so it can't filter either.
        $moveIn = Stay::moveIn($request->query('move_in'));
        $today = BusinessDate::today();
        if ($moveIn !== null && ($moveIn->lt($today) || $moveIn->gt($today->addDays(ReserveSpace::MOVE_IN_WINDOW_DAYS)))) {
            $moveIn = null;
        }

        return new self(
            province: $province,
            city: $city,
            barangay: $barangay,
            minRate: $min,
            maxRate: $max,
            kind: $kind,
            gender: $gender,
            amenities: self::amenities($request->query('amenities')),
            moveIn: $moveIn,
            months: Stay::months($request->query('months'), self::DEFAULT_MONTHS),
            sort: $request->query('sort') === self::SORT_NEWEST ? self::SORT_NEWEST : self::SORT_PRICE,
        );
    }

    /** The genders a "for women" / "for men" search accepts: that policy, and places open to everyone. */
    /** @return list<GenderPolicy>|null */
    public function policies(): ?array
    {
        return match ($this->gender) {
            'female' => [GenderPolicy::Any, GenderPolicy::FemaleOnly],
            'male' => [GenderPolicy::Any, GenderPolicy::MaleOnly],
            default => null,
        };
    }

    public function moveOut(): ?CarbonImmutable
    {
        return $this->moveIn?->addMonthsNoOverflow($this->months);
    }

    /** True when something narrows the results (sorting doesn't). */
    public function isFiltered(): bool
    {
        return $this->toQuery(withSort: false) !== [];
    }

    /**
     * What was understood, as query parameters in a fixed order. Prices go back as whole pesos.
     *
     * @return array<string, string|list<string>>
     */
    public function toQuery(bool $withSort = true): array
    {
        $query = array_filter([
            'province' => $this->province?->slug,
            'city' => $this->city?->slug,
            'barangay' => $this->barangay === null ? null : (string) $this->barangay->id,
            'min_price' => $this->minRate === null ? null : (string) intdiv($this->minRate, 100),
            'max_price' => $this->maxRate === null ? null : (string) intdiv($this->maxRate, 100),
            'kind' => $this->kind?->value,
            'gender' => $this->gender,
            'amenities' => $this->amenities === [] ? null : $this->amenities,
            'move_in' => $this->moveIn?->toDateString(),
            'months' => $this->moveIn === null || $this->months === self::DEFAULT_MONTHS ? null : (string) $this->months,
        ], fn (mixed $value): bool => $value !== null);

        if ($withSort && $this->sort !== self::SORT_PRICE) {
            $query['sort'] = $this->sort;
        }

        return $query;
    }

    private static function slug(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-z0-9-]{1,120}$/', $value) === 1 ? $value : null;
    }

    private static function centavos(mixed $value): ?int
    {
        if (! is_string($value) || ! Pesos::matches($value)) {
            return null;
        }

        $centavos = Pesos::toCentavos($value);

        return $centavos <= Pesos::MAX_CENTAVOS ? $centavos : null;
    }

    /**
     * @return list<string>
     */
    private static function amenities(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $slugs = array_values(array_unique(array_filter($value, fn (mixed $slug): bool => is_string($slug) && self::slug($slug) !== null)));
        if ($slugs === []) {
            return [];
        }

        /** @var list<string> $known */
        $known = Amenity::query()->whereIn('slug', $slugs)->orderBy('slug')->pluck('slug')->all();

        return $known;
    }
}
