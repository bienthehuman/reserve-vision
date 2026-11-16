import { router } from '@inertiajs/react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Button, Form, Offcanvas } from 'react-bootstrap';
import { Sliders } from 'react-bootstrap-icons';
import ComboboxField from '@/Components/Form/ComboboxField';
import { usePlaceList, type PlaceList } from '@/Components/Form/usePlaceList';
import { kindLabel, type PropertyKind } from '@/lib/labels';

export interface SearchValues {
    province: string;
    city: string;
    barangay: string;
    min_price: string;
    max_price: string;
    kind: string;
    gender: string;
    amenities: string[];
    move_in: string;
    months: string;
    sort: string;
}

export interface SearchOptions {
    provinces: { id: number; name: string; slug: string }[];
    cities: { id: number; name: string; slug: string }[];
    barangays: { id: number; name: string; district: string | null }[];
    amenities: { value: string; label: string }[];
    kinds: PropertyKind[];
}

interface FilterSheetProps {
    values: SearchValues;
    options: SearchOptions;
    window: { first: string; last: string };
    total: number;
    /** How many filters are set, for the phone's button. */
    active: number;
}

const COUNT_DELAY_MS = 300;

/** The query string of the values that are set: what the form submits and the count asks about. */
function queryOf(values: SearchValues): string {
    const query = new URLSearchParams();
    const set = (name: string, value: string) => value !== '' && query.set(name, value);
    set('province', values.province);
    set('city', values.city);
    set('barangay', values.barangay);
    set('min_price', values.min_price);
    set('max_price', values.max_price);
    set('kind', values.kind);
    set('gender', values.gender);
    values.amenities.forEach((slug) => query.append('amenities[]', slug));
    if (values.move_in !== '') {
        query.set('move_in', values.move_in);
        set('months', values.months);
    }
    if (values.sort !== 'price') {
        query.set('sort', values.sort);
    }

    return query.toString();
}

/**
 * The search filters (slice 5.2): a bottom sheet on phones, a sidebar from `lg` (React-Bootstrap's
 * responsive Offcanvas). Without JavaScript (and until the page hydrates) it is a plain GET form on the page, with
 * native selects for the place; once hydrated, the place fields become comboboxes you can type into,
 * and the button says how many places the filters match.
 */
