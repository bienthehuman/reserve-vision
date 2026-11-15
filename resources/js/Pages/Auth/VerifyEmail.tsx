import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Form } from 'react-bootstrap';
import AuthCard from '@/Components/AuthCard';
import SubmitButton from '@/Components/Form/SubmitButton';
import PublicLayout from '@/Layouts/PublicLayout';

interface VerifyEmailProps {
    email: string | null;
}

export default function VerifyEmail({ email }: VerifyEmailProps) {
    const form = useForm({});

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/email/verification-notification');
    };

    return (
        <PublicLayout>
            <Head title="Confirm your email" />
            <AuthCard title="Confirm your email">
                <p>
                    We've sent a link to {email ? <strong className="text-break">{email}</strong> : 'your email address'}. Tap it to confirm
                    your email, then you can reserve rooms or open your landlord dashboard.
                </p>
                <p className="text-body-secondary small">Nothing there? Check your spam folder, or ask for a new link.</p>
                <Form onSubmit={submit} aria-label="Send the link again" className="mb-3">
                    <SubmitButton processing={form.processing} pendingLabel="Sending…">
                        Send the link again
                    </SubmitButton>
                </Form>
                <p className="mb-0 small">
                    Wrong email?{' '}
                    <Link href="/logout" method="post" as="button" className="btn btn-link p-0 align-baseline small">
                        Log out
                    </Link>{' '}
                    and sign up again.
                </p>
            </AuthCard>
        </PublicLayout>
    );
}
