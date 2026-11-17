import { useState } from 'react';
import { Alert, Form } from 'react-bootstrap';
import { InfoCircle } from 'react-bootstrap-icons';
import { formatHoldEnd, formatSlot, formatViewingDay } from '@/lib/dates';

export interface ViewingData {
    earliest: { date: string; time: string };
    dates: string[];
    times: string[];
    max_message: number;
    open: { id: number; status: string; preferred_at: string } | null;
}

interface ViewingFormProps {
    propertyId: number;
    propertyName: string;
    viewing: ViewingData;
    /** Today in Manila (the page's first bookable day). */
    today: string;
    csrf: string;
    loggedIn: boolean;
    isLandlord: boolean;
    errors: string[];
}

/**
 * "See it first" (slice 6.2): ask the landlord for a viewing. A plain form post, so it works without JavaScript; it
 * starts at the earliest open slot (maybe today). With JavaScript, the times before that slot are disabled on the day
 * it falls on; without it, the server refuses a too-early time with a message that names the earliest one.
 */
export default function ViewingForm({ propertyId, propertyName, viewing, today, csrf, loggedIn, isLandlord, errors }: ViewingFormProps) {
    const { earliest } = viewing;
    const [date, setDate] = useState(earliest.date);
    const [time, setTime] = useState(earliest.time);
    const noSlotToday = earliest.date !== today;

    const tooEarly = (slot: string) => date === earliest.date && slot < earliest.time;

    const changeDate = (next: string) => {
        setDate(next);

        if (next === earliest.date && time < earliest.time) {
            setTime(earliest.time);
        }
    };

    return (
        <section id="viewing" className="viewing-section mt-5" aria-labelledby="viewing-title">
            <h2 id="viewing-title" className="h3 mb-3">
                See it first
            </h2>

            {errors.length > 0 && (
                <Alert variant="danger" role="alert">
                    <ul className="mb-0">
                        {errors.map((message) => (
                            <li key={message}>{message}</li>
                        ))}
                    </ul>
                </Alert>
            )}

            {isLandlord ? (
                <Alert variant="info" role="status" className="d-flex gap-2 align-items-start mb-0">
                    <InfoCircle aria-hidden="true" focusable="false" className="flex-shrink-0 mt-1" />
                    <div>Renters can ask {propertyName} for a viewing from here. Log in with a renter account to try it.</div>
                </Alert>
            ) : viewing.open ? (
                <Alert variant="info" role="status" className="d-flex gap-2 align-items-start mb-0">
                    <InfoCircle aria-hidden="true" focusable="false" className="flex-shrink-0 mt-1" />
                    <div>
                        You already asked to see this place on {formatHoldEnd(viewing.open.preferred_at)} (Manila time).{' '}
                        <a href="/viewings" className="alert-link">
                            View your viewing requests
                        </a>
                    </div>
                </Alert>
            ) : (
                <form method="post" action="/viewings" className="card" aria-label="Ask for a viewing">
                    <div className="card-body">
                        <input type="hidden" name="_token" value={csrf} />
                        <input type="hidden" name="property_id" value={propertyId} />
                        <p className="mb-3">
                            Want to look around before you reserve? Pick a time and the landlord will confirm it. Viewings are 8:00 AM to 6:00 PM, Manila time, and the landlord needs at least 2 hours' notice.
                        </p>
                        {noSlotToday && <p className="small text-body-secondary">There are no more times left today, so the first one is {formatViewingDay(earliest.date, today).toLowerCase()}.</p>}
                        <div className="row g-3">
                            <div className="col-12 col-sm-6">
                                <Form.Group controlId="viewing-date">
                                    <Form.Label>Day</Form.Label>
                                    <Form.Select name="date" value={date} onChange={(event) => changeDate(event.target.value)} required>
                                        {viewing.dates.map((day) => (
                                            <option key={day} value={day}>
                                                {formatViewingDay(day, today)}
                                            </option>
                                        ))}
                                    </Form.Select>
                                </Form.Group>
                            </div>
                            <div className="col-12 col-sm-6">
                                <Form.Group controlId="viewing-time">
                                    <Form.Label>Time (Manila)</Form.Label>
                                    <Form.Select name="time" value={time} onChange={(event) => setTime(event.target.value)} required>
                                        {viewing.times.map((slot) => (
                                            <option key={slot} value={slot} disabled={tooEarly(slot)}>
                                                {formatSlot(slot)}
                                            </option>
                                        ))}
                                    </Form.Select>
                                </Form.Group>
                            </div>
                            <div className="col-12">
                                <Form.Group controlId="viewing-message">
                                    <Form.Label>
                                        Message <span className="text-body-secondary fw-normal">(optional)</span>
                                    </Form.Label>
                                    <Form.Control as="textarea" rows={3} name="message" maxLength={viewing.max_message} aria-describedby="viewing-message-help" />
                                    <Form.Text id="viewing-message-help">Anything the landlord should know, up to {viewing.max_message} characters.</Form.Text>
                                </Form.Group>
                            </div>
                        </div>
                        <div className="form-actions mt-3">
                            <button type="submit" className="btn btn-primary">
                                Ask for a viewing
                            </button>
                        </div>
                        <p className="small text-body-secondary mt-2 mb-0">
                            {loggedIn ? 'You can cancel until the time comes. You can have up to 3 requests open, one per property.' : "You'll log in first, then come back here."}
                        </p>
                    </div>
                </form>
            )}
        </section>
    );
}
