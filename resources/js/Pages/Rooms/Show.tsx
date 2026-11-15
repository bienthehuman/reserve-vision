import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Alert, Card, Col, Container, Form, Row } from 'react-bootstrap';
import { GeoAlt, InfoCircle } from 'react-bootstrap-icons';
import ErrorSummary from '@/Components/Form/ErrorSummary';
import SubmitButton from '@/Components/Form/SubmitButton';
import { useFocusOnError } from '@/Components/Form/useFocusOnError';
import PriceTag from '@/Components/PriceTag';
import PublicLayout from '@/Layouts/PublicLayout';
import { formatDate } from '@/lib/dates';

interface RoomPageProps {
    room: {
        id: number;
        name: string;
        type: 'private' | 'shared';
        monthly_rate: number;
        reservation_fee: number;
        space_count: number;
    };
    property: { name: string; barangay: string | null; city: string };
    stay: { move_in: string; months: number; move_out: string };
    window: { first: string; last: string; min_months: number; max_months: number };
    freeSpaces: number;
    openReservation: { id: number; space_label: string } | null;
}

const fieldOrder = ['move_in', 'months'] as const;

function spaceWord(type: RoomPageProps['room']['type'], count: number): string {
    if (type === 'private') {
        return 'room';
    }

    return count === 1 ? 'bed' : 'beds';
}

