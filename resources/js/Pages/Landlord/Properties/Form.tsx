import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Container } from 'react-bootstrap';
import CheckboxGroup from '@/Components/Form/CheckboxGroup';
import ErrorSummary from '@/Components/Form/ErrorSummary';
import SelectField from '@/Components/Form/SelectField';
import SubmitButton from '@/Components/Form/SubmitButton';
import TextAreaField from '@/Components/Form/TextAreaField';
import TextField from '@/Components/Form/TextField';
import { useFocusOnError } from '@/Components/Form/useFocusOnError';
import LandlordLayout from '@/Layouts/LandlordLayout';

interface Place {
    slug: string;
    city: string;
    lat: string;
    lng: string;
}

interface FormProps {
    property: {
        id: number;
        kind: string;
        name: string;
        slug: string;
        city: string;
        barangay: string | null;
        address: string;
        lat: string | null;
        lng: string | null;
        gender_policy: string;
        amenities: number[];
        house_rules: string | null;
        payment_instructions: string | null;
    } | null;
    /** The property has been published: its web address and city are locked. */
    locked: boolean;
    places: Place[];
    amenities: { id: number; name: string }[];
    kinds: string[];
    genderPolicies: string[];
}

const kindLabels: Record<string, string> = { apartment: 'Apartment', boarding_house: 'Boarding house' };
const genderLabels: Record<string, string> = { any: 'Anyone', male_only: 'Men only', female_only: 'Women only' };

const FIELD_ORDER = ['name', 'kind', 'city', 'barangay', 'address', 'lat', 'lng', 'slug', 'gender_policy', 'amenities', 'house_rules', 'payment_instructions'];

