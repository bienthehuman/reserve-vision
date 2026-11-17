import { Head, Link, usePage } from '@inertiajs/react';
import { Button, Col, Container, Form, Row } from 'react-bootstrap';
import { Search } from 'react-bootstrap-icons';
import ListingCard, { type ListingCardData } from '@/Components/ListingCard';
import PublicLayout from '@/Layouts/PublicLayout';

interface HomeProps {
    pitch: string;
    cities: { value: string; label: string }[];
    window: { first: string; last: string };
    featured: ListingCardData[];
}

export default function Home({ pitch, cities, window, featured }: HomeProps) {
    const { appName } = usePage().props;

    return (
        <PublicLayout>
            <Head title="Find a room or a bed">
                <meta head-key="description" name="description" content={pitch} />
            </Head>
            <section className="hero py-5" aria-labelledby="hero-title">
                <Container>
                    <h1 id="hero-title" className="display-1 mb-3">
                        {appName}
                    </h1>
                    <p className="lead mb-4">{pitch}</p>

                    {/* A plain GET form to the search page, so it works without JS. */}
                    <Form role="search" action="/listings" method="get" className="search-card p-3 p-md-4">
                        <Row className="g-3 align-items-end">
                            <Col xs={12} md={4}>
                                <Form.Group controlId="search-where">
                                    <Form.Label>Where do you want to live?</Form.Label>
                                    <Form.Select name="city" defaultValue="">
                                        <option value="">Anywhere</option>
                                        {cities.map((city) => (
                                            <option key={city.value} value={city.value}>
                                                {city.label}
                                            </option>
                                        ))}
                                    </Form.Select>
                                </Form.Group>
                            </Col>
                            <Col xs={6} md={3}>
                                <Form.Group controlId="search-move-in">
                                    <Form.Label>Move in</Form.Label>
                                    <Form.Control type="date" name="move_in" min={window.first} max={window.last} />
                                </Form.Group>
                            </Col>
                            <Col xs={6} md={2}>
                                <Form.Group controlId="search-max-price">
                                    <Form.Label>Up to (₱/mo)</Form.Label>
                                    <Form.Control type="number" name="max_price" min={0} step={100} inputMode="numeric" placeholder="8000" />
                                </Form.Group>
                            </Col>
                            <Col xs={12} md={3}>
                                <Button type="submit" variant="primary" className="w-100 d-inline-flex align-items-center justify-content-center gap-2">
                                    <Search aria-hidden="true" focusable="false" />
                                    Search
                                </Button>
                            </Col>
                        </Row>
                    </Form>
                </Container>
            </section>

            {featured.length > 0 && (
                <section className="py-5" aria-labelledby="featured-title">
                    <Container>
                        <div className="d-flex flex-wrap justify-content-between align-items-baseline gap-2 mb-3">
                            <h2 id="featured-title" className="h3 mb-0">
                                New places
                            </h2>
                            <Link href="/listings" className="link-action">
                                Browse all
                            </Link>
                        </div>
                        <Row as="ul" className="g-3 list-unstyled mb-0">
                            {featured.map((listing, index) => (
                                <Col as="li" key={listing.id} xs={12} sm={6} lg={4}>
                                    <ListingCard listing={listing} priority={index === 0} />
                                </Col>
                            ))}
                        </Row>
                    </Container>
                </section>
            )}
        </PublicLayout>
    );
}