/** Minimal room page (slice 2.5): rate, free spaces for the chosen stay, and the reserve form. Slice 5.1 replaces it. */
export default function Show({ room, property, stay, window, freeSpaces, openReservation }: RoomPageProps) {
    const { auth } = usePage().props;
    const user = auth.user;
    const form = useForm({ move_in: stay.move_in, months: String(stay.months) });
    const { ref: summaryRef, onError } = useFocusOnError();
    const place = [property.barangay, property.city].filter(Boolean).join(', ');

    // Re-count the free spaces for the new stay, keeping what the renter typed.
    const recount = (data: { move_in: string; months: string }) => {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(data.move_in)) {
            return;
        }
        router.get(`/rooms/${room.id}`, data, {
            only: ['stay', 'freeSpaces', 'flash'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const update = (field: 'move_in' | 'months', value: string) => {
        const data = { ...form.data, [field]: value };
        form.setData(field, value);
        recount(data);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/rooms/${room.id}/reservations`, { onError, preserveScroll: true });
    };

    const free =
        room.type === 'private'
            ? freeSpaces > 0
                ? 'The room is free'
                : 'The room is taken'
            : `${freeSpaces} of ${room.space_count} ${spaceWord(room.type, room.space_count)} free`;

    return (
        <PublicLayout>
            <Head title={`${room.name}, ${property.name}`} />
            <Container className="py-4 py-md-5">
                <Row className="justify-content-center">
                    <Col xs={12} md={10} lg={8} xl={7}>
                        <p className="text-body-secondary mb-1">{property.name}</p>
                        <h1 className="h2 mb-2">{room.name}</h1>
                        <p className="d-flex align-items-center gap-1 text-body-secondary mb-4">
                            <GeoAlt aria-hidden="true" focusable="false" className="flex-shrink-0" />
                            <span>{place}</span>
                        </p>

                        <Card className="auth-card mb-4">
                            <Card.Body className="p-3 p-sm-4">
                                <dl className="row mb-0">
                                    <dt className="col-sm-5">Monthly rate</dt>
                                    <dd className="col-sm-7">
                                        <PriceTag centavos={room.monthly_rate} per="month" />
                                    </dd>
                                    <dt className="col-sm-5">Reservation fee</dt>
                                    <dd className="col-sm-7">
                                        <PriceTag centavos={room.reservation_fee} />
                                    </dd>
                                    <dt className="col-sm-5">Room</dt>
                                    <dd className="col-sm-7 mb-0">
                                        {room.type === 'private'
                                            ? 'Private room'
                                            : `Shared room, ${room.space_count} ${spaceWord(room.type, room.space_count)}`}
                                    </dd>
                                </dl>
                            </Card.Body>
                        </Card>

                        <Card className="auth-card">
                            <Card.Body className="p-3 p-sm-4">
                                <h2 className="h3 mb-3">Reserve</h2>

                                {openReservation ? (
                                    <Alert variant="info" className="d-flex gap-2 align-items-start mb-0">
                                        <InfoCircle aria-hidden="true" focusable="false" className="flex-shrink-0 mt-1" />
                                        <div>
                                            You have {openReservation.space_label} in this room on hold.{' '}
                                            <Link href={`/reservations/${openReservation.id}`} className="alert-link">
                                                View your hold
                                            </Link>
                                        </div>
                                    </Alert>
                                ) : user?.type === 'landlord' ? (
                                    <Alert variant="info" className="d-flex gap-2 align-items-start mb-0">
                                        <InfoCircle aria-hidden="true" focusable="false" className="flex-shrink-0 mt-1" />
                                        <div>Only renter accounts can reserve. You're logged in as a landlord.</div>
                                    </Alert>
                                ) : (
                                    <>
                                        <ErrorSummary ref={summaryRef} errors={form.errors} order={fieldOrder} />
                                        <Form noValidate onSubmit={submit} aria-label="Reserve this room">
                                            <Row className="g-3 mb-3">
                                                <Col xs={12} sm={7}>
                                                    <Form.Group controlId="field-move_in">
                                                        <Form.Label>Move-in date</Form.Label>
                                                        <Form.Control
                                                            type="date"
                                                            name="move_in"
                                                            value={form.data.move_in}
                                                            min={window.first}
                                                            max={window.last}
                                                            onChange={(event) => update('move_in', event.target.value)}
                                                            isInvalid={!!form.errors.move_in}
                                                            aria-invalid={form.errors.move_in ? true : undefined}
                                                            aria-describedby={form.errors.move_in ? 'field-move_in-error field-move_in-help' : 'field-move_in-help'}
                                                            required
                                                        />
                                                        {form.errors.move_in && (
                                                            <Form.Control.Feedback type="invalid" id="field-move_in-error">
                                                                {form.errors.move_in}
                                                            </Form.Control.Feedback>
                                                        )}
                                                        <Form.Text id="field-move_in-help" className="d-block">
                                                            {formatDate(window.first)} to {formatDate(window.last)}
                                                        </Form.Text>
                                                    </Form.Group>
                                                </Col>
                                                <Col xs={12} sm={5}>
                                                    <Form.Group controlId="field-months">
                                                        <Form.Label>Lease length</Form.Label>
                                                        <Form.Select
                                                            name="months"
                                                            value={form.data.months}
                                                            onChange={(event) => update('months', event.target.value)}
                                                            isInvalid={!!form.errors.months}
                                                            aria-invalid={form.errors.months ? true : undefined}
                                                            aria-describedby={form.errors.months ? 'field-months-error' : undefined}
                                                        >
                                                            {Array.from({ length: window.max_months - window.min_months + 1 }, (_, i) => window.min_months + i).map(
                                                                (months) => (
                                                                    <option key={months} value={months}>
                                                                        {months === 1 ? '1 month' : `${months} months`}
                                                                    </option>
                                                                ),
                                                            )}
                                                        </Form.Select>
                                                        {form.errors.months && (
                                                            <Form.Control.Feedback type="invalid" id="field-months-error">
                                                                {form.errors.months}
                                                            </Form.Control.Feedback>
                                                        )}
                                                    </Form.Group>
                                                </Col>
                                            </Row>

                                            <p className="mb-3" aria-live="polite" data-testid="free-spaces">
                                                <strong>{free}</strong> for {formatDate(stay.move_in)} to {formatDate(stay.move_out)}.
                                            </p>

                                            <SubmitButton processing={form.processing} pendingLabel="Reserving…">
                                                Reserve
                                            </SubmitButton>
                                            <p className="small text-body-secondary mt-2 mb-0">
                                                {user
                                                    ? 'We hold a space for you for 24 hours while you pay the reservation fee.'
                                                    : "You'll log in first. We then hold a space for you for 24 hours while you pay the reservation fee."}
                                            </p>
                                        </Form>
                                    </>
                                )}
                            </Card.Body>
                        </Card>
                    </Col>
                </Row>
            </Container>
        </PublicLayout>
    );
}
