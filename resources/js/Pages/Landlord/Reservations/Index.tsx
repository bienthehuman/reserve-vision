import { Head, Link } from '@inertiajs/react';
import { Button, Container, Form, Table } from 'react-bootstrap';
import { CalendarX } from 'react-bootstrap-icons';
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
    proofs_overdue: 'Proofs that have waited more than 24 hours',
    holds_expiring: 'Holds that run out within 6 hours',
    moving_in: 'Confirmed stays that start in the next 7 days',
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
                <h1 className="h2 mb-3">Reservations</h1>

                {filter.show && (
                    <p className="mb-3">
                        Showing: <strong>{showLabels[filter.show]}</strong>.{' '}
                        <Link href="/landlord/reservations">Show all reservations</Link>
                    </p>
                )}

                <Form method="get" action="/landlord/reservations" className="d-flex flex-wrap align-items-end gap-2 mb-4">
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
                        Filter
                    </Button>
                </Form>

                {reservations.length === 0 ? (
                    <div className="text-center text-body-secondary py-5">
                        <CalendarX aria-hidden="true" focusable="false" size={32} className="mb-2" />
                        <p className="mb-0">{filter.status || filter.show ? 'No reservations match.' : 'No reservations yet.'}</p>
                    </div>
                ) : (
                    <>
                        <p className="text-body-secondary" aria-live="polite">
                            {pagination.total} {pagination.total === 1 ? 'reservation' : 'reservations'}
                        </p>
                        <Table className="stack-table align-middle">
                            <thead>
                                <tr>
                                    <th scope="col">Renter</th>
                                    <th scope="col">Place</th>
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
                                            <Link href={`/landlord/reservations/${row.id}`}>{row.renter_name}</Link>
                                        </td>
                                        <td data-label="Place">
                                            {row.property_name} / {row.room_name} / {row.space_label}
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

                        {pagination.last_page > 1 && (
                            <nav aria-label="Pages" className="d-flex align-items-center justify-content-between gap-2">
                                {pagination.prev_url ? (
                                    <Link href={localPath(pagination.prev_url)} className="btn btn-outline-primary">
                                        Previous
                                    </Link>
                                ) : (
                                    <span />
                                )}
                                <span>
                                    Page {pagination.page} of {pagination.last_page}
                                </span>
                                {pagination.next_url ? (
                                    <Link href={localPath(pagination.next_url)} className="btn btn-outline-primary">
                                        Next
                                    </Link>
                                ) : (
                                    <span />
                                )}
                            </nav>
                        )}
                    </>
                )}
            </Container>
        </LandlordLayout>
    );
}
