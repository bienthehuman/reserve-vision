import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Badge, Card, Form, ListGroup } from 'react-bootstrap';
import AuthCard from '@/Components/AuthCard';
import ErrorSummary from '@/Components/Form/ErrorSummary';
import SubmitButton from '@/Components/Form/SubmitButton';
import TextField from '@/Components/Form/TextField';
import { useFocusOnError } from '@/Components/Form/useFocusOnError';
import PublicLayout from '@/Layouts/PublicLayout';

export interface DemoLogin {
    email: string;
    password: string;
    role: string;
    note: string;
}

interface LoginProps {
    canResetPassword: boolean;
    /** Only sent when APP_DEMO=true (FortifyServiceProvider). */
    demoLogins?: DemoLogin[];
}

const fieldOrder = ['email', 'password'] as const;

export default function Login({ canResetPassword, demoLogins }: LoginProps) {
    const form = useForm({ email: '', password: '', remember: false });
    const { ref: summaryRef, onError } = useFocusOnError();

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/login', { onError, onFinish: () => form.reset('password') });
    };

    const fillDemoLogin = (login: DemoLogin) => {
        form.clearErrors();
        form.setData({ email: login.email, password: login.password, remember: false });
    };

    return (
        <PublicLayout>
            <Head title="Log in" />
            <AuthCard
                title="Log in"
                intro="Welcome back. Log in to reserve a room or manage your listings."
                footer={
                    <>
                        New here? <Link href="/register">Sign up</Link>
                    </>
                }
            >
                <ErrorSummary ref={summaryRef} errors={form.errors} order={fieldOrder} />
                <Form noValidate onSubmit={submit} aria-label="Log in">
                    <TextField
                        name="email"
                        type="email"
                        label="Email"
                        autoComplete="username"
                        inputMode="email"
                        value={form.data.email}
                        onChange={(value) => form.setData('email', value)}
                        error={form.errors.email}
                        required
                    />
                    <TextField
                        name="password"
                        type="password"
                        label="Password"
                        autoComplete="current-password"
                        value={form.data.password}
                        onChange={(value) => form.setData('password', value)}
                        error={form.errors.password}
                        required
                    />
                    <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
                        <Form.Check
                            type="checkbox"
                            id="field-remember"
                            name="remember"
                            label="Keep me logged in on this device"
                            checked={form.data.remember}
                            onChange={(event) => form.setData('remember', event.target.checked)}
                        />
                        {canResetPassword && (
                            <Link href="/forgot-password" className="link-action">
                                Forgot your password?
                            </Link>
                        )}
                    </div>
                    <SubmitButton processing={form.processing} pendingLabel="Logging in…">
                        Log in
                    </SubmitButton>
                </Form>
            </AuthCard>

            {demoLogins && demoLogins.length > 0 && <DemoLoginPanel logins={demoLogins} onPick={fillDemoLogin} />}
        </PublicLayout>
    );
}

/** APP_DEMO=true only: the seeded accounts (README "Demo logins"); one tap fills the form. */
function DemoLoginPanel({ logins, onPick }: { logins: DemoLogin[]; onPick: (login: DemoLogin) => void }) {
    return (
        <section aria-labelledby="demo-logins-title" className="container pb-5">
            <div className="row justify-content-center">
                <div className="col-12 col-sm-10 col-md-8 col-lg-6 col-xl-5">
                    <Card className="demo-logins">
                        <Card.Body className="p-3 p-sm-4">
                            <h2 id="demo-logins-title" className="h3 mb-1">
                                Demo accounts
                            </h2>
                            <p className="small text-body-secondary mb-3">
                                This is a demo site. Tap an account to fill in the form, then log in. Every password is{' '}
                                <strong className="text-body">password</strong>.
                            </p>
                            <ListGroup as="ul">
                                {logins.map((login) => (
                                    <ListGroup.Item
                                        as="li"
                                        key={login.email}
                                        className="p-0"
                                    >
                                        <button
                                            type="button"
                                            className="list-group-item-action btn text-start w-100 border-0 rounded-0 d-flex flex-column align-items-start gap-1 px-3 py-2"
                                            onClick={() => onPick(login)}
                                        >
                                            <span className="d-flex flex-wrap align-items-center gap-2">
                                                <span className="fw-semibold text-break">{login.email}</span>
                                                <Badge bg="secondary-subtle" text="dark" pill>
                                                    {login.role}
                                                </Badge>
                                            </span>
                                            <span className="small text-body-secondary">{login.note}</span>
                                        </button>
                                    </ListGroup.Item>
                                ))}
                            </ListGroup>
                        </Card.Body>
                    </Card>
                </div>
            </div>
        </section>
    );
}
