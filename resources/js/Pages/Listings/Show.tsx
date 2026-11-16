import { Head, Link, usePage } from '@inertiajs/react';
import { Alert, Badge, Card, Col, Container, Form, Row } from 'react-bootstrap';
import { BoxArrowUpRight, GeoAlt, InfoCircle } from 'react-bootstrap-icons';
import ListingPhotos, { type ListingPhoto } from '@/Components/ListingPhotos';
import PriceTag from '@/Components/PriceTag';
import StickyReserveBar from '@/Components/StickyReserveBar';
import PublicLayout from '@/Layouts/PublicLayout';
import { formatDate } from '@/lib/dates';
import { genderLabel, kindLabel, type GenderPolicy, type PropertyKind } from '@/lib/labels';

interface RoomData {
    id: number;
    name: string;
    type: 'private' | 'shared';
    monthly_rate: number;
    reservation_fee: number;
    deposit_months: number;
    advance_months: number;
    space_count: number;
    free_spaces: number;
    open_reservation: { id: number; space_label: string } | null;
}

interface ListingPageProps {
    listing: {
        name: string;
        kind: PropertyKind;
        gender_policy: GenderPolicy;
        address: string;
        maps_url: string;
        house_rules: string | null;
        landlord: string | null;
        from_rate: number | null;
        amenities: string[];
        photos: ListingPhoto[];
    };
    rooms: RoomData[];
    stay: { move_in: string; months: number; move_out: string };
    window: { first: string; last: string; min_months: number; max_months: number };
    listingUrl: string;
    csrf: string;
    seo: {
        title: string;
        description: string;
        canonical: string;
        image: { url: string; width: number; height: number; alt: string } | null;
        json_ld: string;
        nonce: string;
    };
}

function months(count: number): string {
    return count === 1 ? '1 month' : `${count} months`;
}

function bedWord(count: number): string {
    return count === 1 ? 'bed' : 'beds';
}

function freeText(room: RoomData): string {
    if (room.type === 'private') {
        return room.free_spaces > 0 ? 'The room is free' : 'The room is taken';
    }

    return `${room.free_spaces} of ${room.space_count} ${bedWord(room.space_count)} free`;
}

/** The head: title, description, canonical, Open Graph, Twitter card and JSON-LD. `head-key` makes each one appear once, also after a client-side visit. */
function ListingHead({ seo }: Pick<ListingPageProps, 'seo'>) {
    return (
        <Head title={seo.title}>
            <meta head-key="description" name="description" content={seo.description} />
            <link head-key="canonical" rel="canonical" href={seo.canonical} />
            <meta head-key="og:type" property="og:type" content="website" />
            <meta head-key="og:locale" property="og:locale" content="en_PH" />
            <meta head-key="og:title" property="og:title" content={seo.title} />
            <meta head-key="og:description" property="og:description" content={seo.description} />
            <meta head-key="og:url" property="og:url" content={seo.canonical} />
            {seo.image && <meta head-key="og:image" property="og:image" content={seo.image.url} />}
            {seo.image && <meta head-key="og:image:width" property="og:image:width" content={String(seo.image.width)} />}
            {seo.image && <meta head-key="og:image:height" property="og:image:height" content={String(seo.image.height)} />}
            {seo.image && <meta head-key="og:image:alt" property="og:image:alt" content={seo.image.alt} />}
            <meta head-key="twitter:card" name="twitter:card" content="summary_large_image" />
            <script head-key="json-ld" type="application/ld+json" nonce={seo.nonce}>
                {seo.json_ld}
            </script>
        </Head>
    );
}