/** Create or edit a property (owners; slice 4.1). Coordinates start at the chosen city's centre. */
export default function Form({ property, locked, places, amenities, kinds, genderPolicies }: FormProps) {
    const editing = property !== null;
    const form = useForm({
        kind: property?.kind ?? 'boarding_house',
        name: property?.name ?? '',
        slug: editing && !locked ? property.slug : '',
        city: property?.city ?? '',
        barangay: property?.barangay ?? '',
        address: property?.address ?? '',
        lat: property?.lat ?? '',
        lng: property?.lng ?? '',
        gender_policy: property?.gender_policy ?? 'any',
        amenities: property?.amenities ?? ([] as number[]),
        house_rules: property?.house_rules ?? '',
        payment_instructions: property?.payment_instructions ?? '',
    });
    const { ref: summaryRef, onError } = useFocusOnError();

    const centreOf = (slug: string): Place | undefined => places.find((place) => place.slug === slug);

    /** Choosing a city moves the coordinates to its centre, unless the owner has typed their own. */
    const chooseCity = (slug: string) => {
        const previous = centreOf(form.data.city);
        const untouched =
            (form.data.lat === '' && form.data.lng === '') || (previous && form.data.lat === previous.lat && form.data.lng === previous.lng);
        const next = centreOf(slug);

        form.setData((data) => ({
            ...data,
            city: slug,
            ...(untouched && next ? { lat: next.lat, lng: next.lng } : {}),
        }));
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onError };

        if (editing) {
            form.put(`/landlord/properties/${property.id}`, options);
        } else {
            form.post('/landlord/properties', options);
        }
    };

    const city = centreOf(form.data.city);

    return (
        <LandlordLayout>
            <Head title={editing ? `Edit ${property.name}` : 'Add a property'} />
            <Container className="py-4 py-md-5">
                <p className="mb-2">
                    <Link href={editing ? `/landlord/properties/${property.id}` : '/landlord/properties'}>
                        {editing ? 'Back to the property' : 'Back to properties'}
                    </Link>
                </p>
                <h1 className="h2 mb-4">{editing ? 'Edit property' : 'Add a property'}</h1>

                <form noValidate onSubmit={submit} className="property-form">
                    <ErrorSummary ref={summaryRef} errors={form.errors} order={FIELD_ORDER} />

                    <div className="row">
                        <div className="col-sm-6">
                            <SelectField
                                name="kind"
                                label="Type"
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
                                label="Who may stay"
                                value={form.data.gender_policy}
                                onChange={(value) => form.setData('gender_policy', value)}
                                options={genderPolicies.map((policy) => ({ value: policy, label: genderLabels[policy] ?? policy }))}
                                error={form.errors.gender_policy}
                                help="Shown on the listing. The system doesn't enforce it: you decline a reservation that doesn't fit."
                                required
                            />
                        </div>
                    </div>

                    <TextField
                        name="name"
                        label="Name"
                        value={form.data.name}
                        onChange={(value) => form.setData('name', value)}
                        error={form.errors.name}
                        placeholder="Dela Cruz Bedspace House"
                        required
                    />

                    <div className="row">
                        <div className="col-sm-6">
                            <SelectField
                                name="city"
                                label="City"
                                value={form.data.city}
                                onChange={chooseCity}
                                options={places.map((place) => ({ value: place.slug, label: place.city }))}
                                placeholder="Choose a city"
                                error={form.errors.city}
                                help={locked ? "The city can't change once the property has been published: it is part of its web address." : undefined}
                                disabled={locked}
                                required
                            />
                        </div>
                        <div className="col-sm-6">
                            <TextField
                                name="barangay"
                                label="Barangay"
                                value={form.data.barangay}
                                onChange={(value) => form.setData('barangay', value)}
                                error={form.errors.barangay}
                                optional
                            />
                        </div>
                    </div>

                    <TextField
                        name="address"
                        label="Street address"
                        value={form.data.address}
                        onChange={(value) => form.setData('address', value)}
                        error={form.errors.address}
                        autoComplete="street-address"
                        required
                    />

                    <div className="row">
                        <div className="col-sm-6">
                            <TextField
                                name="lat"
                                label="Latitude"
                                inputMode="decimal"
                                value={form.data.lat}
                                onChange={(value) => form.setData('lat', value)}
                                error={form.errors.lat}
                                help={city ? `Starts at the centre of ${city.city}.` : 'Starts at the centre of the city you choose.'}
                                optional
                            />
                        </div>
                        <div className="col-sm-6">
                            <TextField
                                name="lng"
                                label="Longitude"
                                inputMode="decimal"
                                value={form.data.lng}
                                onChange={(value) => form.setData('lng', value)}
                                error={form.errors.lng}
                                help="Numbers like 14.604200 and 120.982200. A map to pick the spot comes later."
                                optional
                            />
                        </div>
                    </div>

                    {locked ? (
                        <p className="mb-3">
                            <span className="fw-semibold">Web address:</span> <code>/listings/{property?.city}/{property?.slug}</code>
                            <span className="d-block form-text">It can't change once the property has been published, so links to it keep working.</span>
                        </p>
                    ) : (
                        <TextField
                            name="slug"
                            label="Web address"
                            value={form.data.slug}
                            onChange={(value) => form.setData('slug', value)}
                            error={form.errors.slug}
                            help="The last part of the public link, for example dela-cruz-bedspace-house. Leave it empty to make it from the name. You can change it until the property is first published."
                            optional
                        />
                    )}

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
                        help="Include whether the reservation fee is refundable, and when: renters see this before they reserve."
                        placeholder="The reservation fee is refundable if the landlord declines. Quiet hours 10 PM to 6 AM."
                        optional
                    />

                    <TextAreaField
                        name="payment_instructions"
                        label="How to pay the reservation fee"
                        value={form.data.payment_instructions}
                        onChange={(value) => form.setData('payment_instructions', value)}
                        error={form.errors.payment_instructions}
                        help="Shown to a renter when they reserve: where to send the fee. You need it to publish."
                        placeholder="GCash 0917 123 4567 (J. Santos) or BPI 1234-5678-90"
                        rows={3}
                        optional
                    />

                    <SubmitButton processing={form.processing} pendingLabel="Saving…" className="">
                        {editing ? 'Save property' : 'Save as a draft'}
                    </SubmitButton>
                </form>
            </Container>
        </LandlordLayout>
    );
}
