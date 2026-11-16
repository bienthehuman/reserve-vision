import { useForm } from '@inertiajs/react';
import { useId, type FormEvent } from 'react';
import { Card, Form } from 'react-bootstrap';
import { ExclamationCircle } from 'react-bootstrap-icons';
import SubmitButton from '@/Components/Form/SubmitButton';
import TextAreaField from '@/Components/Form/TextAreaField';
import type { Decisions } from '@/Components/ReservationDecisions';

interface RefundCardProps {
    reservationId: number;
    refund: NonNullable<Decisions['refund']>;
}

/** "Refunded", "Not refunded" or "Not recorded yet". */
export function refundLabel(refunded: boolean | null): string {
    return refunded === null ? 'Not recorded yet' : refunded ? 'Refunded' : 'Not refunded';
}

/**
 * The refund record on a declined or cancelled reservation that had a proof (slice 6.1, RESERVATIONS.md "Refunds"): the
 * system moves no money, it keeps what the landlord says. Can be changed again; every change is in the audit log.
 */
export default function RefundCard({ reservationId, refund }: RefundCardProps) {
    const form = useForm<{ fee_refunded: '' | '1' | '0'; refund_note: string }>({
        fee_refunded: refund.fee_refunded === null ? '' : refund.fee_refunded ? '1' : '0',
        refund_note: refund.refund_note ?? '',
    });
    const errorId = useId();

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(`/landlord/reservations/${reservationId}/refund`, { preserveScroll: true });
    };

    return (
        <Card className="mb-4" data-testid="refund-card">
            <Card.Body>
                <h2 className="h3">Reservation fee</h2>
                <p>
                    The renter sent a proof of payment. Record whether you gave the fee back, as your house rules say; the renter
                    sees it on their reservation.
                </p>
                <p className="fw-semibold" data-testid="refund-status">
                    {refundLabel(refund.fee_refunded)}
                </p>
                {refund.can_record && (
                    <form onSubmit={submit} noValidate aria-label="Refund record">
                        <fieldset className="mb-3" aria-describedby={form.errors.fee_refunded ? errorId : undefined}>
                            <legend className="form-label fs-6 mb-2">
                                Did you refund the fee?
                            </legend>
                            <Form.Check
                                type="radio"
                                id={`refund-yes-${reservationId}`}
                                name="fee_refunded"
                                value="1"
                                label="Yes, refunded"
                                checked={form.data.fee_refunded === '1'}
                                onChange={() => form.setData('fee_refunded', '1')}
                                isInvalid={Boolean(form.errors.fee_refunded)}
                            />
                            <Form.Check
                                type="radio"
                                id={`refund-no-${reservationId}`}
                                name="fee_refunded"
                                value="0"
                                label="No, not refunded"
                                checked={form.data.fee_refunded === '0'}
                                onChange={() => form.setData('fee_refunded', '0')}
                                isInvalid={Boolean(form.errors.fee_refunded)}
                            />
                            {form.errors.fee_refunded && (
                                <div id={errorId} className="invalid-feedback d-flex gap-1">
                                    <ExclamationCircle aria-hidden="true" focusable="false" className="flex-shrink-0 mt-1" />
                                    <span>{form.errors.fee_refunded}</span>
                                </div>
                            )}
                        </fieldset>
                        <TextAreaField
                            name="refund_note"
                            label="Note"
                            optional
                            help="For example how and when you sent it back. The renter sees this."
                            value={form.data.refund_note}
                            onChange={(value) => form.setData('refund_note', value)}
                            error={form.errors.refund_note}
                            rows={2}
                        />
                        <div className="button-row">
                            <SubmitButton processing={form.processing} pendingLabel="Saving…" className="">
                                Save refund record
                            </SubmitButton>
                        </div>
                    </form>
                )}
            </Card.Body>
        </Card>
    );
}
