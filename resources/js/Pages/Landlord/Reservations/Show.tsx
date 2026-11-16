import { Head, Link } from '@inertiajs/react';
import { Badge, Card, Col, Container, Row } from 'react-bootstrap';
import { Receipt } from 'react-bootstrap-icons';
import PageHeader from '@/Components/PageHeader';
import PriceTag from '@/Components/PriceTag';
import ProofViewer from '@/Components/ProofViewer';
import RefundCard from '@/Components/RefundCard';
import ReservationDecisions, { type Decisions } from '@/Components/ReservationDecisions';
import StatusBadge from '@/Components/StatusBadge';
import EmptyState from '@/Components/EmptyState';
import LandlordLayout from '@/Layouts/LandlordLayout';
import { formatDate, formatDateTime } from '@/lib/dates';
import type { ReservationStatus } from '@/types';

type ProofStatus = 'pending' | 'accepted' | 'rejected';

interface ShowProps {
    reservation: {
        id: number;
        status: ReservationStatus;
        is_live_hold: boolean;
        reservation_fee: number;
        hold_expires_at: string | null;
        move_in: string;
        move_out: string;
        created_at: string;
        proof_rejections: number;
        decided_by: string | null;
        decided_at: string | null;
        decision_reason: string | null;
        cancelled_at: string | null;
        cancelled_by_renter: boolean;
        cancelled_by: string | null;
    };
    decisions: Decisions;
    place: { property_id: number; property_name: string; room_name: string; space_label: string };
    renter: { name: string; email: string | null; phone: string | null };
    proofs: {
        id: number;
        amount: number;
        reference_no: string | null;
        status: ProofStatus;
        uploaded_at: string;
        rejection_reason: string | null;
        is_image: boolean;
        url: string;
    }[];
}

const proofStatus: Record<ProofStatus, { label: string; color: 'info' | 'success' | 'danger' }> = {
    pending: { label: 'Waiting for review', color: 'info' },
    accepted: { label: 'Accepted', color: 'success' },
    rejected: { label: 'Rejected', color: 'danger' },
};

/** The decision that ended up on the reservation, in a sentence ("Confirmed by Ana Cruz"), or null. */
function decisionLabel(reservation: ShowProps['reservation']): string | null {
    const by = reservation.decided_by ? ` by ${reservation.decided_by}` : '';

    switch (reservation.status) {
        case 'confirmed':
        case 'active':
        case 'ended':
            return `Confirmed${by}`;
        case 'declined':
            return `Declined${by}`;
        case 'cancelled':
            return reservation.cancelled_by_renter ? 'Cancelled by the renter' : `Withdrawn${reservation.cancelled_by ? ` by ${reservation.cancelled_by}` : ''}`;
        default:
            return null;
    }
}

/**
 * One reservation, with the renter's contact details (the only landlord page that shows them, slice 3.2), their proofs of
 * payment (opened through a signed URL, slice 5.3), and (6.1) the decision panel, the decision made and the refund record.
 */
