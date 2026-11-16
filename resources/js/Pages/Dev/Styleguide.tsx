import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Alert, Button, Card, Col, Container, Form, Row, Table } from 'react-bootstrap';
import { ArrowLeft, ExclamationCircle } from 'react-bootstrap-icons';
import Pager from '@/Components/Pager';
import ComboboxField from '@/Components/Form/ComboboxField';
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
                        <Button variant="outline-danger">Delete photo</Button>
                        <Button variant="danger">Cancel reservation</Button>
                        <Button variant="primary" disabled>
                            Reserved
                        </Button>
                    </div>
                    <p className="small text-body-secondary mt-3 mb-2">Small, for rows and cards. Same radius, never next to a full-size control.</p>
                    <div className="d-flex flex-wrap gap-2">
                        <Button size="sm" variant="primary">
                            Add a bed
                        </Button>
                        <Button size="sm" variant="outline-primary">
                            Rename
                        </Button>
                        <Button size="sm" variant="outline-danger">
                            Delete
                        </Button>
                    </div>
                    <p className="small text-body-secondary mt-3 mb-2">One height: a field, a dropdown and a button on one line.</p>
                    <div className="toolbar__filters">
                        <Form.Group controlId="sg-filter">
                            <Form.Label>Status</Form.Label>
                            <Form.Select defaultValue="held">
                                <option value="">All statuses</option>
                                <option value="held">On hold</option>
                                <option value="confirmed">Confirmed</option>
                            </Form.Select>
                        </Form.Group>
                        <Button variant="outline-primary">Filter</Button>
                        <Button variant="primary">Search</Button>
                    </div>
                </Section>

                <Section id="links" title="Links">
                    <p>
                        In a sentence a link keeps its underline: <a href="#links">read the house rules</a> before you reserve.
                    </p>
                    <p className="d-flex flex-wrap gap-4 align-items-center">
                        <a href="#links" className="back-link">
                            <ArrowLeft aria-hidden="true" focusable="false" />
                            Back to properties
                        </a>
                        <a href="#links" className="link-plain">
                            Santos Bedspace House
                        </a>
                        <a href="#links" className="link-action">
                            Forgot your password?
                        </a>
                    </p>
                </Section>

                <Section id="surfaces" title="Surfaces">
                    <Row className="g-3">
                        <Col md={6}>
                            <Card>
                                <Card.Body>
                                    <h3 className="mb-3">Card</h3>
                                    <dl className="details row">
                                        <dt className="col-sm-5">Monthly rate</dt>
                                        <dd className="col-sm-7">
                                            <PriceTag centavos={650000} per="month" />
                                        </dd>
                                        <dt className="col-sm-5">Who can stay</dt>
                                        <dd className="col-sm-7">Anyone</dd>
                                    </dl>
                                </Card.Body>
                            </Card>
                        </Col>
                        <Col md={6}>
                            <Card className="table-card">
                                <Table className="stack-table align-middle">
                                    <thead>
                                        <tr>
                                            <th scope="col">Renter</th>
                                            <th scope="col">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td data-label="Renter">
                                                <a href="#surfaces" className="link-plain">
                                                    Jasmine Ocampo
                                                </a>
                                            </td>
                                            <td data-label="Status">
                                                <StatusBadge status="held" />
                                            </td>
                                        </tr>
                                        <tr>
                                            <td data-label="Renter">
                                                <a href="#surfaces" className="link-plain">
                                                    Nicole Mendoza
                                                </a>
                                            </td>
                                            <td data-label="Status">
                                                <StatusBadge status="confirmed" />
                                            </td>
                                        </tr>
                                    </tbody>
                                </Table>
                            </Card>
                        </Col>
                    </Row>
                    <Pager page={1} lastPage={22} prevHref={null} nextHref="#surfaces" />
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

                <Section id="dependent-dropdowns" title="Dropdowns you can type into">
                    <p className="small text-body-secondary">
                        For lists too long to scroll (slice 4.4, the address): type to narrow the list, accents and word order don&apos;t
                        matter. One that needs another choice says so while it waits; a list that didn&apos;t load offers a retry.
                    </p>
                    <Form noValidate className="col-md-6">
                        <ComboboxField
                            name="sg-barangay-grouped"
                            label="Barangay in Manila"
                            value="2"
                            onChange={() => undefined}
                            options={[
                                { value: '1', label: 'Barangay 659', group: 'Ermita' },
                                { value: '2', label: 'Barangay 395', group: 'Sampaloc' },
                                { value: '3', label: 'Barangay 396', group: 'Sampaloc' },
                            ]}
                            help="Type part of the name, or a district (Sampaloc)."
                        />
                        <ComboboxField
                            name="sg-barangay-waiting"
                            label="Barangay"
                            value=""
                            onChange={() => undefined}
                            options={[]}
                            placeholder="Choose a city first."
                            disabled
                        />
                        <ComboboxField
                            name="sg-city-failed"
                            label="City or municipality"
                            value=""
                            onChange={() => undefined}
                            options={[]}
                            placeholder="Couldn't load the cities and municipalities"
                            className="mb-2"
                            disabled
                        />
                        <div className="place-error mb-3" role="status">
                            <span>We couldn&apos;t load the cities and municipalities. Check your connection, then try again.</span>
                            <Button variant="outline-primary">Try again</Button>
                        </div>
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
