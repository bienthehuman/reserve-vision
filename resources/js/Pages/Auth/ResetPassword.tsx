import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Form } from 'react-bootstrap';
import AuthCard from '@/Components/AuthCard';
import ErrorSummary from '@/Components/Form/ErrorSummary';
import SubmitButton from '@/Components/Form/SubmitButton';
import TextField from '@/Components/Form/TextField';
import { useFocusOnError } from '@/Components/Form/useFocusOnError';
import PublicLayout from '@/Layouts/PublicLayout';

interface ResetPasswordProps {
    token: string;
    email: string;
}

const fieldOrder = ['email', 'password', 'password_confirmation'] as const;

export default function ResetPassword({ token, email }: ResetPasswordProps) {
    const form = useForm({ token, email, password: '', password_confirmation: '' });
    const { ref: summaryRef, onError } = useFocusOnError();

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/reset-password', { onError, onFinish: () => form.reset('password', 'password_confirmation') });
    };

    return (
        <PublicLayout>
            <Head title="Choose a new password" />
            <AuthCard title="Choose a new password" intro="Then log in with it.">
                <ErrorSummary ref={summaryRef} errors={form.errors} order={fieldOrder} />
                <Form noValidate onSubmit={submit} aria-label="Choose a new password">
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
                        label="New password"
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
                        label="Type the new password again"
                        autoComplete="new-password"
                        className="mb-4"
                        value={form.data.password_confirmation}
                        onChange={(value) => form.setData('password_confirmation', value)}
                        error={form.errors.password_confirmation}
                        required
                    />
                    <SubmitButton processing={form.processing} pendingLabel="Saving…">
                        Save my new password
                    </SubmitButton>
                </Form>
            </AuthCard>
        </PublicLayout>
    );
}