export default function Show({ reservation, decisions, place, renter, proofs }: ShowProps) {
    const decision = decisionLabel(reservation);
    // "Hold ends/ended" only while the hold is what the reservation is: held (live or run out) or expired. Once a proof is in,
    // the hold time no longer matters (backlog fix in 6.1).
    const showHold = reservation.hold_expires_at !== null && (reservation.status === 'held' || reservation.status === 'expired');
    return (
        <LandlordLayout>
            <Head title={`Reservation ${reservation.id}`} />
            <Container className="py-4 py-md-5">
                <PageHeader title={`Reservation for ${renter.name}`} back={{ href: '/landlord/reservations', label: 'Back to reservations' }}>
                    <p className="mt-2 mb-0">
                        <StatusBadge status={reservation.status} />
                    </p>
                </PageHeader>

                <ReservationDecisions
                    reservationId={reservation.id}
                    status={reservation.status}
                    moveIn={reservation.move_in}
                    moveOut={reservation.move_out}
                    renterName={renter.name}
                    decisions={decisions}
                />

                <Row className="g-4">
                    <Col xs={12} lg={6}>
                        <Card className="h-100">
                            <Card.Body>
                                <h2 className="h3 mb-3">Stay</h2>
                                <dl className="details row">
                                    <dt className="col-sm-5">Property</dt>
                                    <dd className="col-sm-7">
                                        <Link href={`/landlord/properties/${place.property_id}`} className="link-action">
                                            {place.property_name}
                                        </Link>
                                    </dd>
                                    <dt className="col-sm-5">Room and bed</dt>
                                    <dd className="col-sm-7">
                                        {place.room_name}, {place.space_label}
                                    </dd>
                                    <dt className="col-sm-5">Stay</dt>
                                    <dd className="col-sm-7">
                                        {formatDate(reservation.move_in)} to {formatDate(reservation.move_out)}
                                    </dd>
                                    <dt className="col-sm-5">Reservation fee</dt>
                                    <dd className="col-sm-7">
                                        <PriceTag centavos={reservation.reservation_fee} />
                                    </dd>
                                    {showHold && reservation.hold_expires_at && (
                                        <>
                                            <dt className="col-sm-5">{reservation.is_live_hold ? 'Hold ends' : 'Hold ended'}</dt>
                                            <dd className="col-sm-7">{formatDateTime(reservation.hold_expires_at)} (Philippine time)</dd>
                                        </>
                                    )}
                                    {reservation.proof_rejections > 0 && (
                                        <>
                                            <dt className="col-sm-5">New proofs asked for</dt>
                                            <dd className="col-sm-7">{reservation.proof_rejections} of 2</dd>
                                        </>
                                    )}
                                    {decision && (
                                        <>
                                            <dt className="col-sm-5">Decision</dt>
                                            <dd className="col-sm-7" data-testid="decision">
                                                {decision}
                                                {(reservation.cancelled_at ?? reservation.decided_at) && (
                                                    <span className="d-block small text-body-secondary">
                                                        {formatDateTime((reservation.cancelled_at ?? reservation.decided_at) as string)} (Philippine time)
                                                    </span>
                                                )}
                                            </dd>
                                        </>
                                    )}
                                    {reservation.decision_reason && (reservation.status === 'declined' || reservation.status === 'cancelled') && (
                                        <>
                                            <dt className="col-sm-5">Reason given</dt>
                                            <dd className="col-sm-7 pre-line">{reservation.decision_reason}</dd>
                                        </>
                                    )}
                                    <dt className="col-sm-5">Reserved on</dt>
                                    <dd className="col-sm-7 mb-0">{formatDateTime(reservation.created_at)}</dd>
                                </dl>
                            </Card.Body>
                        </Card>
                    </Col>
                    <Col xs={12} lg={6}>
                        <Card className="h-100">
                            <Card.Body>
                                <h2 className="h3 mb-3">Renter</h2>
                                <dl className="details row">
                                    <dt className="col-sm-5">Name</dt>
                                    <dd className="col-sm-7">{renter.name}</dd>
                                    {renter.email && (
                                        <>
                                            <dt className="col-sm-5">Email</dt>
                                            <dd className="col-sm-7 text-break">
                                                <a href={`mailto:${renter.email}`} className="link-action">
                                                    {renter.email}
                                                </a>
                                            </dd>
                                        </>
                                    )}
                                    {renter.phone && (
                                        <>
                                            <dt className="col-sm-5">Phone</dt>
                                            <dd className="col-sm-7 mb-0">
                                                <a href={`tel:${renter.phone}`} className="link-action">
                                                    {renter.phone}
                                                </a>
                                            </dd>
                                        </>
                                    )}
                                </dl>
                            </Card.Body>
                        </Card>
                    </Col>
                </Row>

                {decisions.refund && (
                    <div className="mt-4">
                        <RefundCard reservationId={reservation.id} refund={decisions.refund} />
                    </div>
                )}

                <h2 className="h3 mt-5 mb-3">Proof of payment</h2>
                {proofs.length === 0 ? (
                    <Card>
                        <EmptyState Icon={Receipt}>The renter hasn't uploaded a proof of payment yet.</EmptyState>
                    </Card>
                ) : (
                    <ul className="proof-list">
                        {proofs.map((proof) => {
                            const label = `Proof of payment from ${renter.name}, uploaded ${formatDateTime(proof.uploaded_at)}`;

                            return (
                                <li key={proof.id}>
                                    <Card>
                                        <Card.Body>
                                            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                                                <span className="fw-semibold">Uploaded {formatDateTime(proof.uploaded_at)}</span>
                                                <Badge pill bg="" className={`status-badge status-badge--${proofStatus[proof.status].color}`}>
                                                    {proofStatus[proof.status].label}
                                                </Badge>
                                            </div>
                                            <dl className="details row">
                                                <dt className="col-6 col-sm-4">Amount</dt>
                                                <dd className="col-6 col-sm-8">
                                                    <PriceTag centavos={proof.amount} />
                                                </dd>
                                                <dt className="col-6 col-sm-4">Reference number</dt>
                                                <dd className="col-6 col-sm-8 text-break">{proof.reference_no ?? 'Not given'}</dd>
                                                {proof.rejection_reason && (
                                                    <>
                                                        <dt className="col-sm-4">Why it was rejected</dt>
                                                        <dd className="col-sm-8">{proof.rejection_reason}</dd>
                                                    </>
                                                )}
                                            </dl>
                                            <ProofViewer proof={proof} label={label} />
                                        </Card.Body>
                                    </Card>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </Container>
        </LandlordLayout>
    );
}
