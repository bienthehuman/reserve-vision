import { Head, usePage } from '@inertiajs/react';
import { Button, Col, Container, Form, Row } from 'react-bootstrap';
import { Search } from 'react-bootstrap-icons';
import PublicLayout from '@/Layouts/PublicLayout';

interface HomeProps {
    pitch: string;
}

export default function Home({ pitch }: HomeProps) {
    const { appName } = usePage().props;

    return (
        <PublicLayout>
            <Head title="Find a room or a bed" />
            <section className="hero py-5" aria-labelledby="hero-title">
                <Container>
                    <h1 id="hero-title" className="display-1 mb-3">
                        {appName}
                    </h1>
                    <p className="lead mb-4">{pitch}</p>

                    {/* Placeholder: search arrives in a later slice. A plain GET form so it works without JS. */}
                    <Form role="search" action="/" method="get" className="search-card p-3 p-md-4">
                        <Row className="g-3 align-items-end">
                            <Col xs={12} md={6}>
                                <Form.Group controlId="search-where">
                                    <Form.Label>Where do you want to live?</Form.Label>
                                    <Form.Control type="search" name="where" placeholder="e.g. Sampaloc, Manila" />
                                </Form.Group>
                            </Col>
                            <Col xs={12} md={3}>
                                <Form.Group controlId="search-kind">
                                    <Form.Label>Looking for</Form.Label>
                                    <Form.Select name="kind" defaultValue="">
                                        <option value="">Any place</option>
                                        <option value="apartment">An apartment</option>
                                        <option value="bed">A boarding-house bed</option>
                                    </Form.Select>
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
        </PublicLayout>
    );
}
