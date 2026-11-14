import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Alert, Button, Col, Container, Form, Row } from 'react-bootstrap';
import { ExclamationCircle } from 'react-bootstrap-icons';
import PriceTag from '@/Components/PriceTag';
import StatusBadge from '@/Components/StatusBadge';
import PublicLayout from '@/Layouts/PublicLayout';
import { designTokens } from '@/lib/designTokens';
import { reservationStatuses } from '@/types/reservation';

function Section({ id, title, children }: { id: string; title: string; children: ReactNode }) {
    return (
        <section className="mb-5" aria-labelledby={id}>
            <h2 id={id} className="mb-3">
                {title}
            </h2>
            {children}
        </section>
    );
}

export default function Styleguide() {
    return (
        <PublicLayout>
            <Head title="Styleguide" />
            <Container className="py-5">
                <h1 className="mb-2">Styleguide</h1>
                <p className="text-body-secondary mb-5">
                    Every token and component from docs/DESIGN_SYSTEM.md. Development only; this page is a 404 in production.
                </p>

                <Section id="colors" title="Color">
                    <Row xs={2} sm={3} lg={5} className="g-3" as="ul" role="list" aria-label="Color tokens">
                        {designTokens.map((token) => (
                            <Col as="li" key={token.name} className="list-unstyled">
                                <div className={`swatch swatch--${token.name} mb-2`} aria-hidden="true" />
                                <div className="fw-semibold">{token.name}</div>
                                <div className="small text-body-secondary font-monospace">{token.hex}</div>
                                <div className="small text-body-secondary">{token.use}</div>
                            </Col>
                        ))}
                    </Row>
                </Section>

                <Section id="type" title="Type scale">
                    <p className="display-1">Display: Find your next room</p>
                    <p className="h1">h1: Sampaguita Boarding House</p>
                    <p className="h2">h2: Rooms and beds</p>
                    <p className="h3">h3: Room 2, lower bunk</p>
                    <p>Body: Your hold ends in 23 hours. Upload proof of the reservation fee before then.</p>
                    <p className="small text-body-secondary">Small: Listed Mon, 1 Jun 2026 · Sampaloc, Manila</p>
                    <p>
                        Price: <PriceTag centavos={650000} per="month" /> · Fee: <PriceTag centavos={150000} />
                    </p>
                </Section>

                <Section id="buttons" title="Buttons">
                    <div className="d-flex flex-wrap gap-2">
                        <Button variant="primary">Reserve</Button>
                        <Button variant="outline-primary">Save for later</Button>
                        <Button variant="danger">Cancel reservation</Button>
                        <Button variant="link">View house rules</Button>
                        <Button variant="primary" disabled>
                            Reserved
                        </Button>
                    </div>
                </Section>

                <Section id="forms" title="Form field with an error">
                    <Form noValidate className="col-md-6">
                        <Form.Group className="mb-3" controlId="sg-name">
                            <Form.Label>Full name</Form.Label>
                            <Form.Control type="text" defaultValue="Juan dela Cruz" aria-describedby="sg-name-help" />
                            <Form.Text id="sg-name-help">As it appears on your payment.</Form.Text>
                        </Form.Group>
                        <Form.Group className="mb-3" controlId="sg-phone">
                            <Form.Label>Mobile number</Form.Label>
                            <Form.Control
                                type="tel"
                                defaultValue="0917"
                                isInvalid
                                aria-invalid="true"
                                aria-describedby="sg-phone-error"
                                placeholder="e.g. 0917 123 4567"
                            />
                            <Form.Control.Feedback type="invalid" id="sg-phone-error">
                                <ExclamationCircle aria-hidden="true" focusable="false" />
                                Enter an 11-digit mobile number, like 0917 123 4567.
                            </Form.Control.Feedback>
                        </Form.Group>
                        <Form.Check type="checkbox" id="sg-rules" label="I have read the house rules" />
                    </Form>
                </Section>

                <Section id="alerts" title="Alerts">
                    <Alert variant="success">Your reservation is confirmed. Move in from Mon, 1 Jun 2026.</Alert>
                    <Alert variant="warning">Your hold ends in 23 hours. Upload proof of payment to keep it.</Alert>
                    <Alert variant="danger">The landlord declined the proof you uploaded. See their note below.</Alert>
                    <Alert variant="info">The landlord is reviewing your proof of payment.</Alert>
                </Section>

                <Section id="status" title="Reservation status">
                    <ul className="list-unstyled d-flex flex-wrap gap-2" aria-label="Reservation statuses">
                        {reservationStatuses.map((status) => (
                            <li key={status}>
                                <StatusBadge status={status} detail={status === 'held' ? '23h left' : undefined} />
                            </li>
                        ))}
                    </ul>
                    <p className="mt-3">
                        Highlight: <span className="badge rounded-pill bg-mango">Last bed</span>
                    </p>
                </Section>
            </Container>
        </PublicLayout>
    );
}
