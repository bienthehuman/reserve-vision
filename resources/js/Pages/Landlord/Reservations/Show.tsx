import { Head, Link } from '@inertiajs/react';
import { Badge, Card, Col, Container, Row, Table } from 'react-bootstrap';
import PriceTag from '@/Components/PriceTag';
import StatusBadge from '@/Components/StatusBadge';
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
    };
    place: { property_id: number; property_name: string; room_name: string; space_label: string };
    renter: { name: string; email: string | null; phone: string | null };
    proofs: { id: number; amount: number; reference_no: string | null; status: ProofStatus; uploaded_at: string; rejection_reason: string | null }[];
}

const proofStatus: Record<ProofStatus, { label: string; color: 'info' | 'success' | 'danger' }> = {
    pending: { label: 'Awaiting review', color: 'info' },
    accepted: { label: 'Accepted', color: 'success' },
    rejected: { label: 'Rejected', color: 'danger' },
};

/** One reservation, with the renter's contact details: the only landlord page that shows them (slice 3.2). */
export default function Show({ reservation, place, renter, proofs }: ShowProps) {
    return (
        <LandlordLayout>
            <Head title={`Reservation ${reservation.id}`} />
            <Container className="py-4 py-md-5">
                <p className="mb-2">
                    <Link href="/landlord/reservations">Back to reservations</Link>
                </p>
                <h1 className="h2 mb-2">Reservation for {renter.name}</h1>
                <p className="mb-4">
                    <StatusBadge status={reservation.status} />
                </p>

                <Row className="g-4">
                    <Col xs={12} lg={6}>
                        <Card className="auth-card h-100">
                            <Card.Body className="p-3 p-sm-4">
                                <h2 className="h3">Stay</h2>
                                <dl className="row mb-0">
                                    <dt className="col-sm-5">Property</dt>
                                    <dd className="col-sm-7">
                                        <Link href={`/landlord/properties/${place.property_id}`}>{place.property_name}</Link>
                                    </dd>
                                    <dt className="col-sm-5">Room and bed</dt>
                                    <dd className="col-sm-7">
                                        {place.room_name}, {place.space_label}
                                    </dd>
                                    <dt className="col-sm-5">Move-in to move-out</dt>
                                    <dd className="col-sm-7">
                                        {formatDate(reservation.move_in)} to {formatDate(reservation.move_out)}
                                    </dd>
                                    <dt className="col-sm-5">Reservation fee</dt>
                                    <dd className="col-sm-7">
                                        <PriceTag centavos={reservation.reservation_fee} />
                                    </dd>
                                    {reservation.hold_expires_at && (
                                        <>
                                            <dt className="col-sm-5">{reservation.is_live_hold ? 'Hold expires' : 'Hold expired'}</dt>
                                            <dd className="col-sm-7">{formatDateTime(reservation.hold_expires_at)} (Manila time)</dd>
                                        </>
                                    )}
                                    <dt className="col-sm-5">Reserved on</dt>
                                    <dd className="col-sm-7 mb-0">{formatDateTime(reservation.created_at)}</dd>
                                </dl>
                            </Card.Body>
                        </Card>
                    </Col>
                    <Col xs={12} lg={6}>
                        <Card className="auth-card h-100">
                            <Card.Body className="p-3 p-sm-4">
                                <h2 className="h3">Renter</h2>
                                <dl className="row mb-0">
                                    <dt className="col-sm-5">Name</dt>
                                    <dd className="col-sm-7">{renter.name}</dd>
                                    {renter.email && (
                                        <>
                                            <dt className="col-sm-5">Email</dt>
                                            <dd className="col-sm-7 text-break">
                                                <a href={`mailto:${renter.email}`}>{renter.email}</a>
                                            </dd>
                                        </>
                                    )}
                                    {renter.phone && (
                                        <>
                                            <dt className="col-sm-5">Phone</dt>
                                            <dd className="col-sm-7 mb-0">
                                                <a href={`tel:${renter.phone}`}>{renter.phone}</a>
                                            </dd>
                                        </>
                                    )}
                                </dl>
                            </Card.Body>
                        </Card>
                    </Col>
                </Row>

                <h2 className="h3 mt-5 mb-3">Payment proofs</h2>
                {proofs.length === 0 ? (
                    <p className="text-body-secondary">No proof of payment uploaded yet.</p>
                ) : (
                    <>
                        <Table className="stack-table align-middle">
                            <thead>
                                <tr>
                                    <th scope="col">Uploaded</th>
                                    <th scope="col">Amount</th>
                                    <th scope="col">Reference</th>
                                    <th scope="col">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {proofs.map((proof) => (
                                    <tr key={proof.id}>
                                        <td data-label="Uploaded">{formatDateTime(proof.uploaded_at)}</td>
                                        <td data-label="Amount">
                                            <PriceTag centavos={proof.amount} />
                                        </td>
                                        <td data-label="Reference">{proof.reference_no ?? 'None given'}</td>
                                        <td data-label="Status">
                                            <Badge pill bg="" className={`status-badge status-badge--${proofStatus[proof.status].color}`}>
                                                {proofStatus[proof.status].label}
                                            </Badge>
                                            {proof.rejection_reason && <span className="d-block small text-body-secondary">{proof.rejection_reason}</span>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                        <p className="small text-body-secondary">Opening the proof file comes in a later version.</p>
                    </>
                )}
            </Container>
        </LandlordLayout>
    );
}
