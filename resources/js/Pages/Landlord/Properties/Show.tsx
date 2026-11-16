import { Head, Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Badge, Button, Card, Container, ListGroup } from 'react-bootstrap';
import ActionButton from '@/Components/ActionButton';
import ErrorSummary from '@/Components/Form/ErrorSummary';
import SubmitButton from '@/Components/Form/SubmitButton';
import TextField from '@/Components/Form/TextField';
import { useFocusOnError } from '@/Components/Form/useFocusOnError';
import PriceTag from '@/Components/PriceTag';
import PropertyState from '@/Components/PropertyState';
import LandlordLayout from '@/Layouts/LandlordLayout';

interface Space {
    id: number;
    label: string;
    is_archived: boolean;
    can_edit: boolean;
    can_archive: boolean;
}

interface Room {
    id: number;
    name: string;
    type: 'private' | 'shared';
    monthly_rate: number;
    reservation_fee: number;
    deposit_months: number;
    advance_months: number;
    is_archived: boolean;
    can_edit: boolean;
    can_archive: boolean;
    can_add_space: boolean;
    spaces: Space[];
}

interface ShowProps {
    property: {
        id: number;
        name: string;
        kind: 'apartment' | 'boarding_house';
        address: string;
        barangay: string | null;
        city: string;
        gender_policy: 'any' | 'male_only' | 'female_only';
        house_rules: string | null;
        payment_instructions: string | null;
        is_published: boolean;
        is_archived: boolean;
        can_edit: boolean;
        can_add_room: boolean;
        can_archive: boolean;
    };
    rooms: Room[];
}

const genderLabel = { any: 'Anyone', male_only: 'Men only', female_only: 'Women only' } as const;

/** One space: its name, and for an owner "Rename" (an inline form) and "Archive" / "Unarchive". */
function SpaceRow({ space }: { space: Space }) {
    const [renaming, setRenaming] = useState(false);
    const form = useForm({ label: space.label });
    const { ref: summaryRef, onError } = useFocusOnError();

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.patch(`/landlord/spaces/${space.id}`, {
            preserveScroll: true,
            onSuccess: () => setRenaming(false),
            onError,
        });
    };

    if (renaming) {
        return (
            <ListGroup.Item>
                <form noValidate onSubmit={submit} aria-label={`Rename ${space.label}`}>
                    <ErrorSummary ref={summaryRef} errors={form.errors} order={['label']} />
                    <TextField
                        name={`label-${space.id}`}
                        label="New name"
                        value={form.data.label}
                        onChange={(value) => form.setData('label', value)}
                        error={form.errors.label}
                        className="mb-2"
                        required
                        autoFocus
                    />
                    <div className="d-flex gap-2">
                        <SubmitButton processing={form.processing} pendingLabel="Saving…" className="">
                            Save name
                        </SubmitButton>
                        <Button type="button" variant="outline-secondary" onClick={() => { form.reset(); form.clearErrors(); setRenaming(false); }}>
                            Cancel
                        </Button>
                    </div>
                </form>
            </ListGroup.Item>
        );
    }

    return (
        <ListGroup.Item className="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <span>
                {space.label}
                {space.is_archived && (
                    <Badge pill bg="" className="status-badge status-badge--secondary ms-2">
                        Archived
                    </Badge>
                )}
            </span>
            <span className="d-flex flex-wrap gap-2">
                {space.can_edit && (
                    <Button type="button" size="sm" variant="outline-primary" aria-label={`Rename ${space.label}`} onClick={() => setRenaming(true)}>
                        Rename
                    </Button>
                )}
                {space.can_archive &&
                    (space.is_archived ? (
                        <ActionButton href={`/landlord/spaces/${space.id}/archive`} method="delete" accessibleName={`Unarchive ${space.label}`}>
                            Unarchive
                        </ActionButton>
                    ) : (
                        <ActionButton href={`/landlord/spaces/${space.id}/archive`} method="post" accessibleName={`Archive ${space.label}`}>
                            Archive
                        </ActionButton>
                    ))}
            </span>
        </ListGroup.Item>
    );
}

