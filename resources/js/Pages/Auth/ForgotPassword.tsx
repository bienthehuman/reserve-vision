import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Form } from 'react-bootstrap';
import AuthCard from '@/Components/AuthCard';
import ErrorSummary from '@/Components/Form/ErrorSummary';
import SubmitButton from '@/Components/Form/SubmitButton';
import TextField from '@/Components/Form/TextField';
import { useFocusOnError } from '@/Components/Form/useFocusOnError';
import PublicLayout from '@/Layouts/PublicLayout';

export default function ForgotPassword() {
    const form = useForm({ email: '' });
    const { ref: summaryRef, onError } = useFocusOnError();

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/forgot-password', { onError });
    };

    return (
        <PublicLayout>
            <Head title="Reset your password" />
            <AuthCard
                title="Reset your password"
                intro="Enter the email you signed up with. We'll send you a link to choose a new password."
                footer={
                    <>
                        Remembered it? <Link href="/login">Log in</Link>
                    </>
                }
            >
                <ErrorSummary ref={summaryRef} errors={form.errors} order={['email']} />
                <Form noValidate onSubmit={submit} aria-label="Reset your password">
                    <TextField
                        name="email"
                        type="email"
                        label="Email"
                        autoComplete="email"
                        inputMode="email"
                        className="mb-4"
                        value={form.data.email}
                        onChange={(value) => form.setData('email', value)}
                        error={form.errors.email}
                        required
                    />
                    <SubmitButton processing={form.processing} pendingLabel="Sending the link…">
                        Email me a reset link
                    </SubmitButton>
                </Form>
            </AuthCard>
        </PublicLayout>
    );
}
