import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Col, Form, Row } from 'react-bootstrap';
import { ExclamationCircle } from 'react-bootstrap-icons';
import AuthCard from '@/Components/AuthCard';
import ErrorSummary from '@/Components/Form/ErrorSummary';
import SubmitButton from '@/Components/Form/SubmitButton';
import TextField from '@/Components/Form/TextField';
import { useFocusOnError } from '@/Components/Form/useFocusOnError';
import PublicLayout from '@/Layouts/PublicLayout';
import { nameSuffixes, type NameSuffix } from '@/types/name';

type AccountType = '' | 'renter' | 'landlord';

const fieldOrder = [
    'type',
    'first_name',
    'middle_name',
    'last_name',
    'suffix',
    'business_name',
    'email',
    'phone',
    'password',
    'password_confirmation',
] as const;

export default function Register() {
    const form = useForm({
        type: '' as AccountType,
        first_name: '',
        middle_name: '',
        last_name: '',
        suffix: '' as NameSuffix | '',
        business_name: '',
        email: '',
        phone: '',
        password: '',
        password_confirmation: '',
    });
    const { ref: summaryRef, onError } = useFocusOnError();
    const isLandlord = form.data.type === 'landlord';

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            middle_name: data.middle_name || null,
            suffix: data.suffix || null,
            business_name: data.type === 'landlord' ? data.business_name : null,
        }));
        form.post('/register', { onError, onFinish: () => form.reset('password', 'password_confirmation') });
    };

    return (
        <PublicLayout>
            <Head title="Sign up" />
            <AuthCard
                title="Sign up"
                intro="One account to reserve rooms and beds, or to list your property."
                footer={
                    <>
                        Already have an account? <Link href="/login">Log in</Link>
                    </>
                }
            >
                <ErrorSummary ref={summaryRef} errors={form.errors} order={fieldOrder} />
                <Form noValidate onSubmit={submit} aria-label="Sign up">
                    <fieldset
                        className="mb-4"
                        aria-describedby={form.errors.type ? 'field-type-error' : undefined}
                    >
                        <legend className="form-label fs-6 fw-semibold mb-2">I want to:</legend>
                        {/* The summary links to the first option. */}
                        <Form.Check
                            type="radio"
                            id="field-type"
                            name="type"
                            value="renter"
                            label="Rent a place"
                            checked={form.data.type === 'renter'}
                            onChange={() => form.setData('type', 'renter')}
                            isInvalid={!!form.errors.type}
                        />
                        <Form.Check
                            type="radio"
                            id="field-type-landlord"
                            name="type"
                            value="landlord"
                            label="List my property"
                            checked={form.data.type === 'landlord'}
                            onChange={() => form.setData('type', 'landlord')}
                            isInvalid={!!form.errors.type}
                        />
                        {form.errors.type && (
                            <div id="field-type-error" className="invalid-feedback d-flex">
                                <ExclamationCircle aria-hidden="true" focusable="false" className="flex-shrink-0" />
                                <span>{form.errors.type}</span>
                            </div>
                        )}
                    </fieldset>

                    <fieldset className="mb-2">
                        <legend className="form-label fs-6 fw-semibold mb-2">Your name</legend>
                        <Row className="g-0 gx-sm-3">
                            <Col xs={12} sm={6}>
                                <TextField
                                    name="first_name"
                                    label="First name"
                                    autoComplete="given-name"
                                    value={form.data.first_name}
                                    onChange={(value) => form.setData('first_name', value)}
                                    error={form.errors.first_name}
                                    required
                                />
                            </Col>
                            <Col xs={12} sm={6}>
                                <TextField
                                    name="middle_name"
                                    label="Middle name"
                                    optional
                                    autoComplete="additional-name"
                                    value={form.data.middle_name}
                                    onChange={(value) => form.setData('middle_name', value)}
                                    error={form.errors.middle_name}
                                />
                            </Col>
                            <Col xs={12} sm={8}>
                                <TextField
                                    name="last_name"
                                    label="Last name"
                                    autoComplete="family-name"
                                    value={form.data.last_name}
                                    onChange={(value) => form.setData('last_name', value)}
                                    error={form.errors.last_name}
                                    required
                                />
                            </Col>
                            <Col xs={12} sm={4}>
                                <Form.Group className="mb-3" controlId="field-suffix">
                                    <Form.Label>
                                        Suffix <span className="text-body-secondary fw-normal">(optional)</span>
                                    </Form.Label>
                                    <Form.Select
                                        name="suffix"
                                        value={form.data.suffix}
                                        onChange={(event) => form.setData('suffix', event.target.value as NameSuffix | '')}
                                        isInvalid={!!form.errors.suffix}
                                        aria-describedby={form.errors.suffix ? 'field-suffix-error' : undefined}
                                        autoComplete="honorific-suffix"
                                    >
                                        <option value="">None</option>
                                        {nameSuffixes.map((suffix) => (
                                            <option key={suffix.value} value={suffix.value}>
                                                {suffix.label}
                                            </option>
                                        ))}
                                    </Form.Select>
                                    {form.errors.suffix && (
                                        <Form.Control.Feedback type="invalid" id="field-suffix-error">
                                            <ExclamationCircle aria-hidden="true" focusable="false" className="flex-shrink-0" />
                                            <span>{form.errors.suffix}</span>
                                        </Form.Control.Feedback>
                                    )}
                                </Form.Group>
                            </Col>
                        </Row>
                    </fieldset>

                    {isLandlord && (
                        <TextField
                            name="business_name"
                            label="Business or display name"
                            autoComplete="organization"
                            placeholder="e.g. Santos Family Rentals"
                            help="Renters see this on your listings. Your own name is fine too."
                            value={form.data.business_name}
                            onChange={(value) => form.setData('business_name', value)}
                            error={form.errors.business_name}
                            required
                        />
                    )}

                    <TextField
                        name="email"
                        type="email"
                        label="Email"
                        autoComplete="email"
                        inputMode="email"
                        help="We'll send a link to confirm it."
                        value={form.data.email}
                        onChange={(value) => form.setData('email', value)}
                        error={form.errors.email}
                        required
                    />
                    <TextField
                        name="phone"
                        type="tel"
                        label="Mobile number"
                        autoComplete="tel-national"
                        inputMode="tel"
                        placeholder="e.g. 0917 123 4567"
                        help={isLandlord ? 'Renters with a reservation can call or text you on it.' : 'Landlords you reserve with can call or text you on it.'}
                        value={form.data.phone}
                        onChange={(value) => form.setData('phone', value)}
                        error={form.errors.phone}
                        required
                    />
                    <TextField
                        name="password"
                        type="password"
                        label="Password"
                        autoComplete="new-password"
                        help="At least 8 characters."
                        value={form.data.password}
                        onChange={(value) => form.setData('password', value)}
                        error={form.errors.password}
                        required
                    />
                    <TextField
                        name="password_confirmation"
                        type="password"
                        label="Type the password again"
                        autoComplete="new-password"
                        className="mb-4"
                        value={form.data.password_confirmation}
                        onChange={(value) => form.setData('password_confirmation', value)}
                        error={form.errors.password_confirmation}
                        required
                    />
                    <SubmitButton processing={form.processing} pendingLabel="Creating your account…">
                        Create my account
                    </SubmitButton>
                </Form>
            </AuthCard>
        </PublicLayout>
    );
}
