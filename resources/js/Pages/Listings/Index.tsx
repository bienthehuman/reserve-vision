import { Head, Link } from '@inertiajs/react';
import { Col, Container, Row } from 'react-bootstrap';
import { Search } from 'react-bootstrap-icons';
import EmptyState from '@/Components/EmptyState';
import FilterSheet, { type SearchOptions, type SearchValues } from '@/Components/FilterSheet';
import ListingCard, { type ListingCardData } from '@/Components/ListingCard';
import PageHeader from '@/Components/PageHeader';
import Pager from '@/Components/Pager';
import PublicLayout from '@/Layouts/PublicLayout';
import { formatDate } from '@/lib/dates';

interface IndexProps {
    listings: ListingCardData[];
    total: number;
    page: number;
    lastPage: number;
    prevHref: string | null;
    nextHref: string | null;
    filtered: boolean;
    filters: SearchValues;
    options: SearchOptions;
    window: { first: string; last: string };
    moveOut: string | null;
}

/** How many filters are set, for the phone's "Filters (3)" button. Sorting is not a filter. */
function activeCount(filters: SearchValues): number {
    const single = [filters.city || filters.province, filters.barangay, filters.min_price, filters.max_price, filters.kind, filters.gender, filters.move_in];

    return single.filter((value) => value !== '').length + filters.amenities.length;
}

/** Search results (slice 5.2): properties that match the filters in the query string, cheapest matching room first. */
export default function Index({ listings, total, page, lastPage, prevHref, nextHref, filtered, filters, options, window, moveOut }: IndexProps) {
    const places = total === 1 ? '1 place' : `${total} places`;
    const from = filters.move_in === '' ? null : filters.move_in;

    return (
        <PublicLayout>
            <Head title={filtered ? 'Search results' : 'Browse all listings'}>
                <meta head-key="description" name="description" content="Rooms and bedspaces for rent across the Philippines: filter by place, price, house rules and move-in date, and hold a space for 24 hours." />
            </Head>
            <Container className="py-4 py-md-5">
                <PageHeader title={filtered ? 'Search results' : 'All listings'} back={{ href: '/', label: 'Home' }}>
                    <p className="page-header__subtitle mb-0" role="status">
                        {places} {filtered ? 'match' : 'to stay'}
                        {filters.sort === 'newest' ? ', newest first' : ', cheapest first'}.
                        {from !== null && moveOut !== null && ` Beds free from ${formatDate(from)} to ${formatDate(moveOut)}.`}
                    </p>
                    {filters.gender !== '' && (
                        <p className="page-header__subtitle mb-0">
                            For {filters.gender === 'female' ? 'women' : 'men'}: {filters.gender === 'female' ? 'women-only' : 'men-only'} places and places open to everyone.
                        </p>
                    )}
                </PageHeader>
                <Row className="g-4">
                    <Col lg={4} xl={3}>
                        <FilterSheet values={filters} options={options} window={window} total={total} active={activeCount(filters)} />
                    </Col>
                    <Col lg={8} xl={9}>
                        {listings.length === 0 ? (
                            <EmptyState
                                Icon={Search}
                                action={
                                    filtered ? (
                                        <Link href="/listings" className="btn btn-outline-primary">
                                            Clear filters
                                        </Link>
                                    ) : undefined
                                }
                            >
                                {filtered ? 'No places match these filters. Try a wider price range, another date, or fewer amenities.' : 'No listings yet. Check back soon.'}
                            </EmptyState>
                        ) : (
                            <Row as="ul" className="g-3 list-unstyled mb-4">
                                {listings.map((listing, index) => (
                                    <Col as="li" key={listing.id} xs={12} sm={6} xl={4}>
                                        <ListingCard listing={listing} level={2} from={from} priority={index === 0} />
                                    </Col>
                                ))}
                            </Row>
                        )}
                        <Pager page={page} lastPage={lastPage} prevHref={prevHref} nextHref={nextHref} />
                    </Col>
                </Row>
            </Container>
        </PublicLayout>
    );
}
