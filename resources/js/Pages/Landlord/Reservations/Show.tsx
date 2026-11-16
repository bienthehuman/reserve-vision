import { Head, Link } from '@inertiajs/react';
import { Badge, Card, Col, Container, Row, Table } from 'react-bootstrap';
import { Receipt } from 'react-bootstrap-icons';
import PageHeader from '@/Components/PageHeader';
import PriceTag from '@/Components/PriceTag';
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
    };
    place: { property_id: number; property_name: string; room_name: string; space_label: string };
    renter: { name: string; email: string | null; phone: string | null };
    proofs: { id: number; amount: number; reference_no: string | null; status: ProofStatus; uploaded_at: string; rejection_reason: string | null }[];
}

const proofStatus: Record<ProofStatus, { label: string; color: 'info' | 'success' | 'danger' }> = {
    pending: { label: 'Waiting for review', color: 'info' },
    accepted: { label: 'Accepted', color: 'success' },
    rejected: { label: 'Rejected', color: 'danger' },
};

/** One reservation, with the renter's contact details: the only landlord page that shows them (slice 3.2). */
export default function Show({ reservation, place, renter, proofs }: ShowProps) {
    return (
        <LandlordLayout>
            <Head title={`Reservation ${reservation.id}`} />
            <Container className="py-4 py-md-5">
                <PageHeader title={`Reservation for ${renter.name}`} back={{ href: '/landlord/reservations', label: 'Back to reservations' }}>
                    <p className="mt-2 mb-0">
                        <StatusBadge status={reservation.status} />
                    </p>
                </PageHeader>

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
                                    {reservation.hold_expires_at && (
                                        <>
                                            <dt className="col-sm-5">{reservation.is_live_hold ? 'Hold ends' : 'Hold ended'}</dt>
                                            <dd className="col-sm-7">{formatDateTime(reservation.hold_expires_at)} (Philippine time)</dd>
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

                <h2 className="h3 mt-5 mb-3">Proof of payment</h2>
                {proofs.length === 0 ? (
                    <Card>
                        <EmptyState Icon={Receipt}>The renter hasn't uploaded a proof of payment yet.</EmptyState>
                    </Card>
                ) : (
                    <>
                        <Card className="table-card">
                        <Table className="stack-table align-middle">
                            <thead>
                                <tr>
                                    <th scope="col">Uploaded</th>
                                    <th scope="col">Amount</th>
                                    <th scope="col">Reference number</th>
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
                                        <td data-label="Reference number">{proof.reference_no ?? 'Not given'}</td>
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
                        </Card>
                        <p className="small text-body-secondary mt-2">You'll be able to open the uploaded file here soon.</p>
                    </>
                )}
            </Container>
        </LandlordLayout>
    );
}
