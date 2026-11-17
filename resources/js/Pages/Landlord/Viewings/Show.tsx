import { Head, useForm } from '@inertiajs/react';
import { Card, Col, Container, Row } from 'react-bootstrap';
import ActionButton from '@/Components/ActionButton';
import TextAreaField from '@/Components/Form/TextAreaField';
import PageHeader from '@/Components/PageHeader';
import ViewingStatusBadge from '@/Components/ViewingStatusBadge';
import LandlordLayout from '@/Layouts/LandlordLayout';
import { formatHoldEnd } from '@/lib/dates';
import type { ShownViewingStatus } from '@/types/viewing';

interface ShowProps {
    viewing: {
        id: number;
        status: ShownViewingStatus;
        preferred_at: string;
        is_upcoming: boolean;
        message: string | null;
        landlord_note: string | null;
        decided_by: string | null;
        created_at: string;
        max_note: number;
    };
    moves: { key: 'accept' | 'decline' | 'done'; allowed: boolean }[];
    property: { id: number; name: string; address: string };
    renter: { name: string; email: string | null; phone: string | null };
}

/**
 * One viewing request (slice 6.2): the renter's contact details, their message, and the moves the transition table
 * allows now. Accept and decline take an optional note the renter reads, and an answer can be changed until the
 * time comes; "Mark done" opens from the time on.
 */
export default function Show({ viewing, moves, property, renter }: ShowProps) {
    const form = useForm({ note: viewing.landlord_note ?? '' });
    const move = (key: string) => moves.find((candidate) => candidate.key === key);
    const answers = moves.filter((candidate) => candidate.key !== 'done');
    const done = move('done');
    const when = formatHoldEnd(viewing.preferred_at);

    const answer = (key: 'accept' | 'decline') => form.post(`/landlord/viewings/${viewing.id}/${key}`, { preserveScroll: true });

    return (
        <LandlordLayout>
            <Head title={`Viewing: ${renter.name}`} />
            <Container className="py-4 py-md-5">
                <PageHeader title={`Viewing: ${renter.name}`} back={{ href: '/landlord/viewings', label: 'Back to viewings' }}>
                    <p className="mb-0">
                        <ViewingStatusBadge status={viewing.status} detail={!viewing.is_upcoming && viewing.status === 'accepted' ? 'time has passed' : undefined} />
                    </p>
                </PageHeader>

                <Row className="g-3">
                    <Col xs={12} lg={7}>
                        <Card className="h-100">
                            <Card.Body>
                                <h2 className="h4 mb-3">The viewing</h2>
                                <dl className="details row mb-0">
                                    <dt className="col-4">When</dt>
                                    <dd className="col-8">
                                        {when} <span className="text-body-secondary">Manila time</span>
                                    </dd>
                                    <dt className="col-4">Property</dt>
                                    <dd className="col-8">
                                        {property.name}
                                        <span className="d-block small text-body-secondary">{property.address}</span>
                                    </dd>
                                    <dt className="col-4">Message</dt>
                                    <dd className="col-8">{viewing.message ? <span className="pre-line">{viewing.message}</span> : <span className="text-body-secondary">No message</span>}</dd>
                                    {viewing.decided_by && (
                                        <>
                                            <dt className="col-4">Answered by</dt>
                                            <dd className="col-8">{viewing.decided_by}</dd>
                                        </>
                                    )}
                                    {viewing.landlord_note && (
                                        <>
                                            <dt className="col-4">Your note</dt>
                                            <dd className="col-8 mb-0">
                                                <span className="pre-line">{viewing.landlord_note}</span>
                                            </dd>
                                        </>
                                    )}
                                </dl>
                            </Card.Body>
                        </Card>
                    </Col>
                    <Col xs={12} lg={5}>
                        <Card className="h-100">
                            <Card.Body>
                                <h2 className="h4 mb-3">The renter</h2>
                                <dl className="details row mb-0">
                                    <dt className="col-4">Name</dt>
                                    <dd className="col-8">{renter.name}</dd>
                                    <dt className="col-4">Email</dt>
                                    <dd className="col-8 text-break">{renter.email ? <a href={`mailto:${renter.email}`}>{renter.email}</a> : '—'}</dd>
                                    <dt className="col-4">Phone</dt>
                                    <dd className="col-8 mb-0">{renter.phone ? <a href={`tel:${renter.phone}`}>{renter.phone}</a> : '—'}</dd>
                                </dl>
                            </Card.Body>
                        </Card>
                    </Col>
                </Row>

                {moves.length > 0 && (
                    <Card className="mt-3">
                        <Card.Body>
                            <h2 className="h4 mb-3">Your answer</h2>

                            {answers.length > 0 && (
                                <form
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                    }}
                                    aria-label="Answer the viewing request"
                                >
                                    <TextAreaField
                                        name="note"
                                        label="Note to the renter"
                                        optional
                                        value={form.data.note}
                                        onChange={(value) => form.setData('note', value)}
                                        error={form.errors.note}
                                        help={`They read it with your answer. Up to ${viewing.max_note} characters.`}
                                        rows={3}
                                    />
                                    {!answers.every((candidate) => candidate.allowed) && (
                                        <p className="small text-body-secondary" id="answer-closed">
                                            The time has passed, so the answer can no longer be changed.
                                        </p>
                                    )}
                                    <div className="button-row">
                                        {answers.map((candidate) =>
                                            candidate.key === 'accept' ? (
                                                <button key="accept" type="button" className="btn btn-primary" disabled={form.processing || !candidate.allowed} onClick={() => answer('accept')}>
                                                    {viewing.status === 'declined' ? 'Accept instead' : 'Accept'}
                                                </button>
                                            ) : (
                                                <button key="decline" type="button" className="btn btn-outline-primary" disabled={form.processing || !candidate.allowed} onClick={() => answer('decline')}>
                                                    {viewing.status === 'accepted' ? 'Decline instead' : 'Decline'}
                                                </button>
                                            ),
                                        )}
                                    </div>
                                    <p className="small text-body-secondary mt-2 mb-0">You can change your answer until the time of the viewing.</p>
                                </form>
                            )}

                            {done && (
                                <div className={answers.length > 0 ? 'mt-4 pt-3 border-top' : undefined}>
                                    <p id="done-help" className="small text-body-secondary">
                                        {done.allowed ? 'The renter came, or the time has passed? Mark the viewing as done.' : `You can mark it done from ${when} (Manila time).`}
                                    </p>
                                    <ActionButton href={`/landlord/viewings/${viewing.id}/done`} method="post" size="md" disabled={!done.allowed} describedBy="done-help">
                                        Mark as done
                                    </ActionButton>
                                </div>
                            )}
                        </Card.Body>
                    </Card>
                )}
            </Container>
        </LandlordLayout>
    );
}
