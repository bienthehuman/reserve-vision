import { Head, Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Badge, Button, Card, Container, ListGroup } from 'react-bootstrap';
import { DoorOpen, GeoAlt, Pencil, PlusLg } from 'react-bootstrap-icons';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import ActionButton from '@/Components/ActionButton';
import ErrorSummary from '@/Components/Form/ErrorSummary';
import SubmitButton from '@/Components/Form/SubmitButton';
import TextField from '@/Components/Form/TextField';
import { useFocusOnError } from '@/Components/Form/useFocusOnError';
import PriceTag from '@/Components/PriceTag';
import PropertyPhotos, { type Photo } from '@/Components/PropertyPhotos';
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
        /** Street address (when given), barangay, city, province. */
        full_address: string;
        gender_policy: 'any' | 'male_only' | 'female_only';
        house_rules: string | null;
        payment_instructions: string | null;
        is_published: boolean;
        is_archived: boolean;
        can_edit: boolean;
        can_add_room: boolean;
        can_archive: boolean;
        can_manage_photos: boolean;
        photo_limit: number;
    };
    photos: Photo[];
    rooms: Room[];
}

const genderLabel = { any: 'Anyone', male_only: 'Men only', female_only: 'Women only' } as const;

/** One space: its name, and for an owner "Rename" (an inline form) and "Archive" / "Restore". */
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
                    <div className="button-row">
                        <Button type="button" variant="outline-primary" onClick={() => { form.reset(); form.clearErrors(); setRenaming(false); }}>
                            Cancel
                        </Button>
                        <SubmitButton processing={form.processing} pendingLabel="Saving…" className="">
                            Save name
                        </SubmitButton>
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
                        <ActionButton href={`/landlord/spaces/${space.id}/archive`} method="delete" accessibleName={`Restore ${space.label}`}>
                            Restore
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
        <Card className="mb-3">
            <Card.Body>
                <div className="card-title-row">
                    <div>
                        <h3 className="h3 mb-0">{room.name}</h3>
                        <p className="small text-body-secondary mb-0">{room.type === 'shared' ? 'Shared room, rented by the bed' : 'Private room'}</p>
                    </div>
                    {room.is_archived && (
                        <Badge pill bg="" className="status-badge status-badge--secondary">
                            Archived
                        </Badge>
                    )}
                </div>
                <dl className="details row mb-4">
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

                <h4 className="small fw-semibold text-body-secondary mb-2">{room.type === 'shared' ? 'Beds' : 'Rented as'}</h4>
                <ListGroup className="mb-3">
                    {room.spaces.map((space) => (
                        <SpaceRow key={space.id} space={space} />
                    ))}
                </ListGroup>

                {(room.can_edit || room.can_add_space || room.can_archive) && (
                    <div className="button-row">
                        {room.can_edit && (
                            <Link href={`/landlord/rooms/${room.id}/edit`} className="btn btn-outline-primary btn-sm" aria-label={`Edit room ${room.name}`}>
                                <Pencil aria-hidden="true" focusable="false" />
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
                                <ActionButton href={`/landlord/rooms/${room.id}/archive`} method="delete" accessibleName={`Restore room ${room.name}`}>
                                    Restore room
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

/** One property with its photos, rooms, rates and spaces. Read-only for a caretaker; owners edit, add and archive (slices 4.1, 4.2). */
export default function Show({ property, photos, rooms }: ShowProps) {
    return (
        <LandlordLayout>
            <Head title={property.name} />
            <Container className="py-4 py-md-5">
                <PageHeader
                    title={property.name}
                    back={{ href: '/landlord/properties', label: 'Back to properties' }}
                    actions={
                        (property.can_edit || property.can_archive) && (
                            <>
                                {property.can_edit && (
                                    <Link href={`/landlord/properties/${property.id}/edit`} className="btn btn-outline-primary">
                                        <Pencil aria-hidden="true" focusable="false" />
                                        Edit property
                                    </Link>
                                )}
                                {property.can_archive &&
                                    (property.is_archived ? (
                                        <ActionButton href={`/landlord/properties/${property.id}/archive`} method="delete" size="md">
                                            Restore property
                                        </ActionButton>
                                    ) : (
                                        <ActionButton href={`/landlord/properties/${property.id}/archive`} method="post" size="md">
                                            Archive property
                                        </ActionButton>
                                    ))}
                            </>
                        )
                    }
                >
                    <p className="page-header__subtitle d-flex align-items-start gap-1">
                        <GeoAlt aria-hidden="true" focusable="false" className="flex-shrink-0 mt-1" />
                        <span>{property.full_address}</span>
                    </p>
                    <p className="mt-2 mb-0">
                        <PropertyState published={property.is_published} archived={property.is_archived} />
                    </p>
                </PageHeader>

                <Card className="mb-4">
                    <Card.Body>
                        <h2 className="h3 mb-3">Details</h2>
                        <dl className="details row">
                            <dt className="col-sm-4">Who can stay</dt>
                            <dd className="col-sm-8">{genderLabel[property.gender_policy]}</dd>
                            <dt className="col-sm-4">How renters pay the reservation fee</dt>
                            <dd className="col-sm-8 pre-line">
                                {property.payment_instructions ?? 'Not added yet. You need this before you can publish.'}
                            </dd>
                            <dt className="col-sm-4">House rules</dt>
                            <dd className="col-sm-8 pre-line">
                                {property.house_rules ?? 'None added.'}
                            </dd>
                        </dl>
                    </Card.Body>
                </Card>

                <PropertyPhotos
                    propertyId={property.id}
                    propertyName={property.name}
                    photos={photos}
                    limit={property.photo_limit}
                    canManage={property.can_manage_photos}
                />

                <div className="card-title-row mt-5">
                    <h2 className="h3 mb-0">Rooms</h2>
                    {property.can_add_room && (
                        <Link href={`/landlord/properties/${property.id}/rooms/create`} className="btn btn-primary">
                            <PlusLg aria-hidden="true" focusable="false" />
                            Add a room
                        </Link>
                    )}
                </div>
                {rooms.length === 0 && (
                    <Card>
                        <EmptyState Icon={DoorOpen}>This property has no rooms yet.</EmptyState>
                    </Card>
                )}
                {rooms.map((room) => (
                    <RoomCard key={room.id} room={room} />
                ))}
            </Container>
        </LandlordLayout>
    );
}
