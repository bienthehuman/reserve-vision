import { Head, Link } from '@inertiajs/react';
import { Card, Col, Container, Row } from 'react-bootstrap';
import PriceTag from '@/Components/PriceTag';
import StatusBadge from '@/Components/StatusBadge';
import PublicLayout from '@/Layouts/PublicLayout';
import { formatDate, formatDateTime } from '@/lib/dates';
import type { ReservationStatus } from '@/types';

interface ReservationPageProps {
    reservation: {
        id: number;
        status: ReservationStatus;
        is_live_hold: boolean;
        reservation_fee: number;
        hold_expires_at: string | null;
        move_in: string;
        move_out: string;
        space_label: string;
    };
    room: { id: number; name: string };
    property: { name: string; barangay: string | null; city: string };
}

/** The renter's "your hold" page (slice 2.5, minimal). Proof upload arrives in slice 5.3. */
export default function Show({ reservation, room, property }: ReservationPageProps) {
    const place = [property.barangay, property.city].filter(Boolean).join(', ');

    return (
        <PublicLayout>
            <Head title={reservation.is_live_hold ? 'Your hold' : 'Your reservation'} />
            <Container className="py-4 py-md-5">
                <Row className="justify-content-center">
                    <Col xs={12} md={10} lg={8} xl={7}>
                        <h1 className="h2 mb-2">{reservation.is_live_hold ? 'Your hold' : 'Your reservation'}</h1>
                        <p className="mb-4">
                            <StatusBadge status={reservation.status} />
                        </p>

                        <Card className="auth-card mb-4">
                            <Card.Body className="p-3 p-sm-4">
                                <dl className="row mb-0">
                                    <dt className="col-sm-5">Space</dt>
                                    <dd className="col-sm-7" data-testid="space-label">
                                        {reservation.space_label}
                                    </dd>
                                    <dt className="col-sm-5">Room</dt>
                                    <dd className="col-sm-7">
                                        <Link href={`/rooms/${room.id}`}>{room.name}</Link>, {property.name}
                                        <span className="d-block small text-body-secondary">{place}</span>
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
                                            <dt className="col-sm-5">{reservation.is_live_hold ? 'Hold expires' : 'Hold expired'}</dt>
                                            <dd className="col-sm-7 mb-0">{formatDateTime(reservation.hold_expires_at)} (Manila time)</dd>
                                        </>
                                    )}
                                </dl>
                            </Card.Body>
                        </Card>

                        {reservation.is_live_hold && (
                            <p className="text-body-secondary">
                                Pay the reservation fee to the landlord and upload your proof of payment before the hold
                                expires. Uploading proof is coming soon.
                            </p>
                        )}
                    </Col>
                </Row>
            </Container>
        </PublicLayout>
    );
}