export default function Show({ listing, rooms, stay, window, listingUrl, csrf, seo }: ListingPageProps) {
    const { auth, errors } = usePage().props;
    const user = auth.user;
    const isLandlord = user?.type === 'landlord';
    const errorList = Object.values((errors ?? {}) as Record<string, string>);
    const stayText = `${formatDate(stay.move_in)} to ${formatDate(stay.move_out)}`;

    return (
        <PublicLayout>
            <ListingHead seo={seo} />
            <Container className="py-3 py-md-4">
                <Row className="justify-content-center">
                    <Col xs={12} lg={10} xl={9}>
                        <ListingPhotos photos={listing.photos} />

                        <header className="listing-header">
                            <h1 className="h2 mb-2">{listing.name}</h1>
                            <p className="d-flex flex-wrap gap-1 mb-2">
                                <Badge bg="light" text="dark" className="listing-chip">
                                    {kindLabel[listing.kind]}
                                </Badge>
                                <Badge bg="light" text="dark" className="listing-chip">
                                    {genderLabel[listing.gender_policy]}
                                </Badge>
                            </p>
                            <p className="d-flex align-items-start gap-1 mb-1">
                                <GeoAlt aria-hidden="true" focusable="false" className="flex-shrink-0 mt-1" />
                                <span>{listing.address}</span>
                            </p>
                            <p className="mb-2">
                                <a href={listing.maps_url} target="_blank" rel="noopener noreferrer" className="link-action d-inline-flex align-items-center gap-1">
                                    Open in Maps
                                    <BoxArrowUpRight aria-hidden="true" focusable="false" />
                                    <span className="visually-hidden">(opens Google Maps in a new tab)</span>
                                </a>
                            </p>
                            {listing.landlord && <p className="small text-body-secondary mb-2">Listed by {listing.landlord}</p>}
                            {listing.from_rate !== null && (
                                <p className="mb-0">
                                    <span className="text-body-secondary">From </span>
                                    <PriceTag centavos={listing.from_rate} per="month" />
                                </p>
                            )}
                        </header>

                        <Row className="g-3 mb-4">
                            <Col xs={12} md={6}>
                                <Card className="h-100">
                                    <Card.Body>
                                        <h2 className="h4 mb-3">Amenities</h2>
                                        {listing.amenities.length > 0 ? (
                                            <ul className="amenity-list">
                                                {listing.amenities.map((name) => (
                                                    <li key={name}>{name}</li>
                                                ))}
                                            </ul>
                                        ) : (
                                            <p className="text-body-secondary mb-0">No amenities listed.</p>
                                        )}
                                    </Card.Body>
                                </Card>
                            </Col>
                            <Col xs={12} md={6}>
                                <Card className="h-100">
                                    <Card.Body>
                                        <h2 className="h4 mb-3">House rules and refunds</h2>
                                        <p className="pre-line mb-0">{listing.house_rules || "The landlord hasn't written house rules yet."}</p>
                                    </Card.Body>
                                </Card>
                            </Col>
                        </Row>

                        <section id="rooms" className="rooms-section" aria-labelledby="rooms-title">
                            <h2 id="rooms-title" className="h3 mb-3">
                                Rooms
                            </h2>

                            <Card className="mb-3">
                                <Card.Body>
                                    {/* A plain GET form: choosing a stay reloads the page with the free beds for it. */}
                                    <Form method="get" action={`${listingUrl}#rooms`} aria-label="Choose your stay">
                                        <Row className="g-3 align-items-end">
                                            <Col xs={12} sm={5}>
                                                <Form.Group controlId="stay-move_in">
                                                    <Form.Label>Move-in date</Form.Label>
                                                    <Form.Control type="date" name="move_in" defaultValue={stay.move_in} min={window.first} max={window.last} required />
                                                </Form.Group>
                                            </Col>
                                            <Col xs={12} sm={4}>
                                                <Form.Group controlId="stay-months">
                                                    <Form.Label>How long you'll stay</Form.Label>
                                                    <Form.Select name="months" defaultValue={String(stay.months)}>
                                                        {Array.from({ length: window.max_months - window.min_months + 1 }, (_, i) => window.min_months + i).map((count) => (
                                                            <option key={count} value={count}>
                                                                {months(count)}
                                                            </option>
                                                        ))}
                                                    </Form.Select>
                                                </Form.Group>
                                            </Col>
                                            <Col xs={12} sm={3}>
                                                <button type="submit" className="btn btn-outline-primary w-100">
                                                    Check free beds
                                                </button>
                                            </Col>
                                        </Row>
                                        <p className="small text-body-secondary mt-2 mb-0">
                                            You can move in from {formatDate(window.first)} to {formatDate(window.last)}.
                                        </p>
                                    </Form>
                                </Card.Body>
                            </Card>

                            {errorList.length > 0 && (
                                <Alert variant="danger" role="alert">
                                    <ul className="mb-0">
                                        {errorList.map((message) => (
                                            <li key={message}>{message}</li>
                                        ))}
                                    </ul>
                                </Alert>
                            )}

                            {isLandlord && (
                                <Alert variant="info" className="d-flex gap-2 align-items-start">
                                    <InfoCircle aria-hidden="true" focusable="false" className="flex-shrink-0 mt-1" />
                                    <div>You're logged in as a landlord. To reserve a room, log in with a renter account.</div>
                                </Alert>
                            )}

                            {rooms.length === 0 && <p className="text-body-secondary">No rooms are open for reservation right now.</p>}

                            <div className="d-grid gap-3">
                                {rooms.map((room) => (
                                    <Card as="article" key={room.id} id={`room-${room.id}`} className="room-card" aria-labelledby={`room-${room.id}-name`}>
                                        <Card.Body>
                                            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                                                <div>
                                                    <h3 id={`room-${room.id}-name`} className="h5 mb-1">
                                                        {room.name}
                                                    </h3>
                                                    <p className="small text-body-secondary mb-0">
                                                        {room.type === 'private' ? 'Private room' : `Shared room, ${room.space_count} ${bedWord(room.space_count)}`}
                                                    </p>
                                                </div>
                                                <PriceTag centavos={room.monthly_rate} per="month" />
                                            </div>

                                            <dl className="details row mb-3">
                                                <dt className="col-6 col-sm-4">Reservation fee</dt>
                                                <dd className="col-6 col-sm-8">
                                                    <PriceTag centavos={room.reservation_fee} />
                                                </dd>
                                                <dt className="col-6 col-sm-4">Deposit</dt>
                                                <dd className="col-6 col-sm-8">{months(room.deposit_months)}</dd>
                                                <dt className="col-6 col-sm-4">Advance</dt>
                                                <dd className="col-6 col-sm-8 mb-0">{months(room.advance_months)}</dd>
                                            </dl>

                                            <p className="mb-3" data-testid={`free-spaces-${room.id}`}>
                                                <strong>{freeText(room)}</strong> for {stayText}.
                                            </p>

                                            {room.open_reservation ? (
                                                <Alert variant="info" className="d-flex gap-2 align-items-start mb-0">
                                                    <InfoCircle aria-hidden="true" focusable="false" className="flex-shrink-0 mt-1" />
                                                    <div>
                                                        You have {room.open_reservation.space_label} in this room on hold.{' '}
                                                        <a href={`/reservations/${room.open_reservation.id}`} className="alert-link">
                                                            View your hold
                                                        </a>
                                                    </div>
                                                </Alert>
                                            ) : (
                                                !isLandlord && (
                                                    // A plain form post: it works without JavaScript. The stay is the one chosen above.
                                                    <form method="post" action={`/rooms/${room.id}/reservations`}>
                                                        <input type="hidden" name="_token" value={csrf} />
                                                        <input type="hidden" name="move_in" value={stay.move_in} />
                                                        <input type="hidden" name="months" value={stay.months} />
                                                        <button type="submit" className="btn btn-primary" aria-label={`Reserve ${room.name}`}>
                                                            Reserve
                                                        </button>
                                                        <p className="small text-body-secondary mt-2 mb-0">
                                                            {user
                                                                ? 'We hold it for you for 24 hours while you pay the reservation fee.'
                                                                : "You'll log in first. We then hold it for you for 24 hours while you pay the reservation fee."}
                                                        </p>
                                                    </form>
                                                )
                                            )}
                                        </Card.Body>
                                    </Card>
                                ))}
                            </div>
                        </section>

                        <p className="mt-4 mb-0">
                            <Link href="/listings" className="back-link">
                                Browse all listings
                            </Link>
                        </p>
                    </Col>
                </Row>
            </Container>
            <StickyReserveBar fromRate={listing.from_rate} />
        </PublicLayout>
    );
}
