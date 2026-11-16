import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Card, Container } from 'react-bootstrap';
import CheckboxGroup from '@/Components/Form/CheckboxGroup';
import ErrorSummary from '@/Components/Form/ErrorSummary';
import SelectField from '@/Components/Form/SelectField';
import SubmitButton from '@/Components/Form/SubmitButton';
import TextAreaField from '@/Components/Form/TextAreaField';
import TextField from '@/Components/Form/TextField';
import { useFocusOnError } from '@/Components/Form/useFocusOnError';
import PageHeader from '@/Components/PageHeader';
import LandlordLayout from '@/Layouts/LandlordLayout';

interface Place {
    slug: string;
    city: string;
    province: string;
}

interface FormProps {
    property: {
        id: number;
        kind: string;
        name: string;
        slug: string;
        address: string | null;
        barangay: string;
        city: string;
        gender_policy: string;
        amenities: number[];
        house_rules: string | null;
        payment_instructions: string | null;
    } | null;
    /** The property has been published: its listing link, and so its city, are locked. */
    locked: boolean;
    places: Place[];
    amenities: { id: number; name: string }[];
    kinds: string[];
    genderPolicies: string[];
}

const kindLabels: Record<string, string> = { apartment: 'Apartment', boarding_house: 'Boarding house' };
const genderLabels: Record<string, string> = { any: 'Anyone', male_only: 'Men only', female_only: 'Women only' };

const FIELD_ORDER = ['name', 'kind', 'gender_policy', 'address', 'barangay', 'city', 'amenities', 'house_rules', 'payment_instructions'];

/**
 * Create or edit a property (owners; slice 4.1). The address is entered the way it is written in the
 * Philippines: street address (optional), barangay, city, province. The province follows from the city.
 * The listing link is made from the name; it isn't a field.
 */
export default function Form({ property, locked, places, amenities, kinds, genderPolicies }: FormProps) {
    const editing = property !== null;
    const form = useForm({
        kind: property?.kind ?? 'boarding_house',
        name: property?.name ?? '',
        address: property?.address ?? '',
        barangay: property?.barangay ?? '',
        city: property?.city ?? '',
        gender_policy: property?.gender_policy ?? 'any',
        amenities: property?.amenities ?? ([] as number[]),
        house_rules: property?.house_rules ?? '',
        payment_instructions: property?.payment_instructions ?? '',
    });
    const { ref: summaryRef, onError } = useFocusOnError();
    const backHref = editing ? `/landlord/properties/${property.id}` : '/landlord/properties';
    const province = places.find((place) => place.slug === form.data.city)?.province ?? '';

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

                            <TextField
                                name="barangay"
                                label="Barangay"
                                value={form.data.barangay}
                                onChange={(value) => form.setData('barangay', value)}
                                error={form.errors.barangay}
                                autoComplete="address-level3"
                                placeholder="Sampaloc"
                                required
                            />

                            <div className="row">
                                <div className="col-sm-6">
                                    <SelectField
                                        name="city"
                                        label="City"
                                        value={form.data.city}
                                        onChange={(value) => form.setData('city', value)}
                                        options={places.map((place) => ({ value: place.slug, label: place.city }))}
                                        placeholder="Choose a city"
                                        error={form.errors.city}
                                        help={locked ? "The city can't be changed after the property has been published." : undefined}
                                        disabled={locked}
                                        required
                                    />
                                </div>
                                <div className="col-sm-6">
                                    <TextField
                                        name="province"
                                        label="Province"
                                        value={province}
                                        onChange={() => undefined}
                                        placeholder="Filled in when you choose a city"
                                        help="Comes from the city."
                                        readOnly
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
