import { Head, Link, useForm } from '@inertiajs/react';
import { useId, type FormEvent } from 'react';
import { Badge, Card, Col, Container, Form, Row } from 'react-bootstrap';
import { ExclamationCircle, Telephone } from 'react-bootstrap-icons';
import ConfirmButton from '@/Components/ConfirmButton';
import ErrorSummary from '@/Components/Form/ErrorSummary';
import SubmitButton from '@/Components/Form/SubmitButton';
import TextField, { fieldId } from '@/Components/Form/TextField';
import { useFocusOnError } from '@/Components/Form/useFocusOnError';
import HoldCountdown from '@/Components/HoldCountdown';
import PageHeader from '@/Components/PageHeader';
import PriceTag from '@/Components/PriceTag';
import ProofViewer from '@/Components/ProofViewer';
import StatusBadge from '@/Components/StatusBadge';
import PublicLayout from '@/Layouts/PublicLayout';
import { formatDate, formatDateTime } from '@/lib/dates';
import { formatPeso, pesosText } from '@/lib/money';
import type { ReservationStatus } from '@/types';

type ProofStatus = 'pending' | 'accepted' | 'rejected';

interface Proof {
    id: number;
    amount: number;
    reference_no: string | null;
    status: ProofStatus;
    uploaded_at: string;
    rejection_reason: string | null;
    is_image: boolean;
    url: string;
}

interface ReservationPageProps {
    reservation: {
        id: number;
        status: ReservationStatus;
        is_live_hold: boolean;
        reservation_fee: number;
        hold_expires_at: string | null;
        move_in: string;
        move_out: string;
        space_label: string;
        cancelled_at: string | null;
        can_upload: boolean;
        can_cancel: boolean;
    };
    room: { id: number; name: string };
    property: {
        name: string;
        full_address: string;
        listing_url: string | null;
        house_rules: string | null;
        payment_instructions: string | null;
    };
    landlord: { name: string; contact_phone: string | null };
    proofs: Proof[];
    csrf: string;
}

/** Accept list for the file picker; the server judges the file by its content anyway. */
const ACCEPT = 'image/jpeg,image/png,image/webp,application/pdf';

const FIELDS = ['proof', 'amount', 'reference_no'] as const;

/** The renter's view of a proof's status. "Waiting for review" only while the reservation is still waiting for it. */
function proofStatus(status: ProofStatus, reservation: ReservationStatus): { label: string; color: 'info' | 'success' | 'danger' | 'secondary' } {
    if (status === 'accepted') {
        return { label: 'Accepted', color: 'success' };
    }

    if (status === 'rejected') {
        return { label: 'Not accepted', color: 'danger' };
    }

    return reservation === 'proof_uploaded' ? { label: 'Waiting for review', color: 'info' } : { label: 'Not reviewed', color: 'secondary' };
}

/**
 * The renter's hold page (slice 2.5, completed in 5.3; flow B in ACCESS.md). While the hold is live:
 * the countdown, where to pay, and the form to send the proof. Always: the bed and stay, the landlord's
 * name and phone, the proofs sent, and Cancel when the reservation can still be cancelled.
 */
