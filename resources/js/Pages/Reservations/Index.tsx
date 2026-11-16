import { Head, Link } from '@inertiajs/react';
import { Card, Container } from 'react-bootstrap';
import { CalendarX, Search } from 'react-bootstrap-icons';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import Pager from '@/Components/Pager';
import StatusBadge from '@/Components/StatusBadge';
import PublicLayout from '@/Layouts/PublicLayout';
import { formatDate, formatHoldEnd } from '@/lib/dates';
import type { ReservationStatus } from '@/types';

interface Row {
    id: number;
    status: ReservationStatus;
    property_name: string;
    place: string;
    room_name: string;
    space_label: string;
    move_in: string;
    move_out: string;
    hold_expires_at: string | null;
}

interface IndexProps {
    current: Row[];
    past: Row[];
    pagination: { page: number; last_page: number; total: number; prev_url: string | null; next_url: string | null };
}

/** A paginator URL as a path, so links stay on the page's own origin. */
function localPath(url: string): string {
    const parsed = new URL(url, 'http://localhost');

    return `${parsed.pathname}${parsed.search}`;
}

/** "My reservations" (slice 5.3): the ones still holding a place first, then the past ones. */
export default function Index({ current, past, pagination }: IndexProps) {
    const nothing = current.length === 0 && pagination.total === 0;

    return (
        <PublicLayout>
            <Head title="My reservations" />
            <Container className="py-4 py-md-5">
                <PageHeader title="My reservations" />

                {nothing ? (
                    <Card>
                        <EmptyState
                            Icon={Search}
                            action={
                                <Link href="/listings" className="btn btn-primary">
                                    Find a place
                                </Link>
                            }
                        >
                            You haven't reserved a place yet.
                        </EmptyState>
                    </Card>
                ) : (
                    <>
                        <section className="mb-5" aria-labelledby="current-title">
                            <h2 id="current-title" className="h3 mb-3">
                                Current <span className="text-body-secondary fw-normal">({current.length})</span>
                            </h2>
                            {current.length === 0 ? (
                                <Card>
                                    <EmptyState Icon={CalendarX}>Nothing on hold or coming up.</EmptyState>
                                </Card>
                            ) : (
                                <ReservationList rows={current} />
                            )}
                        </section>

                        {pagination.total > 0 && (
                            <section aria-labelledby="past-title">
                                <h2 id="past-title" className="h3 mb-3">
                                    Past <span className="text-body-secondary fw-normal">({pagination.total})</span>
                                </h2>
                                <ReservationList rows={past} />
                                <Pager
                                    page={pagination.page}
                                    lastPage={pagination.last_page}
                                    prevHref={pagination.prev_url ? localPath(pagination.prev_url) : null}
                                    nextHref={pagination.next_url ? localPath(pagination.next_url) : null}
                                />
                            </section>
                        )}
                    </>
                )}
            </Container>
        </PublicLayout>
    );
}

function ReservationList({ rows }: { rows: Row[] }) {
    return (
        <ul className="proof-list mb-3">
            {rows.map((row) => (
                <li key={row.id}>
                    <Card>
                        <Card.Body>
                            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                <div>
                                    <h3 className="h5 mb-1">
                                        <Link href={`/reservations/${row.id}`} className="link-plain stretched-link">
                                            {row.property_name}
                                        </Link>
                                    </h3>
                                    <p className="small text-body-secondary mb-0">{row.place}</p>
                                </div>
                                <StatusBadge status={row.status} />
                            </div>
                            <p className="mt-2 mb-0">
                                {row.room_name} · {row.space_label}
                                <span className="d-block">
                                    {formatDate(row.move_in)} to {formatDate(row.move_out)}
                                </span>
                                {row.hold_expires_at && (
                                    <span className="d-block small text-body-secondary">Hold until {formatHoldEnd(row.hold_expires_at)}, Manila time</span>
                                )}
                            </p>
                        </Card.Body>
                    </Card>
                </li>
            ))}
        </ul>
    );
}
