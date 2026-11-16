import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { Button, Card, Container } from 'react-bootstrap';
import CheckboxGroup from '@/Components/Form/CheckboxGroup';
import ErrorSummary from '@/Components/Form/ErrorSummary';
import SelectField, { type SelectOption } from '@/Components/Form/SelectField';
import SubmitButton from '@/Components/Form/SubmitButton';
import TextAreaField from '@/Components/Form/TextAreaField';
import TextField from '@/Components/Form/TextField';
import { useFocusOnError } from '@/Components/Form/useFocusOnError';
import { usePlaceList, type PlaceList } from '@/Components/Form/usePlaceList';
import PageHeader from '@/Components/PageHeader';
import LandlordLayout from '@/Layouts/LandlordLayout';

interface Place {
    id: number;
    name: string;
    slug: string;
}

interface BarangayPlace {
    id: number;
    name: string;
    /** Manila only: the district its numbered barangays are known by. */
    district: string | null;
}

interface FormProps {
    property: {
        id: number;
        kind: string;
        name: string;
        slug: string;
        address: string | null;
        province_id: number | null;
        city_id: number | null;
        barangay_id: number | null;
        /** What a property from before slice 4.4 says, when it isn't linked to the list yet. */
        barangay_text: string | null;
        city_text: string | null;
        gender_policy: string;
        amenities: number[];
        house_rules: string | null;
        payment_instructions: string | null;
    } | null;
    /** The property has been published: its listing link, and so its city, are locked. */
    locked: boolean;
    provinces: Place[];
    /** The property's province's cities and its city's barangays; empty for a new property. */
    cities: Place[];
    barangays: BarangayPlace[];
    amenities: { id: number; name: string }[];
    kinds: string[];
    genderPolicies: string[];
}

const kindLabels: Record<string, string> = { apartment: 'Apartment', boarding_house: 'Boarding house' };
const genderLabels: Record<string, string> = { any: 'Anyone', male_only: 'Men only', female_only: 'Women only' };

const FIELD_ORDER = ['name', 'kind', 'gender_policy', 'address', 'barangay_id', 'city_id', 'amenities', 'house_rules', 'payment_instructions'];

const citiesUrl = (province: Place) => `/places/provinces/${province.slug}/cities`;
const barangaysUrl = (city: Place) => `/places/cities/${city.slug}/barangays`;

/**
 * Create or edit a property (owners; slice 4.1). The address is entered the way it is written in the
 * Philippines: street address (optional), barangay, city, province (slice 4.4: three dropdowns from the
 * PSGC list, each needing the one below it, so each says so while it waits). The form posts `city_id`
 * and `barangay_id`; the province is only there to narrow the cities. The listing link is made from
 * the name; it isn't a field.
 */