export default function Show({ reservation, room, property, landlord, proofs, csrf }: ReservationPageProps) {
    const title = reservation.is_live_hold ? 'Your hold' : 'Your reservation';

    return (
        <PublicLayout>
            <Head title={title} />
            <Container className="py-4 py-md-5">
                <Row className="justify-content-center">
                    <Col xs={12} md={10} lg={8} xl={7}>
                        <PageHeader title={title} back={{ href: '/reservations', label: 'My reservations' }}>
                            <p className="mt-2 mb-0">
                                <StatusBadge status={reservation.status} />
                            </p>
                        </PageHeader>

                        {reservation.can_upload && reservation.hold_expires_at && (
                            <Card className="mb-4">
                                <Card.Body>
                                    <h2 className="h3 mb-3">Pay the reservation fee</h2>
                                    <HoldCountdown expiresAt={reservation.hold_expires_at} />
                                    <p>
                                        Pay <strong>{formatPeso(reservation.reservation_fee)}</strong> to {landlord.name}, then send your proof of
                                        payment below before the hold ends.
                                    </p>
                                    {property.payment_instructions && (
                                        <>
                                            <h3 className="h5">How to pay</h3>
                                            <p className="pre-line" data-testid="payment-instructions">
                                                {property.payment_instructions}
                                            </p>
                                        </>
                                    )}
                                    <Landlord landlord={landlord} />
                                </Card.Body>
                            </Card>
                        )}

                        {reservation.can_upload && <UploadForm reservation={reservation} csrf={csrf} />}

                        <Card className="mb-4">
                            <Card.Body>
                                <h2 className="h3 mb-3">Your stay</h2>
                                <dl className="details row mb-0">
                                    <dt className="col-sm-5">Reserved for you</dt>
                                    <dd className="col-sm-7" data-testid="space-label">
                                        {reservation.space_label}
                                    </dd>
                                    <dt className="col-sm-5">Room</dt>
                                    <dd className="col-sm-7">
                                        {property.listing_url ? (
                                            <a href={`${property.listing_url}#room-${room.id}`} className="link-action">
                                                {room.name}, {property.name}
                                            </a>
                                        ) : (
                                            `${room.name}, ${property.name}`
                                        )}
                                        <span className="d-block small text-body-secondary">{property.full_address}</span>
                                    </dd>
                                    <dt className="col-sm-5">Stay</dt>
                                    <dd className="col-sm-7">
                                        {formatDate(reservation.move_in)} to {formatDate(reservation.move_out)}
                                    </dd>
                                    <dt className="col-sm-5">Reservation fee</dt>
                                    <dd className="col-sm-7">
                                        <PriceTag centavos={reservation.reservation_fee} />
                                    </dd>
                                    {reservation.hold_expires_at && !reservation.can_upload && reservation.status === 'expired' && (
                                        <>
                                            <dt className="col-sm-5">Hold ended</dt>
                                            <dd className="col-sm-7">{formatDateTime(reservation.hold_expires_at)} (Philippine time)</dd>
                                        </>
                                    )}
                                    {reservation.cancelled_at && (
                                        <>
                                            <dt className="col-sm-5">Cancelled</dt>
                                            <dd className="col-sm-7">{formatDateTime(reservation.cancelled_at)} (Philippine time)</dd>
                                        </>
                                    )}
                                </dl>
                                {!reservation.can_upload && (
                                    <>
                                        <hr />
                                        <Landlord landlord={landlord} />
                                    </>
                                )}
                            </Card.Body>
                        </Card>

                        {proofs.length > 0 && (
                            <section className="mb-4" aria-labelledby="proofs-title">
                                <h2 id="proofs-title" className="h3 mb-3">
                                    Proofs you sent
                                </h2>
                                <ul className="proof-list">
                                    {proofs.map((proof) => {
                                        const shown = proofStatus(proof.status, reservation.status);
                                        const label = `Your proof of payment, sent ${formatDateTime(proof.uploaded_at)}`;

                                        return (
                                            <li key={proof.id}>
                                                <Card>
                                                    <Card.Body>
                                                        <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                                                            <span className="fw-semibold">Sent {formatDateTime(proof.uploaded_at)}</span>
                                                            <Badge pill bg="" className={`status-badge status-badge--${shown.color}`}>
                                                                {shown.label}
                                                            </Badge>
                                                        </div>
                                                        <dl className="details row">
                                                            <dt className="col-6 col-sm-5">Amount</dt>
                                                            <dd className="col-6 col-sm-7">
                                                                <PriceTag centavos={proof.amount} />
                                                            </dd>
                                                            <dt className="col-6 col-sm-5">Reference number</dt>
                                                            <dd className="col-6 col-sm-7 text-break">{proof.reference_no ?? 'Not given'}</dd>
                                                            {proof.rejection_reason && (
                                                                <>
                                                                    <dt className="col-sm-5">Why it wasn't accepted</dt>
                                                                    <dd className="col-sm-7">{proof.rejection_reason}</dd>
                                                                </>
                                                            )}
                                                        </dl>
                                                        <ProofViewer proof={proof} label={label} />
                                                    </Card.Body>
                                                </Card>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </section>
                        )}

                        {reservation.can_cancel && (
                            <Card className="mb-4">
                                <Card.Body>
                                    <h2 className="h3">Cancel this reservation</h2>
                                    <p>Your place is freed for others at once. You can't undo this.</p>
                                    <div className="button-row">
                                        <ConfirmButton
                                            href={`/reservations/${reservation.id}/cancel`}
                                            method="post"
                                            size="md"
                                            title="Cancel this reservation?"
                                            body={<CancelWarning houseRules={property.house_rules} paid={proofs.length > 0} />}
                                            confirmLabel="Cancel reservation"
                                            pendingLabel="Cancelling…"
                                            cancelLabel="Keep it"
                                        >
                                            Cancel reservation
                                        </ConfirmButton>
                                    </div>
                                </Card.Body>
                            </Card>
                        )}

                        <p className="mb-0">
                            <Link href="/reservations" className="back-link">
                                All my reservations
                            </Link>
                        </p>
                    </Col>
                </Row>
            </Container>
        </PublicLayout>
    );
}

function Landlord({ landlord }: { landlord: ReservationPageProps['landlord'] }) {
    return (
        <dl className="details row mb-0">
            <dt className="col-sm-5">Landlord</dt>
            <dd className="col-sm-7">{landlord.name}</dd>
            {landlord.contact_phone && (
                <>
                    <dt className="col-sm-5">Phone</dt>
                    <dd className="col-sm-7 mb-0">
                        <a href={`tel:${landlord.contact_phone}`} className="link-action d-inline-flex align-items-center gap-1">
                            <Telephone aria-hidden="true" focusable="false" />
                            {landlord.contact_phone}
                        </a>
                    </dd>
                </>
            )}
        </dl>
    );
}

/** The dialog's warning: the fee follows the house rules, quoted. */
function CancelWarning({ houseRules, paid }: { houseRules: string | null; paid: boolean }) {
    return (
        <>
            <p>
                {paid
                    ? 'Whether your reservation fee is refunded is up to the house rules. The landlord records it.'
                    : "You haven't sent a proof of payment, so there is no fee to refund."}
            </p>
            {paid &&
                (houseRules ? (
                    <figure className="mb-0">
                        <figcaption className="small text-body-secondary mb-1">The house rules say:</figcaption>
                        <blockquote className="pre-line border-start border-3 ps-3 mb-0">{houseRules}</blockquote>
                    </figure>
                ) : (
                    <p className="mb-0">This place has no written house rules. Ask the landlord about a refund.</p>
                ))}
        </>
    );
}

/**
 * The proof form. A plain multipart form post (the `_token` is in it), so it also works before the
 * page's JavaScript has loaded; once hydrated, Inertia sends it and shows the errors in place.
 */
function UploadForm({ reservation, csrf }: { reservation: ReservationPageProps['reservation']; csrf: string }) {
    const form = useForm<{ proof: File | null; amount: string; reference_no: string }>({
        proof: null,
        amount: pesosText(reservation.reservation_fee),
        reference_no: '',
    });
    const { ref: summaryRef, onError } = useFocusOnError();
    const helpId = useId();
    const errorId = useId();
    const action = `/reservations/${reservation.id}/proofs`;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(action, { forceFormData: true, preserveScroll: true, onError, onSuccess: () => form.reset() });
    };

    return (
        <Card className="mb-4">
            <Card.Body>
                <h2 className="h3 mb-3">Send your proof of payment</h2>
                <ErrorSummary ref={summaryRef} errors={form.errors} order={FIELDS} />
                <form method="post" action={action} encType="multipart/form-data" noValidate onSubmit={submit} aria-label="Proof of payment">
                    <input type="hidden" name="_token" value={csrf} />
                    <Form.Group className="mb-3" controlId={fieldId('proof')}>
                        <Form.Label>Screenshot, photo or PDF of the receipt</Form.Label>
                        <Form.Control
                            type="file"
                            name="proof"
                            accept={ACCEPT}
                            isInvalid={Boolean(form.errors.proof)}
                            aria-describedby={form.errors.proof ? `${helpId} ${errorId}` : helpId}
                            onChange={(event) => form.setData('proof', (event.target as HTMLInputElement).files?.[0] ?? null)}
                        />
                        <Form.Text id={helpId}>JPEG, PNG, WebP or PDF, up to 5 MB. Make sure the amount and reference number can be read.</Form.Text>
                        {form.errors.proof && (
                            <div id={errorId} className="invalid-feedback d-flex gap-1">
                                <ExclamationCircle aria-hidden="true" focusable="false" className="flex-shrink-0 mt-1" />
                                <span>{form.errors.proof}</span>
                            </div>
                        )}
                    </Form.Group>
                    <Row className="g-3 mb-3">
                        <Col sm={6}>
                            <TextField
                                name="amount"
                                label="Amount you paid (₱)"
                                value={form.data.amount}
                                onChange={(value) => form.setData('amount', value)}
                                error={form.errors.amount}
                                inputMode="decimal"
                                autoComplete="off"
                                className="mb-0"
                            />
                        </Col>
                        <Col sm={6}>
                            <TextField
                                name="reference_no"
                                label="Reference number"
                                value={form.data.reference_no}
                                onChange={(value) => form.setData('reference_no', value)}
                                error={form.errors.reference_no}
                                autoComplete="off"
                                help="From the receipt, e.g. the GCash or bank reference."
                                className="mb-0"
                            />
                        </Col>
                    </Row>
                    <div className="button-row">
                        <SubmitButton processing={form.processing} pendingLabel="Sending…" className="">
                            Send proof
                        </SubmitButton>
                    </div>
                </form>
            </Card.Body>
        </Card>
    );
}
