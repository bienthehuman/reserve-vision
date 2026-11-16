import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Card, Container, Table } from 'react-bootstrap';
import ActionButton from '@/Components/ActionButton';
import ConfirmButton from '@/Components/ConfirmButton';
import ErrorSummary from '@/Components/Form/ErrorSummary';
import SubmitButton from '@/Components/Form/SubmitButton';
import TextField from '@/Components/Form/TextField';
import { useFocusOnError } from '@/Components/Form/useFocusOnError';
import PageHeader from '@/Components/PageHeader';
import LandlordLayout from '@/Layouts/LandlordLayout';
import { formatDate, formatDateTime } from '@/lib/dates';

interface Member {
    id: number;
    name: string;
    role: 'owner' | 'caretaker';
    joined_at: string;
    is_you: boolean;
    can_remove: boolean;
}

interface Invitation {
    id: number;
    email: string;
    role: 'owner' | 'caretaker';
    expires_at: string;
    is_expired: boolean;
    can_resend: boolean;
    can_revoke: boolean;
}

interface IndexProps {
    members: Member[];
    invitations: Invitation[];
}

const roleLabel = { owner: 'Owner', caretaker: 'Caretaker' } as const;

/**
 * Members and the invitations nobody has answered yet (owners only). An owner invites a caretaker by email,
 * sends an invitation again or cancels it, and removes a member (slices 3.2 and 4.3).
 */
export default function Index({ members, invitations }: IndexProps) {
    const form = useForm({ email: '' });
    const { ref: summaryRef, onError } = useFocusOnError();
    const anyRemovable = members.some((member) => member.can_remove);

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
                                {anyRemovable && (
                                    <th scope="col">
                                        <span className="visually-hidden">Actions</span>
                                    </th>
                                )}
                            </tr>
                        </thead>
                        <tbody>
                            {members.map((member) => (
                                <tr key={member.id}>
                                    <td data-label="Name">
                                        <span>
                                            {member.name}
                                            {member.is_you && <span className="text-body-secondary"> (you)</span>}
                                        </span>
                                    </td>
                                    <td data-label="Role">{roleLabel[member.role]}</td>
                                    <td data-label="Joined">{formatDate(member.joined_at.slice(0, 10))}</td>
                                    {anyRemovable && (
                                        <td className="stack-table__actions">
                                            {member.can_remove && (
                                                <ConfirmButton
                                                    href={`/landlord/members/${member.id}`}
                                                    method="delete"
                                                    accessibleName={`Remove ${member.name}`}
                                                    title={`Remove ${member.name}?`}
                                                    body={`${member.name} will no longer see this account's properties and reservations. They keep their login, and you can invite them again.`}
                                                    confirmLabel="Remove member"
                                                    pendingLabel="Removing…"
                                                    cancelLabel="Keep them"
                                                >
                                                    Remove
                                                </ConfirmButton>
                                            )}
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Card>

                <h2 className="h3 mt-5 mb-3">Invitations waiting for an answer</h2>
                {invitations.length === 0 ? (
                    <p className="text-body-secondary">Nobody is waiting to answer an invitation.</p>
                ) : (
                    <Card className="table-card">
                        <Table className="stack-table align-middle">
                            <thead>
                                <tr>
                                    <th scope="col">Email</th>
                                    <th scope="col">Role</th>
                                    <th scope="col">Good until</th>
                                    <th scope="col">
                                        <span className="visually-hidden">Actions</span>
                                    </th>
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
                                            <span>
                                                {formatDateTime(invitation.expires_at)}
                                                {invitation.is_expired && ' (the link has expired)'}
                                            </span>
                                        </td>
                                        <td className="stack-table__actions">
                                            <span className="button-row">
                                                {invitation.can_resend && (
                                                    <ActionButton
                                                        href={`/landlord/invitations/${invitation.id}/resend`}
                                                        method="post"
                                                        accessibleName={`Send again to ${invitation.email}`}
                                                    >
                                                        Send again
                                                    </ActionButton>
                                                )}
                                                {invitation.can_revoke && (
                                                    <ConfirmButton
                                                        href={`/landlord/invitations/${invitation.id}`}
                                                        method="delete"
                                                        accessibleName={`Cancel invitation for ${invitation.email}`}
                                                        title="Cancel this invitation?"
                                                        body={`The link we sent to ${invitation.email} will stop working. You can invite them again later.`}
                                                        confirmLabel="Cancel invitation"
                                                        pendingLabel="Cancelling…"
                                                        cancelLabel="Keep it"
                                                    >
                                                        Cancel invitation
                                                    </ConfirmButton>
                                                )}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </Card>
                )}

                <Card className="mt-4">
                    <Card.Body>
                        <h2 className="h3 mb-3">Invite a caretaker</h2>
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
                                help="We'll email them a link that works for 7 days. A caretaker can see reservations and properties, but can't change prices or invite people."
                                required
                            />
                            <div className="button-row">
                                <SubmitButton processing={form.processing} pendingLabel="Sending…" className="">
                                    Send invitation
                                </SubmitButton>
                            </div>
                        </form>
                    </Card.Body>
                </Card>
            </Container>
        </LandlordLayout>
    );
}