export default function Form({ property, locked, provinces, cities, barangays, amenities, kinds, genderPolicies }: FormProps) {
    const editing = property !== null;
    const [provinceId, setProvinceId] = useState(property?.province_id ? String(property.province_id) : '');
    const form = useForm({
        kind: property?.kind ?? 'boarding_house',
        name: property?.name ?? '',
        address: property?.address ?? '',
        city_id: property?.city_id ? String(property.city_id) : '',
        barangay_id: property?.barangay_id ? String(property.barangay_id) : '',
        gender_policy: property?.gender_policy ?? 'any',
        amenities: property?.amenities ?? ([] as number[]),
        house_rules: property?.house_rules ?? '',
        payment_instructions: property?.payment_instructions ?? '',
    });
    const { ref: summaryRef, onError } = useFocusOnError();
    const backHref = editing ? `/landlord/properties/${property.id}` : '/landlord/properties';

    // The lists the page came with, so an existing property opens filled without a request.
    const startProvince = provinces.find((place) => place.id === property?.province_id);
    const startCity = cities.find((place) => place.id === property?.city_id);
    const province = provinces.find((place) => String(place.id) === provinceId);
    const cityList = usePlaceList<Place>(province ? citiesUrl(province) : null, startProvince ? { [citiesUrl(startProvince)]: cities } : {});
    const city = cityList.items.find((place) => String(place.id) === form.data.city_id);
    const barangayList = usePlaceList<BarangayPlace>(city ? barangaysUrl(city) : null, startCity ? { [barangaysUrl(startCity)]: barangays } : {});
    // Published: the city is in the listing URL, so it and its province stay. A property from before
    // the list (no city yet) can still choose one; the server only accepts its own city.
    const cityLocked = locked && property?.city_id != null;

    const chooseProvince = (value: string) => {
        setProvinceId(value);
        form.setData((data) => ({ ...data, city_id: '', barangay_id: '' }));
    };
    const chooseCity = (value: string) => form.setData((data) => ({ ...data, city_id: value, barangay_id: '' }));

    // A province picked on the server-rendered page before the scripts loaded (a slow phone): adopt it,
    // or the city would stay "Choose a province first." under a province that shows a choice.
    useEffect(() => {
        const picked = (document.getElementById('field-province_id') as HTMLSelectElement | null)?.value ?? '';
        if (picked !== '' && picked !== provinceId) {
            chooseProvince(picked);
        }
        // Once, on mount: afterwards the select's onChange keeps the state.
    }, []);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onError };

        if (editing) {
            form.put(`/landlord/properties/${property.id}`, options);
        } else {
            form.post('/landlord/properties', options);
        }
    };

    return (
        <LandlordLayout>
            <Head title={editing ? `Edit ${property.name}` : 'Add a property'} />
            <Container className="py-4 py-md-5">
                <PageHeader
                    title={editing ? 'Edit property' : 'Add a property'}
                    back={{ href: backHref, label: editing ? `Back to ${property.name}` : 'Back to properties' }}
                />

                <form noValidate onSubmit={submit} className="property-form">
                    <ErrorSummary ref={summaryRef} errors={form.errors} order={FIELD_ORDER} />

                    <Card>
                        <section className="form-section" aria-labelledby="section-basics">
                            <div>
                            <h2 id="section-basics" className="h3 form-section__title">
                                The basics
                            </h2>
                            <p className="form-section__intro">What renters see first on your listing.</p>
                            </div>
                            <div className="form-section__fields">

                            <TextField
                                name="name"
                                label="Property name"
                                value={form.data.name}
                                onChange={(value) => form.setData('name', value)}
                                error={form.errors.name}
                                placeholder="Dela Cruz Bedspace House"
                                required
                            />

                            <div className="row">
                                <div className="col-sm-6">
                                    <SelectField
                                        name="kind"
                                        label="Type of property"
                                        value={form.data.kind}
                                        onChange={(value) => form.setData('kind', value)}
                                        options={kinds.map((kind) => ({ value: kind, label: kindLabels[kind] ?? kind }))}
                                        error={form.errors.kind}
                                        required
                                    />
                                </div>
                                <div className="col-sm-6">
                                    <SelectField
                                        name="gender_policy"
                                        label="Who can stay"
                                        value={form.data.gender_policy}
                                        onChange={(value) => form.setData('gender_policy', value)}
                                        options={genderPolicies.map((policy) => ({ value: policy, label: genderLabels[policy] ?? policy }))}
                                        error={form.errors.gender_policy}
                                        help="Shown on your listing, so renters know before they reserve."
                                        required
                                    />
                                </div>
                            </div>
                        </div>
                        </section>

                        <section className="form-section" aria-labelledby="section-address">
                            <div>
                            <h2 id="section-address" className="h3 form-section__title">
                                Address
                            </h2>
                            <p className="form-section__intro">Where the property is. Renters see this on your listing.</p>
                            </div>
                            <div className="form-section__fields">

                            <TextField
                                name="address"
                                label="Street address"
                                value={form.data.address}
                                onChange={(value) => form.setData('address', value)}
                                error={form.errors.address}
                                autoComplete="address-line1"
                                placeholder="12 Mabini Street"
                                help="House or building number and street."
                                optional
                            />

                            <PlaceSelect
                                name="barangay_id"
                                label="Barangay"
                                list={barangayList}
                                value={form.data.barangay_id}
                                onChange={(value) => form.setData('barangay_id', value)}
                                options={barangayList.items.map((place) => ({ value: String(place.id), label: place.name, group: place.district }))}
                                error={form.errors.barangay_id}
                                waiting="Choose a city first."
                                noun="barangays"
                                hint={property?.barangay_text ? `You wrote: ${property.barangay_text}` : undefined}
                            />

                            <div className="row">
                                <div className="col-sm-6">
                                    <PlaceSelect
                                        name="city_id"
                                        label="City or municipality"
                                        list={cityList}
                                        value={form.data.city_id}
                                        onChange={chooseCity}
                                        options={cityList.items.map((place) => ({ value: String(place.id), label: place.name }))}
                                        error={form.errors.city_id}
                                        waiting="Choose a province first."
                                        noun="cities and municipalities"
                                        hint={
                                            cityLocked
                                                ? "The city can't be changed after the property has been published."
                                                : property?.city_text
                                                  ? `You wrote: ${property.city_text}`
                                                  : undefined
                                        }
                                        disabled={cityLocked}
                                    />
                                </div>
                                <div className="col-sm-6">
                                    <SelectField
                                        name="province_id"
                                        label="Province"
                                        value={provinceId}
                                        onChange={chooseProvince}
                                        options={provinces.map((place) => ({ value: String(place.id), label: place.name }))}
                                        placeholder="Choose a province"
                                        help={cityLocked ? 'Follows the city.' : 'Metro Manila for Manila, Quezon City and the other cities around them.'}
                                        disabled={cityLocked}
                                        required
                                    />
                                </div>
                            </div>
                        </div>
                        </section>

                        <section className="form-section" aria-labelledby="section-renters">
                            <div>
                            <h2 id="section-renters" className="h3 form-section__title">
                                For renters
                            </h2>
                            <p className="form-section__intro">What the place offers, its rules, and how to pay you.</p>
                            </div>
                            <div className="form-section__fields">

                            <CheckboxGroup
                                name="amenities"
                                legend="Amenities"
                                options={amenities}
                                value={form.data.amenities}
                                onChange={(value) => form.setData('amenities', value)}
                            />

                            <TextAreaField
                                name="house_rules"
                                label="House rules"
                                value={form.data.house_rules}
                                onChange={(value) => form.setData('house_rules', value)}
                                error={form.errors.house_rules}
                                help="Say whether the reservation fee can be refunded, and when. Renters read this before they reserve."
                                placeholder="The reservation fee is refundable if the landlord declines. Quiet hours 10 PM to 6 AM."
                                optional
                            />

                            <TextAreaField
                                name="payment_instructions"
                                label="How to pay the reservation fee"
                                value={form.data.payment_instructions}
                                onChange={(value) => form.setData('payment_instructions', value)}
                                error={form.errors.payment_instructions}
                                help="Where renters send the fee after they reserve. You can add this later, but you need it before you publish."
                                placeholder="GCash 0917 123 4567 (J. Santos) or BPI 1234-5678-90"
                                rows={3}
                                optional
                            />
                        </div>
                        </section>

                        <div className="form-actions">
                            <Link href={backHref} className="btn btn-outline-primary">
                                Cancel
                            </Link>
                            <SubmitButton processing={form.processing} pendingLabel="Saving…" className="">
                                {editing ? 'Save changes' : 'Save as a draft'}
                            </SubmitButton>
                        </div>
                    </Card>
                </form>
            </Container>
        </LandlordLayout>
    );
}

