import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Alert, Card, Container, Table } from 'react-bootstrap';
import { InfoCircle } from 'react-bootstrap-icons';
import ErrorSummary from '@/Components/Form/ErrorSummary';
import SubmitButton from '@/Components/Form/SubmitButton';
import TextField from '@/Components/Form/TextField';
import { useFocusOnError } from '@/Components/Form/useFocusOnError';
import LandlordLayout from '@/Layouts/LandlordLayout';
import { formatDate, formatDateTime } from '@/lib/dates';

interface IndexProps {
    members: { id: number; name: string; role: 'owner' | 'caretaker'; joined_at: string }[];
    invitations: { id: number; email: string; role: 'owner' | 'caretaker'; expires_at: string; is_expired: boolean }[];
}

const roleLabel = { owner: 'Owner', caretaker: 'Caretaker' } as const;

/** Members and pending invitations, and the owner's invite form (slice 3.2). The invite email and acceptance come in 4.3. */
export default function Index({ members, invitations }: IndexProps) {
    const form = useForm({ email: '' });
    const { ref: summaryRef, onError } = useFocusOnError();

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/landlord/invitations', {
            preserveScroll: true,
            onError,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <LandlordLayout>
            <Head title="Members" />
            <Container className="py-4 py-md-5">
                <h1 className="h2 mb-4">Members</h1>

                <Table className="stack-table align-middle">
                    <thead>
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col">Role</th>
                            <th scope="col">Joined</th>
                        </tr>
                    </thead>
                    <tbody>
                        {members.map((member) => (
                            <tr key={member.id}>
                                <td data-label="Name">{member.name}</td>
                                <td data-label="Role">{roleLabel[member.role]}</td>
                                <td data-label="Joined">{formatDate(member.joined_at.slice(0, 10))}</td>
                            </tr>
                        ))}
                    </tbody>
                </Table>

                <h2 className="h3 mt-5 mb-3">Pending invitations</h2>
                {invitations.length === 0 ? (
                    <p className="text-body-secondary">No pending invitations.</p>
                ) : (
                    <Table className="stack-table align-middle">
                        <thead>
                            <tr>
                                <th scope="col">Email</th>
                                <th scope="col">Role</th>
                                <th scope="col">Expires</th>
                            </tr>
                        </thead>
                        <tbody>
                            {invitations.map((invitation) => (
                                <tr key={invitation.id}>
                                    <td data-label="Email" className="text-break">
                                        {invitation.email}
                                    </td>
                                    <td data-label="Role">{roleLabel[invitation.role]}</td>
                                    <td data-label="Expires">
                                        {formatDateTime(invitation.expires_at)}
                                        {invitation.is_expired && ' (expired)'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}

                <Card className="auth-card mt-4">
                    <Card.Body className="p-3 p-sm-4">
                        <h2 className="h3">Invite a caretaker</h2>
                        <Alert variant="info" className="d-flex gap-2 align-items-start">
                            <InfoCircle aria-hidden="true" focusable="false" className="flex-shrink-0 mt-1" />
                            <span>The invite email arrives in a later version. For now the invitation is only saved here.</span>
                        </Alert>
                        <ErrorSummary ref={summaryRef} errors={form.errors} order={['email']} />
                        <form noValidate onSubmit={submit} aria-label="Invite a caretaker">
                            <TextField
                                name="email"
                                type="email"
                                label="Email"
                                autoComplete="off"
                                inputMode="email"
                                value={form.data.email}
                                onChange={(value) => form.setData('email', value)}
                                error={form.errors.email}
                                help="A caretaker can see reservations and properties, but can't change rates or invite people. Valid for 7 days."
                                required
                            />
                            <SubmitButton processing={form.processing} pendingLabel="Saving…" className="">
                                Save invitation
                            </SubmitButton>
                        </form>
                    </Card.Body>
                </Card>
            </Container>
        </LandlordLayout>
    );
}
