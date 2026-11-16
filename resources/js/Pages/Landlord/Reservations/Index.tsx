import { Head, Link } from '@inertiajs/react';
import { Button, Card, Container, Form, Table } from 'react-bootstrap';
import { CalendarX, Funnel } from 'react-bootstrap-icons';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import Pager from '@/Components/Pager';
import PriceTag from '@/Components/PriceTag';
import StatusBadge, { statusStyles } from '@/Components/StatusBadge';
import LandlordLayout from '@/Layouts/LandlordLayout';
import { formatDate } from '@/lib/dates';
import type { ReservationStatus } from '@/types';

interface Row {
    id: number;
    status: ReservationStatus;
    renter_name: string;
    property_name: string;
    room_name: string;
    space_label: string;
    move_in: string;
    move_out: string;
    reservation_fee: number;
}

interface IndexProps {
    reservations: Row[];
    pagination: { page: number; last_page: number; total: number; prev_url: string | null; next_url: string | null };
    filter: { status: ReservationStatus | null; show: 'proofs_overdue' | 'holds_expiring' | 'moving_in' | null };
    statuses: ReservationStatus[];
}

const showLabels = {
    proofs_overdue: 'Payments waiting for your review for more than 24 hours',
    holds_expiring: 'Holds that end within 6 hours',
    moving_in: 'Renters moving in within the next 7 days',
} as const;

/** Everything after the origin, so an Inertia Link gets a path, not a full URL. */
function localPath(url: string): string {
    const { pathname, search } = new URL(url, 'http://localhost');

    return pathname + search;
}

/** The account's reservations, newest first (slice 3.2). */
export default function Index({ reservations, pagination, filter, statuses }: IndexProps) {
    return (
        <LandlordLayout>
            <Head title="Reservations" />
            <Container className="py-4 py-md-5">
                <PageHeader title="Reservations" />

                {filter.show && (
                    <p className="mb-3">
                        Showing: <strong>{showLabels[filter.show]}</strong>.{' '}
                        <Link href="/landlord/reservations" className="link-action">
                            Show all reservations
                        </Link>
                    </p>
                )}

                <div className="toolbar">
                    <Form method="get" action="/landlord/reservations" className="toolbar__filters">
                        {filter.show && <input type="hidden" name="show" value={filter.show} />}
                        <Form.Group controlId="filter-status">
                            <Form.Label>Status</Form.Label>
                            <Form.Select name="status" defaultValue={filter.status ?? ''}>
                                <option value="">All statuses</option>
                                {statuses.map((status) => (
                                    <option key={status} value={status}>
                                        {statusStyles[status].label}
                                    </option>
                                ))}
                            </Form.Select>
                        </Form.Group>
                        <Button type="submit" variant="outline-primary">
                            <Funnel aria-hidden="true" focusable="false" />
                            Filter
                        </Button>
                    </Form>
                    {reservations.length > 0 && (
                        <p className="small text-body-secondary mb-0" aria-live="polite">
                            {pagination.total} {pagination.total === 1 ? 'reservation' : 'reservations'}
                        </p>
                    )}
                </div>

                {reservations.length === 0 ? (
                    <Card>
                        <EmptyState Icon={CalendarX}>{filter.status || filter.show ? 'No reservations match.' : 'No reservations yet.'}</EmptyState>
                    </Card>
                ) : (
                    <>
                        <Card className="table-card">
                            <Table className="stack-table align-middle">
                                <thead>
                                    <tr>
                                        <th scope="col">Renter</th>
                                        <th scope="col">Room or bed</th>
                                        <th scope="col">Stay</th>
                                        <th scope="col">Status</th>
                                        <th scope="col" className="text-md-end">
                                            Fee
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {reservations.map((row) => (
                                        <tr key={row.id}>
                                            <td data-label="Renter">
                                                <Link href={`/landlord/reservations/${row.id}`} className="link-plain">
                                                    {row.renter_name}
                                                </Link>
                                            </td>
                                            <td data-label="Room or bed">
                                                <span>
                                                    <span className="d-block">{row.property_name}</span>
                                                    <span className="d-block small text-body-secondary">
                                                        {row.room_name} · {row.space_label}
                                                    </span>
                                                </span>
                                            </td>
                                            <td data-label="Stay">
                                                {formatDate(row.move_in)} to {formatDate(row.move_out)}
                                            </td>
                                            <td data-label="Status">
                                                <StatusBadge status={row.status} />
                                            </td>
                                            <td data-label="Fee" className="text-md-end">
                                                <PriceTag centavos={row.reservation_fee} />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </Card>

                        <Pager
                            page={pagination.page}
                            lastPage={pagination.last_page}
                            prevHref={pagination.prev_url ? localPath(pagination.prev_url) : null}
                            nextHref={pagination.next_url ? localPath(pagination.next_url) : null}
                        />
                    </>
                )}
            </Container>
        </LandlordLayout>
    );
}
