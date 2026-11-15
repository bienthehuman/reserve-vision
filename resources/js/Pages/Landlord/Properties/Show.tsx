import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Badge, Card, Container } from 'react-bootstrap';
import PriceTag from '@/Components/PriceTag';
import PropertyState from '@/Components/PropertyState';
import ErrorSummary from '@/Components/Form/ErrorSummary';
import SubmitButton from '@/Components/Form/SubmitButton';
import TextField from '@/Components/Form/TextField';
import { useFocusOnError } from '@/Components/Form/useFocusOnError';
import LandlordLayout from '@/Layouts/LandlordLayout';

interface Room {
    id: number;
    name: string;
    type: 'private' | 'shared';
    monthly_rate: number;
    reservation_fee: number;
    deposit_months: number;
    advance_months: number;
    is_archived: boolean;
    can_change_rate: boolean;
    spaces: { id: number; label: string; is_archived: boolean }[];
}

interface ShowProps {
    property: {
        id: number;
        name: string;
        kind: 'apartment' | 'boarding_house';
        address: string;
        barangay: string | null;
        city: string;
        is_published: boolean;
        is_archived: boolean;
    };
    rooms: Room[];
}

/** Centavos as the text of a pesos field: 650000 → "6500", 650050 → "6500.50". */
function pesosText(centavos: number): string {
    const pesos = Math.floor(centavos / 100);
    const rest = centavos % 100;

    return rest === 0 ? String(pesos) : `${pesos}.${String(rest).padStart(2, '0')}`;
}

/** The owner's rate form (a caretaker never gets it; the endpoint refuses them anyway). */
function RateForm({ room }: { room: Room }) {
    const form = useForm({ monthly_rate: pesosText(room.monthly_rate) });
    const { ref: summaryRef, onError } = useFocusOnError();

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.patch(`/landlord/rooms/${room.id}/rate`, { preserveScroll: true, onError });
    };

    return (
        <form noValidate onSubmit={submit} aria-label={`Change the rate of ${room.name}`} className="mt-3">
            <ErrorSummary ref={summaryRef} errors={form.errors} order={['monthly_rate']} />
            <div className="d-flex flex-wrap align-items-end gap-2">
                <TextField
                    name={`monthly_rate-${room.id}`}
                    label="Monthly rate (₱)"
                    inputMode="decimal"
                    className="mb-0 flex-grow-1"
                    value={form.data.monthly_rate}
                    onChange={(value) => form.setData('monthly_rate', value)}
                    error={form.errors.monthly_rate}
                    help="Pesos, for example 6500 or 6500.50. Existing reservations keep their fee."
                    required
                />
                <SubmitButton processing={form.processing} pendingLabel="Saving…" className="">
                    Save rate
                </SubmitButton>
            </div>
        </form>
    );
}

/** One property with its rooms, rates and spaces. Read-only except the owner's rate form; 4.1 adds editing. */
export default function Show({ property, rooms }: ShowProps) {
    const place = [property.address, property.barangay, property.city].filter(Boolean).join(', ');

    return (
        <LandlordLayout>
            <Head title={property.name} />
            <Container className="py-4 py-md-5">
                <p className="mb-2">
                    <Link href="/landlord/properties">Back to properties</Link>
                </p>
                <h1 className="h2 mb-1">{property.name}</h1>
                <p className="text-body-secondary mb-2">{place}</p>
                <p className="mb-4">
                    <PropertyState published={property.is_published} archived={property.is_archived} />
                </p>

                <h2 className="h3 mb-3">Rooms</h2>
                {rooms.length === 0 && <p className="text-body-secondary">This property has no rooms yet.</p>}
                {rooms.map((room) => (
                    <Card key={room.id} className="auth-card mb-3">
                        <Card.Body className="p-3 p-sm-4">
                            <div className="d-flex flex-wrap align-items-center justify-content-between gap-2">
                                <h3 className="h3 mb-0">
                                    {room.name}{' '}
                                    <span className="small fw-normal text-body-secondary">{room.type === 'shared' ? 'Shared room' : 'Private room'}</span>
                                </h3>
                                {room.is_archived && (
                                    <Badge pill bg="" className="status-badge status-badge--secondary">
                                        Archived
                                    </Badge>
                                )}
                            </div>
                            <dl className="row mt-3 mb-0">
                                <dt className="col-sm-4">Monthly rate</dt>
                                <dd className="col-sm-8">
                                    <PriceTag centavos={room.monthly_rate} per="month" />
                                </dd>
                                <dt className="col-sm-4">Reservation fee</dt>
                                <dd className="col-sm-8">
                                    <PriceTag centavos={room.reservation_fee} />
                                </dd>
                                <dt className="col-sm-4">Deposit and advance</dt>
                                <dd className="col-sm-8">
                                    {room.deposit_months} month{room.deposit_months === 1 ? '' : 's'} deposit, {room.advance_months} month
                                    {room.advance_months === 1 ? '' : 's'} advance
                                </dd>
                                <dt className="col-sm-4">{room.type === 'shared' ? 'Beds' : 'Space'}</dt>
                                <dd className="col-sm-8 mb-0">
                                    <ul className="list-inline mb-0">
                                        {room.spaces.map((space) => (
                                            <li key={space.id} className="list-inline-item">
                                                {space.label}
                                                {space.is_archived && ' (archived)'}
                                            </li>
                                        ))}
                                    </ul>
                                </dd>
                            </dl>
                            {room.can_change_rate && <RateForm room={room} />}
                        </Card.Body>
                    </Card>
                ))}
            </Container>
        </LandlordLayout>
    );
}