export default function FilterSheet({ values: initial, options, window: bookingWindow, total, active }: FilterSheetProps) {
    const [enhanced, setEnhanced] = useState(false);
    const [open, setOpen] = useState(false);
    const [values, setValues] = useState(initial);
    const [count, setCount] = useState<number | null>(total);
    const firstRun = useRef(true);

    useEffect(() => setEnhanced(true), []);

    // The count behind the button: ask the server after a pause, ignore answers that arrive late.
    useEffect(() => {
        if (firstRun.current) {
            firstRun.current = false;

            return;
        }
        const controller = new AbortController();
        const timer = setTimeout(() => {
            fetch(`/listings/count?${queryOf(values)}`, { headers: { Accept: 'application/json' }, signal: controller.signal })
                .then((response) => (response.ok ? (response.json() as Promise<{ total: number }>) : Promise.reject(new Error('count'))))
                .then((body) => setCount(body.total))
                .catch(() => !controller.signal.aborted && setCount(null));
        }, COUNT_DELAY_MS);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [values]);

    const set = <K extends keyof SearchValues>(name: K, value: SearchValues[K]) => setValues((current) => ({ ...current, [name]: value }));

    const submit = (event: React.FormEvent) => {
        if (!enhanced) {
            return;
        }
        event.preventDefault();
        const query = queryOf(values);
        setOpen(false);
        router.get(`/listings${query === '' ? '' : `?${query}`}`);
    };

    const body = (
        <form action="/listings" method="get" onSubmit={submit} className="filter-form" aria-label="Search filters">
            <PlaceFields values={values} options={options} enhanced={enhanced} set={set} setValues={setValues} />

            <fieldset className="mb-3">
                <legend className="form-label fs-6 float-none">Monthly price (₱)</legend>
                <div className="filter-form__pair">
                    <Form.Control type="number" name="min_price" aria-label="Lowest monthly price in pesos" placeholder="From" min={0} step={100} inputMode="numeric" value={values.min_price} onChange={(e) => set('min_price', e.target.value)} />
                    <span aria-hidden="true">to</span>
                    <Form.Control type="number" name="max_price" aria-label="Highest monthly price in pesos" placeholder="To" min={0} step={100} inputMode="numeric" value={values.max_price} onChange={(e) => set('max_price', e.target.value)} />
                </div>
            </fieldset>

            <fieldset className="mb-3">
                <legend className="form-label fs-6 float-none">Available</legend>
                <Form.Group controlId="filter-move-in" className="mb-2">
                    <Form.Label className="small mb-1">From</Form.Label>
                    <Form.Control type="date" name="move_in" min={bookingWindow.first} max={bookingWindow.last} value={values.move_in} onChange={(e) => set('move_in', e.target.value)} />
                </Form.Group>
                <Form.Group controlId="filter-months">
                    <Form.Label className="small mb-1">For</Form.Label>
                    <Form.Select name="months" value={values.months} onChange={(e) => set('months', e.target.value)}>
                        {Array.from({ length: 12 }, (_, i) => i + 1).map((n) => (
                            <option key={n} value={n}>
                                {n === 1 ? '1 month' : `${n} months`}
                            </option>
                        ))}
                    </Form.Select>
                    <Form.Text className="d-block">Shows rooms with a bed free for the whole stay. Pick a date to use it.</Form.Text>
                </Form.Group>
            </fieldset>

            <Form.Group controlId="filter-kind" className="mb-3">
                <Form.Label>Kind of place</Form.Label>
                <Form.Select name="kind" value={values.kind} onChange={(e) => set('kind', e.target.value)}>
                    <option value="">Any</option>
                    {options.kinds.map((kind) => (
                        <option key={kind} value={kind}>
                            {kindLabel[kind]}
                        </option>
                    ))}
                </Form.Select>
            </Form.Group>

            <Form.Group controlId="filter-gender" className="mb-3">
                <Form.Label>Place for</Form.Label>
                <Form.Select name="gender" value={values.gender} onChange={(e) => set('gender', e.target.value)}>
                    <option value="">Anyone</option>
                    <option value="female">Women</option>
                    <option value="male">Men</option>
                </Form.Select>
                <Form.Text className="d-block">Women means women-only places and places open to everyone. Men works the same way.</Form.Text>
            </Form.Group>

            <fieldset className="mb-3">
                <legend className="form-label fs-6 float-none">Amenities</legend>
                {options.amenities.map((amenity) => (
                    <Form.Check
                        key={amenity.value}
                        type="checkbox"
                        id={`filter-amenity-${amenity.value}`}
                        name="amenities[]"
                        value={amenity.value}
                        label={amenity.label}
                        checked={values.amenities.includes(amenity.value)}
                        onChange={(e) => set('amenities', e.target.checked ? [...values.amenities, amenity.value] : values.amenities.filter((slug) => slug !== amenity.value))}
                    />
                ))}
                <Form.Text className="d-block">Places that have all the ones you tick.</Form.Text>
            </fieldset>

            <Form.Group controlId="filter-sort" className="mb-3">
                <Form.Label>Sort by</Form.Label>
                <Form.Select name="sort" value={values.sort} onChange={(e) => set('sort', e.target.value)}>
                    <option value="price">Price, low to high</option>
                    <option value="newest">Newest</option>
                </Form.Select>
            </Form.Group>

            <div className="button-row filter-form__actions">
                {active > 0 && (
                    <a href="/listings" className="btn btn-outline-primary">
                        Clear filters
                    </a>
                )}
                <Button type="submit" variant="primary">
                    {enhanced && count !== null ? (count === 1 ? 'Show 1 place' : `Show ${count} places`) : 'Show places'}
                </Button>
            </div>
        </form>
    );

    if (!enhanced) {
        return (
            <section className="filter-panel card card-body" aria-labelledby="filters-title">
                <h2 id="filters-title" className="h5">
                    Filters
                </h2>
                {body}
            </section>
        );
    }

    return (
        <>
            <Button variant="outline-primary" className="d-lg-none filter-toggle" onClick={() => setOpen(true)} aria-haspopup="dialog">
                <Sliders aria-hidden="true" focusable="false" />
                {active > 0 ? `Filters (${active})` : 'Filters'}
            </Button>
            <Offcanvas show={open} onHide={() => setOpen(false)} placement="bottom" responsive="lg" className="filter-sheet" aria-labelledby="filters-title">
                <Offcanvas.Header closeButton className="d-lg-none">
                    <Offcanvas.Title as="h2" id="filters-title" className="h5">
                        Filters
                    </Offcanvas.Title>
                </Offcanvas.Header>
                <Offcanvas.Body className="filter-panel">
                    <h2 className="h5 d-none d-lg-block">Filters</h2>
                    {body}
                </Offcanvas.Body>
            </Offcanvas>
        </>
    );
}

interface PlaceFieldsProps {
    values: SearchValues;
    options: SearchOptions;
    enhanced: boolean;
    set: <K extends keyof SearchValues>(name: K, value: SearchValues[K]) => void;
    setValues: (update: (current: SearchValues) => SearchValues) => void;
}

/** Province, city, barangay. Native selects with the lists the server sent until the page hydrates (without JavaScript: choose, press the button, and the next list appears); comboboxes after. */
function PlaceFields({ values, options, enhanced, set, setValues }: PlaceFieldsProps) {
    const cities = usePlaceList<{ id: number; name: string; slug: string }>(
        values.province === '' ? null : `/places/provinces/${values.province}/cities`,
        values.province === '' ? {} : { [`/places/provinces/${values.province}/cities`]: options.cities },
    );
    const barangays = usePlaceList<{ id: number; name: string; district: string | null }>(
        values.city === '' ? null : `/places/cities/${values.city}/barangays`,
        values.city === '' ? {} : { [`/places/cities/${values.city}/barangays`]: options.barangays },
    );

    if (!enhanced) {
        return (
            <>
                <NativeSelect id="filter-province" name="province" label="Province" value={values.province} empty="Any province" items={options.provinces.map((p) => ({ value: p.slug, label: p.name }))} />
                {values.province !== '' && (
                    <NativeSelect id="filter-city" name="city" label="City or municipality" value={values.city} empty="Any city" items={options.cities.map((c) => ({ value: c.slug, label: c.name }))} />
                )}
                {values.city !== '' && (
                    <NativeSelect id="filter-barangay" name="barangay" label="Barangay" value={values.barangay} empty="Any barangay" items={options.barangays.map((b) => ({ value: String(b.id), label: b.district ? `${b.name}, ${b.district}` : b.name }))} />
                )}
                <noscript>
                    <p className="small text-body-secondary">After choosing a province, press Show places to pick its city.</p>
                </noscript>
            </>
        );
    }

    return (
        <>
            <ComboboxField
                name="province_choice"
                label="Province"
                value={values.province}
                onChange={(slug) => setValues((current) => ({ ...current, province: slug, city: '', barangay: '' }))}
                options={options.provinces.map((p) => ({ value: p.slug, label: p.name }))}
                placeholder="Any province"
                className="mb-3"
            />
            <ComboboxField
                name="city_choice"
                label="City or municipality"
                value={values.city}
                onChange={(slug) => setValues((current) => ({ ...current, city: slug, barangay: '' }))}
                options={cities.items.map((c) => ({ value: c.slug, label: c.name }))}
                placeholder={placeholderOf(cities, 'Choose a province first.', 'Any city')}
                disabled={cities.status !== 'ready'}
                className="mb-3"
            />
            <ComboboxField
                name="barangay_choice"
                label="Barangay"
                value={values.barangay}
                onChange={(id) => set('barangay', id)}
                options={barangays.items.map((b) => ({ value: String(b.id), label: b.name, group: b.district }))}
                placeholder={placeholderOf(barangays, 'Choose a city first.', 'Any barangay')}
                disabled={barangays.status !== 'ready'}
                className="mb-3"
            />
        </>
    );
}

function placeholderOf(list: PlaceList<unknown>, waiting: string, ready: string): string {
    return { idle: waiting, loading: 'Loading…', ready, error: "Couldn't load the list" }[list.status];
}

function NativeSelect({ id, name, label, value, empty, items }: { id: string; name: string; label: ReactNode; value: string; empty: string; items: { value: string; label: string }[] }) {
    return (
        <Form.Group controlId={id} className="mb-3">
            <Form.Label>{label}</Form.Label>
            <Form.Select name={name} defaultValue={value}>
                <option value="">{empty}</option>
                {items.map((item) => (
                    <option key={item.value} value={item.value}>
                        {item.label}
                    </option>
                ))}
            </Form.Select>
        </Form.Group>
    );
}
