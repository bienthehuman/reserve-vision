import { Head, Link } from '@inertiajs/react';
import { Card, Container } from 'react-bootstrap';
import { CalendarX, GeoAlt, Search, Telephone } from 'react-bootstrap-icons';
import ConfirmButton from '@/Components/ConfirmButton';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import Pager from '@/Components/Pager';
import ViewingStatusBadge from '@/Components/ViewingStatusBadge';
import PublicLayout from '@/Layouts/PublicLayout';
import { formatHoldEnd } from '@/lib/dates';
import type { ShownViewingStatus } from '@/types/viewing';

interface Row {
    id: number;
    status: ShownViewingStatus;
    property_name: string;
    place: string;
    listing_url: string | null;
    preferred_at: string;
    is_upcoming: boolean;
    message: string | null;
    landlord_note: string | null;
    can_cancel: boolean;
    meeting: { address: string; landlord: string | null; phone: string | null } | null;
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

/** "My viewings" (slice 6.2): the requests still to come first, then the past ones. */
export default function Index({ current, past, pagination }: IndexProps) {
    const nothing = current.length === 0 && pagination.total === 0;

    return (
        <PublicLayout>
            <Head title="My viewings" />
            <Container className="py-4 py-md-5">
                <PageHeader title="My viewings" />

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
                            You haven't asked to see a place yet. Every listing has a "See it first" form.
                        </EmptyState>
                    </Card>
                ) : (
                    <>
                        <section className="mb-5" aria-labelledby="coming-title">
                            <h2 id="coming-title" className="h3 mb-3">
                                Coming up <span className="text-body-secondary fw-normal">({current.length})</span>
                            </h2>
                            {current.length === 0 ? (
                                <Card>
                                    <EmptyState Icon={CalendarX}>No viewings coming up.</EmptyState>
                                </Card>
                            ) : (
                                <ViewingList rows={current} />
                            )}
                        </section>

                        {pagination.total > 0 && (
                            <section aria-labelledby="past-title">
                                <h2 id="past-title" className="h3 mb-3">
                                    Past <span className="text-body-secondary fw-normal">({pagination.total})</span>
                                </h2>
                                <ViewingList rows={past} />
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

function ViewingList({ rows }: { rows: Row[] }) {
    return (
        <ul className="proof-list mb-3">
            {rows.map((row) => (
                <li key={row.id}>
                    <Card>
                        <Card.Body>
                            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                <div>
                                    <h3 className="h5 mb-1">
                                        {row.listing_url ? (
                                            <Link href={row.listing_url} className="link-plain">
                                                {row.property_name}
                                            </Link>
                                        ) : (
                                            row.property_name
                                        )}
                                    </h3>
                                    <p className="small text-body-secondary mb-0">{row.place}</p>
                                </div>
                                <ViewingStatusBadge status={row.status} detail={!row.is_upcoming && row.status === 'accepted' ? 'time has passed' : undefined} />
                            </div>

                            <p className="mt-2 mb-1">
                                <strong>{formatHoldEnd(row.preferred_at)}</strong> <span className="text-body-secondary">Manila time</span>
                            </p>
                            {row.status === 'requested' && row.is_upcoming && <p className="small text-body-secondary mb-1">Waiting for the landlord to answer.</p>}
                            {row.message && <p className="pre-line small mb-1">You wrote: {row.message}</p>}
                            {row.landlord_note && <p className="pre-line small mb-1">The landlord wrote: {row.landlord_note}</p>}

                            {row.meeting && (
                                <dl className="details row mt-2 mb-1" aria-label="Where and who to call">
                                    <dt className="col-4 col-sm-3">
                                        <GeoAlt aria-hidden="true" focusable="false" /> Address
                                    </dt>
                                    <dd className="col-8 col-sm-9">{row.meeting.address}</dd>
                                    <dt className="col-4 col-sm-3">
                                        <Telephone aria-hidden="true" focusable="false" /> Call
                                    </dt>
                                    <dd className="col-8 col-sm-9 mb-0">
                                        {row.meeting.phone ? (
                                            <a href={`tel:${row.meeting.phone}`}>{row.meeting.phone}</a>
                                        ) : (
                                            'No phone number on file'
                                        )}
                                        {row.meeting.landlord && <span className="d-block small text-body-secondary">{row.meeting.landlord}</span>}
                                    </dd>
                                </dl>
                            )}

                            {row.can_cancel && (
                                <div className="button-row mt-3">
                                    <ConfirmButton
                                        href={`/viewings/${row.id}/cancel`}
                                        method="post"
                                        accessibleName={`Cancel request for ${row.property_name}`}
                                        title="Cancel this viewing request?"
                                        body={`The landlord of ${row.property_name} will no longer expect you.`}
                                        confirmLabel="Cancel request"
                                        pendingLabel="Cancelling…"
                                        cancelLabel="Keep it"
                                    >
                                        Cancel request
                                    </ConfirmButton>
                                </div>
                            )}
                        </Card.Body>
                    </Card>
                </li>
            ))}
        </ul>
    );
}
