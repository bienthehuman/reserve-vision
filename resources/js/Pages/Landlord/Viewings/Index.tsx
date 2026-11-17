import { Head, Link } from '@inertiajs/react';
import { Badge, Button, Card, Container, Form, Table } from 'react-bootstrap';
import { CalendarX, Funnel } from 'react-bootstrap-icons';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import Pager from '@/Components/Pager';
import ViewingStatusBadge, { viewingStatusStyles } from '@/Components/ViewingStatusBadge';
import LandlordLayout from '@/Layouts/LandlordLayout';
import { formatHoldEnd } from '@/lib/dates';
import type { ShownViewingStatus } from '@/types/viewing';

interface Row {
    id: number;
    status: ShownViewingStatus;
    renter_name: string;
    property_name: string;
    preferred_at: string;
    is_today: boolean;
    is_upcoming: boolean;
    message: string | null;
}

interface IndexProps {
    viewings: Row[];
    pagination: { page: number; last_page: number; total: number; prev_url: string | null; next_url: string | null };
    filter: { status: ShownViewingStatus | null; show: 'waiting' | null };
    statuses: ShownViewingStatus[];
}

/** Everything after the origin, so an Inertia Link gets a path, not a full URL. */
function localPath(url: string): string {
    const { pathname, search } = new URL(url, 'http://localhost');

    return pathname + search;
}

/** The account's viewing requests (slice 6.2): today's first, then what's coming, then the past. */
export default function Index({ viewings, pagination, filter, statuses }: IndexProps) {
    return (
        <LandlordLayout>
            <Head title="Viewings" />
            <Container className="py-4 py-md-5">
                <PageHeader title="Viewings" />

                {filter.show && (
                    <p className="mb-3">
                        Showing: <strong>Requests nobody has answered yet, for a time that hasn't come</strong>.{' '}
                        <Link href="/landlord/viewings" className="link-action">
                            Show all viewings
                        </Link>
                    </p>
                )}

                <div className="toolbar">
                    <Form method="get" action="/landlord/viewings" className="toolbar__filters">
                        {filter.show && <input type="hidden" name="show" value={filter.show} />}
                        <Form.Group controlId="filter-status">
                            <Form.Label>Status</Form.Label>
                            <Form.Select name="status" defaultValue={filter.status ?? ''}>
                                <option value="">All statuses</option>
                                {statuses.map((status) => (
                                    <option key={status} value={status}>
                                        {viewingStatusStyles[status].label}
                                    </option>
                                ))}
                            </Form.Select>
                        </Form.Group>
                        <Button type="submit" variant="outline-primary">
                            <Funnel aria-hidden="true" focusable="false" />
                            Filter
                        </Button>
                    </Form>
                    {viewings.length > 0 && (
                        <p className="small text-body-secondary mb-0" aria-live="polite">
                            {pagination.total} {pagination.total === 1 ? 'viewing' : 'viewings'}
                        </p>
                    )}
                </div>

                {viewings.length === 0 ? (
                    <Card>
                        <EmptyState Icon={CalendarX}>{filter.status || filter.show ? 'No viewings match.' : 'No viewing requests yet.'}</EmptyState>
                    </Card>
                ) : (
                    <>
                        <Card className="table-card">
                            <Table className="stack-table align-middle">
                                <thead>
                                    <tr>
                                        <th scope="col">When (Manila time)</th>
                                        <th scope="col">Renter</th>
                                        <th scope="col">Property</th>
                                        <th scope="col">Message</th>
                                        <th scope="col">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {viewings.map((row) => (
                                        <tr key={row.id}>
                                            <td data-label="When">
                                                <span>
                                                    <Link href={`/landlord/viewings/${row.id}`} className="link-plain">
                                                        {formatHoldEnd(row.preferred_at)}
                                                    </Link>
                                                    {row.is_today && (
                                                        <Badge bg="" className="status-badge status-badge--warning ms-2">
                                                            Today
                                                        </Badge>
                                                    )}
                                                </span>
                                            </td>
                                            <td data-label="Renter">{row.renter_name}</td>
                                            <td data-label="Property">{row.property_name}</td>
                                            <td data-label="Message">{row.message ? <span className="pre-line">{row.message}</span> : <span className="text-body-secondary">No message</span>}</td>
                                            <td data-label="Status">
                                                <ViewingStatusBadge status={row.status} detail={!row.is_upcoming && row.status === 'accepted' ? 'time has passed' : undefined} />
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
