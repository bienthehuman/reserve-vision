import { Head } from '@inertiajs/react';
import { Col, Container, Row } from 'react-bootstrap';
import { House } from 'react-bootstrap-icons';
import EmptyState from '@/Components/EmptyState';
import ListingCard, { type ListingCardData } from '@/Components/ListingCard';
import PageHeader from '@/Components/PageHeader';
import Pager from '@/Components/Pager';
import PublicLayout from '@/Layouts/PublicLayout';

interface IndexProps {
    listings: ListingCardData[];
    total: number;
    page: number;
    lastPage: number;
    prevHref: string | null;
    nextHref: string | null;
}

/** Every published listing, newest first. Slice 5.2 turns this into search results. */
export default function Index({ listings, total, page, lastPage, prevHref, nextHref }: IndexProps) {
    return (
        <PublicLayout>
            <Head title="Browse all listings" />
            <Container className="py-4 py-md-5">
                <PageHeader title="All listings" back={{ href: '/', label: 'Home' }}>
                    <p className="page-header__subtitle mb-0">
                        {total === 1 ? '1 place' : `${total} places`} to stay, newest first.
                    </p>
                </PageHeader>
                {listings.length === 0 ? (
                    <EmptyState Icon={House}>No listings yet. Check back soon.</EmptyState>
                ) : (
                    <Row as="ul" className="g-3 list-unstyled mb-4">
                        {listings.map((listing) => (
                            <Col as="li" key={listing.id} xs={12} sm={6} lg={4}>
                                <ListingCard listing={listing} level={2} />
                            </Col>
                        ))}
                    </Row>
                )}
                <Pager page={page} lastPage={lastPage} prevHref={prevHref} nextHref={nextHref} />
            </Container>
        </PublicLayout>
    );
}
