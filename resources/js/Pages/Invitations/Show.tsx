import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Button, Col, Form, Row } from 'react-bootstrap';
import { ExclamationCircle } from 'react-bootstrap-icons';
import AuthCard from '@/Components/AuthCard';
import ErrorSummary from '@/Components/Form/ErrorSummary';
import SubmitButton from '@/Components/Form/SubmitButton';
import TextField from '@/Components/Form/TextField';
import { useFocusOnError } from '@/Components/Form/useFocusOnError';
import PublicLayout from '@/Layouts/PublicLayout';
import { nameSuffixes, type NameSuffix } from '@/types/name';

interface Invitation {
    /** The landlord account's name. */
    account: string;
    role: 'owner' | 'caretaker';
    invited_by: string;
    /** The address the invitation was sent to; the new account gets it. */
    email: string;
    accept_url: string;
}

interface ShowProps {
    /** What this visitor can do with the link: App\Http\Controllers\Invitations\ShowInvitation. */
    state: 'invalid' | 'register' | 'login' | 'join' | 'refused' | 'other_user';
    /** The sentence for `invalid` and `refused`. */
    reason: string | null;
    invitation: Invitation | null;
}

const roleLabel = { owner: 'an owner', caretaker: 'a caretaker' } as const;
const fieldOrder = ['first_name', 'middle_name', 'last_name', 'suffix', 'phone', 'password', 'password_confirmation'] as const;

/** Nobody has an account with the invited email yet: name, mobile number and password. The email is the invited one. */
function RegisterForm({ invitation }: { invitation: Invitation }) {
    const form = useForm({
        first_name: '',
        middle_name: '',
        last_name: '',
        suffix: '' as NameSuffix | '',
        phone: '',
        password: '',
        password_confirmation: '',
    });
    const { ref: summaryRef, onError } = useFocusOnError();

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, middle_name: data.middle_name || null, suffix: data.suffix || null }));
        form.post(invitation.accept_url, { onError, onFinish: () => form.reset('password', 'password_confirmation') });
    };

    return (
        <>
            <ErrorSummary ref={summaryRef} errors={form.errors} order={fieldOrder} />
            <Form noValidate onSubmit={submit} aria-label="Create your account to join">
                <TextField
                    name="email"
                    type="email"
                    label="Email"
                    value={invitation.email}
                    onChange={() => undefined}
                    help="The invitation was sent to this address, so your account uses it."
                    readOnly
                />
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
                <TextField
                    name="phone"
                    type="tel"
                    label="Mobile number"
                    autoComplete="tel-national"
                    inputMode="tel"
                    placeholder="e.g. 0917 123 4567"
                    help="Renters with a reservation can call or text you on it."
                    value={form.data.phone}
                    onChange={(value) => form.setData('phone', value)}
                    error={form.errors.phone}
                    required
                />
                <Row className="g-0 gx-sm-3">
                    <Col xs={12} sm={6}>
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
                    </Col>
                    <Col xs={12} sm={6}>
                        <TextField
                            name="password_confirmation"
                            type="password"
                            label="Type the password again"
                            autoComplete="new-password"
                            value={form.data.password_confirmation}
                            onChange={(value) => form.setData('password_confirmation', value)}
                            error={form.errors.password_confirmation}
                            required
                        />
                    </Col>
                </Row>
                <SubmitButton processing={form.processing} pendingLabel="Creating your account…">
                    Create my account and join
                </SubmitButton>
            </Form>
        </>
    );
}

/** One button that posts: "Join <account>", or "Log out". */
function PostButton({ href, children, pendingLabel }: { href: string; children: string; pendingLabel: string }) {
    const [processing, setProcessing] = useState(false);

    return (
        <Button
            type="button"
            variant="primary"
            className="w-100"
            disabled={processing}
            onClick={() => router.post(href, {}, { onStart: () => setProcessing(true), onFinish: () => setProcessing(false) })}
        >
            {processing ? pendingLabel : children}
        </Button>
    );
}

/**
 * The page an invitation email links to (slice 4.3). A link that can't be used, for whatever reason,
 * gets the same sentence. A good one says who invited you to what, and then what you can do:
 * sign up, log in first, join with one tap, or why you can't.
 */
export default function Show({ state, reason, invitation }: ShowProps) {
    if (state === 'invalid' || invitation === null) {
        return (
            <PublicLayout>
                <Head title="Invitation" />
                <AuthCard title="This invitation can't be used">
                    <p className="mb-0">{reason}</p>
                </AuthCard>
            </PublicLayout>
        );
    }

    const intro = (
        <>
            {invitation.invited_by} invited you to help manage <strong className="text-body">{invitation.account}</strong> as {roleLabel[invitation.role]}.
            {invitation.role === 'caretaker' && " You'll see its properties and reservations, and you can answer renters. You can't change prices or invite people."}
        </>
    );

    return (
        <PublicLayout>
            <Head title={`Join ${invitation.account}`} />
            <AuthCard title={`Join ${invitation.account}`} intro={intro} wide={state === 'register'}>
                {state === 'register' && <RegisterForm invitation={invitation} />}

                {state === 'login' && (
                    <>
                        <p>
                            There is already an account with <span className="text-break">{invitation.email}</span>. Log in with it, and we'll bring you back here to join.
                        </p>
                        <Link href="/login" className="btn btn-primary w-100">
                            Log in to join
                        </Link>
                    </>
                )}

                {state === 'join' && (
                    <PostButton href={invitation.accept_url} pendingLabel="Joining…">
                        {`Join ${invitation.account}`}
                    </PostButton>
                )}

                {state === 'refused' && (
                    <p className="mb-0" role="status">
                        {reason}
                    </p>
                )}

                {state === 'other_user' && (
                    <>
                        <p>
                            This invitation was sent to <span className="text-break">{invitation.email}</span>, and you're logged in with another email. Log out, then open the link in the
                            invitation email again.
                        </p>
                        <PostButton href="/logout" pendingLabel="Logging out…">
                            Log out
                        </PostButton>
                    </>
                )}
            </AuthCard>
        </PublicLayout>
    );
}
