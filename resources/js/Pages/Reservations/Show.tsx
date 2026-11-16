import { Head, Link } from '@inertiajs/react';
import { Card, Col, Container, Row } from 'react-bootstrap';
import PageHeader from '@/Components/PageHeader';
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
    property: { name: string; barangay: string; city: string };
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
                        <PageHeader title={reservation.is_live_hold ? 'Your hold' : 'Your reservation'}>
                            <p className="mt-2 mb-0">
                                <StatusBadge status={reservation.status} />
                            </p>
                        </PageHeader>

                        <Card className="mb-4">
                            <Card.Body>
                                <dl className="details row">
                                    <dt className="col-sm-5">Reserved for you</dt>
                                    <dd className="col-sm-7" data-testid="space-label">
                                        {reservation.space_label}
                                    </dd>
                                    <dt className="col-sm-5">Room</dt>
                                    <dd className="col-sm-7">
                                        <a href={`/rooms/${room.id}`} className="link-action">
                                            {room.name}
                                        </a>
                                        , {property.name}
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
                                            <dt className="col-sm-5">{reservation.is_live_hold ? 'Hold ends' : 'Hold ended'}</dt>
                                            <dd className="col-sm-7 mb-0">{formatDateTime(reservation.hold_expires_at)} (Philippine time)</dd>
                                        </>
                                    )}
                                </dl>
                            </Card.Body>
                        </Card>

                        {reservation.is_live_hold && (
                            <p className="text-body-secondary">
                                Pay the reservation fee to the landlord before the hold ends. You'll be able to upload your
                                proof of payment here soon.
                            </p>
                        )}
                    </Col>
                </Row>
            </Container>
        </PublicLayout>
    );
}
