import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Alert, Container } from 'react-bootstrap';
import ErrorSummary from '@/Components/Form/ErrorSummary';
import SelectField from '@/Components/Form/SelectField';
import SubmitButton from '@/Components/Form/SubmitButton';
import TextField from '@/Components/Form/TextField';
import { useFocusOnError } from '@/Components/Form/useFocusOnError';
import LandlordLayout from '@/Layouts/LandlordLayout';
import { pesosText } from '@/lib/money';

interface FormProps {
    property: { id: number; name: string };
    room: {
        id: number;
        name: string;
        room_type: 'private' | 'shared';
        monthly_rate: number;
        reservation_fee: number;
        deposit_months: number;
        advance_months: number;
        bed_count: number;
    } | null;
    /** The room has had a reservation: its type can't change. */
    type_locked: boolean;
}

const FIELD_ORDER = ['name', 'room_type', 'beds', 'monthly_rate', 'reservation_fee', 'deposit_months', 'advance_months'];
const months = ['0', '1', '2', '3'].map((value) => ({ value, label: value === '1' ? '1 month' : `${value} months` }));

/** Add or edit a room: name, type, rate, fee, deposit and advance (owners; slice 4.1). A new shared room takes its bed count here. */
export default function Form({ property, room, type_locked: typeLocked }: FormProps) {
    const editing = room !== null;
    const form = useForm({
        name: room?.name ?? '',
        room_type: room?.room_type ?? 'private',
        beds: editing ? '' : '4',
        monthly_rate: room ? pesosText(room.monthly_rate) : '',
        reservation_fee: room ? pesosText(room.reservation_fee) : '',
        deposit_months: String(room?.deposit_months ?? 1),
        advance_months: String(room?.advance_months ?? 1),
    });
    const { ref: summaryRef, onError } = useFocusOnError();

    const becomingShared = editing && room.room_type === 'private' && form.data.room_type === 'shared';
    const becomingPrivate = editing && room.room_type === 'shared' && form.data.room_type === 'private';
    const asksForBeds = form.data.room_type === 'shared' && (!editing || becomingShared);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onError };

        if (editing) {
            form.put(`/landlord/rooms/${room.id}`, options);
        } else {
            form.post(`/landlord/properties/${property.id}/rooms`, options);
        }
    };

    return (
        <LandlordLayout>
            <Head title={editing ? `Edit ${room.name}` : 'Add a room'} />
            <Container className="py-4 py-md-5">
                <p className="mb-2">
                    <Link href={`/landlord/properties/${property.id}`}>Back to {property.name}</Link>
                </p>
                <h1 className="h2 mb-4">{editing ? `Edit ${room.name}` : `Add a room to ${property.name}`}</h1>

                <form noValidate onSubmit={submit} className="room-form">
                    <ErrorSummary ref={summaryRef} errors={form.errors} order={FIELD_ORDER} />

                    <TextField
                        name="name"
                        label="Room name"
                        value={form.data.name}
                        onChange={(value) => form.setData('name', value)}
                        error={form.errors.name}
                        placeholder="Room 1"
                        required
                    />

                    <SelectField
                        name="room_type"
                        label="Room type"
                        value={form.data.room_type}
                        onChange={(value) => form.setData('room_type', value as 'private' | 'shared')}
                        options={[
                            { value: 'private', label: 'Private room (one space, rented whole)' },
                            { value: 'shared', label: 'Shared room (rented by the bed)' },
                        ]}
                        error={form.errors.room_type}
                        help={
                            typeLocked
                                ? "The type can't change because this room has had a reservation: the reservations point to its beds."
                                : undefined
                        }
                        disabled={typeLocked}
                        required
                    />

                    {asksForBeds && (
                        <TextField
                            name="beds"
                            label="Number of beds"
                            inputMode="numeric"
                            value={form.data.beds}
                            onChange={(value) => form.setData('beds', value)}
                            error={form.errors.beds}
                            help="From 2 to 12. They are named Bed A, Bed B and so on; you can rename them, or add a bed later."
                            required
                        />
                    )}
                    {becomingPrivate && (
                        <Alert variant="info" className="mb-3">
                            Making this room private keeps one space, named "Whole room", and removes its other beds. That works because none has been reserved.
                        </Alert>
                    )}
                    {editing && room.room_type === 'shared' && !becomingPrivate && (
                        <p className="text-body-secondary">
                            It has {room.bed_count} {room.bed_count === 1 ? 'bed' : 'beds'} in use. Add, rename or archive beds on the property page.
                        </p>
                    )}

                    <div className="row">
                        <div className="col-sm-6">
                            <TextField
                                name="monthly_rate"
                                label="Monthly rate (₱)"
                                inputMode="decimal"
                                value={form.data.monthly_rate}
                                onChange={(value) => form.setData('monthly_rate', value)}
                                error={form.errors.monthly_rate}
                                help="Pesos, for example 6500 or 6500.50. For a shared room, the price of one bed."
                                required
                            />
                        </div>
                        <div className="col-sm-6">
                            <TextField
                                name="reservation_fee"
                                label="Reservation fee (₱)"
                                inputMode="decimal"
                                value={form.data.reservation_fee}
                                onChange={(value) => form.setData('reservation_fee', value)}
                                error={form.errors.reservation_fee}
                                help="Renters pay it to hold a space. A reservation keeps the fee it was made with, so changing it only affects new ones."
                                required
                            />
                        </div>
                    </div>

                    <div className="row">
                        <div className="col-sm-6">
                            <SelectField
                                name="deposit_months"
                                label="Deposit"
                                value={form.data.deposit_months}
                                onChange={(value) => form.setData('deposit_months', value)}
                                options={months}
                                error={form.errors.deposit_months}
                                required
                            />
                        </div>
                        <div className="col-sm-6">
                            <SelectField
                                name="advance_months"
                                label="Advance"
                                value={form.data.advance_months}
                                onChange={(value) => form.setData('advance_months', value)}
                                options={months}
                                error={form.errors.advance_months}
                                required
                            />
                        </div>
                    </div>

                    <SubmitButton processing={form.processing} pendingLabel="Saving…" className="">
                        {editing ? 'Save room' : 'Add room'}
                    </SubmitButton>
                </form>
            </Container>
        </LandlordLayout>
    );
}
