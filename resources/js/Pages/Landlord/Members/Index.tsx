import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Alert, Card, Container, Table } from 'react-bootstrap';
import { InfoCircle } from 'react-bootstrap-icons';
import ErrorSummary from '@/Components/Form/ErrorSummary';
import SubmitButton from '@/Components/Form/SubmitButton';
import TextField from '@/Components/Form/TextField';
import { useFocusOnError } from '@/Components/Form/useFocusOnError';
import PageHeader from '@/Components/PageHeader';
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
                <PageHeader title="Members">
                    <p className="page-header__subtitle">The people who can manage this account's properties and reservations.</p>
                </PageHeader>

                <Card className="table-card">
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
                </Card>

                <h2 className="h3 mt-5 mb-3">Invitations waiting for an answer</h2>
                {invitations.length === 0 ? (
                    <p className="text-body-secondary">No one has been invited yet.</p>
                ) : (
                    <Card className="table-card">
                    <Table className="stack-table align-middle">
                        <thead>
                            <tr>
                                <th scope="col">Email</th>
                                <th scope="col">Role</th>
                                <th scope="col">Good until</th>
                            </tr>
                        </thead>
                        <tbody>
                            {invitations.map((invitation) => (
                                <tr key={invitation.id}>
                                    <td data-label="Email" className="text-break">
                                        {invitation.email}
                                    </td>
                                    <td data-label="Role">{roleLabel[invitation.role]}</td>
                                    <td data-label="Good until">
                                        {formatDateTime(invitation.expires_at)}
                                        {invitation.is_expired && ' (no longer valid)'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                    </Card>
                )}

                <Card className="form-card mt-4">
                    <Card.Body>
                        <h2 className="h3 mb-3">Invite a caretaker</h2>
                        <Alert variant="info" className="d-flex gap-2 align-items-start">
                            <InfoCircle aria-hidden="true" focusable="false" className="flex-shrink-0 mt-1" />
                            <span>We don't email invitations yet. For now, an invitation is only listed on this page.</span>
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
                                help="A caretaker can see reservations and properties, but can't change prices or invite people. The invitation is good for 7 days."
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