function RoomCard({ room }: { room: Room }) {
    return (
        <Card className="auth-card mb-3">
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
                <dl className="row mt-3 mb-3">
                    <dt className="col-sm-4">Monthly rate</dt>
                    <dd className="col-sm-8">
                        <PriceTag centavos={room.monthly_rate} per="month" />
                    </dd>
                    <dt className="col-sm-4">Reservation fee</dt>
                    <dd className="col-sm-8">
                        <PriceTag centavos={room.reservation_fee} />
                    </dd>
                    <dt className="col-sm-4">Deposit and advance</dt>
                    <dd className="col-sm-8 mb-0">
                        {room.deposit_months} month{room.deposit_months === 1 ? '' : 's'} deposit, {room.advance_months} month
                        {room.advance_months === 1 ? '' : 's'} advance
                    </dd>
                </dl>

                <h4 className="h5">{room.type === 'shared' ? 'Beds' : 'Space'}</h4>
                <ListGroup className="mb-3">
                    {room.spaces.map((space) => (
                        <SpaceRow key={space.id} space={space} />
                    ))}
                </ListGroup>

                {(room.can_edit || room.can_add_space || room.can_archive) && (
                    <div className="d-flex flex-wrap gap-2">
                        {room.can_edit && (
                            <Link href={`/landlord/rooms/${room.id}/edit`} className="btn btn-outline-primary btn-sm" aria-label={`Edit room ${room.name}`}>
                                Edit room
                            </Link>
                        )}
                        {room.can_add_space && (
                            <ActionButton href={`/landlord/rooms/${room.id}/spaces`} method="post" accessibleName={`Add a bed to ${room.name}`}>
                                Add a bed
                            </ActionButton>
                        )}
                        {room.can_archive &&
                            (room.is_archived ? (
                                <ActionButton href={`/landlord/rooms/${room.id}/archive`} method="delete" accessibleName={`Unarchive room ${room.name}`}>
                                    Unarchive room
                                </ActionButton>
                            ) : (
                                <ActionButton href={`/landlord/rooms/${room.id}/archive`} method="post" accessibleName={`Archive room ${room.name}`}>
                                    Archive room
                                </ActionButton>
                            ))}
                    </div>
                )}
            </Card.Body>
        </Card>
    );
}

/** One property with its rooms, rates and spaces. Read-only for a caretaker; owners edit, add and archive (slice 4.1). */
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
                <p className="mb-3">
                    <PropertyState published={property.is_published} archived={property.is_archived} />
                </p>

                {(property.can_edit || property.can_archive) && (
                    <div className="d-flex flex-wrap gap-2 mb-4">
                        {property.can_edit && (
                            <Link href={`/landlord/properties/${property.id}/edit`} className="btn btn-outline-primary">
                                Edit property
                            </Link>
                        )}
                        {property.can_archive &&
                            (property.is_archived ? (
                                <ActionButton href={`/landlord/properties/${property.id}/archive`} method="delete">
                                    Unarchive property
                                </ActionButton>
                            ) : (
                                <ActionButton href={`/landlord/properties/${property.id}/archive`} method="post">
                                    Archive property
                                </ActionButton>
                            ))}
                    </div>
                )}

                <Card className="auth-card mb-4">
                    <Card.Body className="p-3 p-sm-4">
                        <h2 className="h3">Details</h2>
                        <dl className="row mb-0">
                            <dt className="col-sm-4">Who may stay</dt>
                            <dd className="col-sm-8">{genderLabel[property.gender_policy]}</dd>
                            <dt className="col-sm-4">How to pay the fee</dt>
                            <dd className="col-sm-8 pre-line">
                                {property.payment_instructions ?? 'Not written yet. You need it to publish.'}
                            </dd>
                            <dt className="col-sm-4">House rules</dt>
                            <dd className="col-sm-8 mb-0 pre-line">
                                {property.house_rules ?? 'None written.'}
                            </dd>
                        </dl>
                    </Card.Body>
                </Card>

                <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                    <h2 className="h3 mb-0">Rooms</h2>
                    {property.can_add_room && (
                        <Link href={`/landlord/properties/${property.id}/rooms/create`} className="btn btn-primary btn-sm">
                            Add a room
                        </Link>
                    )}
                </div>
                {rooms.length === 0 && <p className="text-body-secondary">This property has no rooms yet.</p>}
                {rooms.map((room) => (
                    <RoomCard key={room.id} room={room} />
                ))}
            </Container>
        </LandlordLayout>
    );
}