interface PlaceSelectProps {
    name: string;
    label: string;
    list: PlaceList<unknown>;
    value: string;
    onChange: (value: string) => void;
    options: SelectOption[];
    error?: string;
    /** Why the dropdown is empty while the one it needs has no choice: "Choose a city first." */
    waiting: string;
    /** For the loading and error messages: "barangays". */
    noun: string;
    hint?: ReactNode;
    disabled?: boolean;
}

/** A dependent dropdown: disabled and saying why until it can be filled, then loading, a list, or an error with a retry. */
function PlaceSelect({ name, label, list, value, onChange, options, error, waiting, noun, hint, disabled = false }: PlaceSelectProps) {
    const placeholder = {
        idle: waiting,
        loading: `Loading ${noun}…`,
        ready: `Choose the ${label.toLowerCase()}`,
        error: `Couldn't load the ${noun}`,
    }[list.status];

    return (
        <>
            <SelectField
                name={name}
                label={label}
                value={value}
                onChange={onChange}
                options={options}
                placeholder={placeholder}
                error={error}
                help={hint}
                disabled={disabled || list.status !== 'ready'}
                className={list.status === 'error' ? 'mb-2' : 'mb-3'}
                required
            />
            {list.status === 'error' && (
                <div className="place-error mb-3" role="alert">
                    <span>We couldn&apos;t load the {noun}. Check your connection, then try again.</span>
                    <Button variant="outline-primary" onClick={list.retry}>
                        Try again
                    </Button>
                </div>
            )}
        </>
    );
}
