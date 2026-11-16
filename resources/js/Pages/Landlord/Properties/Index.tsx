import { Head, Link } from '@inertiajs/react';
import { Container, Table } from 'react-bootstrap';
import { Building } from 'react-bootstrap-icons';
import PropertyState from '@/Components/PropertyState';
import LandlordLayout from '@/Layouts/LandlordLayout';

interface IndexProps {
    can_create: boolean;
    properties: {
        id: number;
        name: string;
        kind: 'apartment' | 'boarding_house';
        barangay: string | null;
        city: string;
        is_published: boolean;
        is_archived: boolean;
        room_count: number;
    }[];
}

const kindLabel = { apartment: 'Apartment', boarding_house: 'Boarding house' } as const;

/** The account's properties; owners can add one (slice 4.1). */
export default function Index({ properties, can_create: canCreate }: IndexProps) {
    return (
        <LandlordLayout>
            <Head title="Properties" />
            <Container className="py-4 py-md-5">
                <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
                    <h1 className="h2 mb-0">Properties</h1>
                    {canCreate && (
                        <Link href="/landlord/properties/create" className="btn btn-primary">
                            Add a property
                        </Link>
                    )}
                </div>

                {properties.length === 0 ? (
                    <div className="text-center text-body-secondary py-5">
                        <Building aria-hidden="true" focusable="false" size={32} className="mb-2" />
                        <p className="mb-0">No properties yet.{canCreate && ' Add your first one to start taking reservations.'}</p>
                    </div>
                ) : (
                    <Table className="stack-table align-middle">
                        <thead>
                            <tr>
                                <th scope="col">Property</th>
                                <th scope="col">Type</th>
                                <th scope="col">Place</th>
                                <th scope="col">Rooms</th>
                                <th scope="col">Listing</th>
                            </tr>
                        </thead>
                        <tbody>
                            {properties.map((property) => (
                                <tr key={property.id}>
                                    <td data-label="Property">
                                        <Link href={`/landlord/properties/${property.id}`}>{property.name}</Link>
                                    </td>
                                    <td data-label="Type">{kindLabel[property.kind]}</td>
                                    <td data-label="Place">{[property.barangay, property.city].filter(Boolean).join(', ')}</td>
                                    <td data-label="Rooms">{property.room_count}</td>
                                    <td data-label="Listing">
                                        <PropertyState published={property.is_published} archived={property.is_archived} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Container>
        </LandlordLayout>
    );
}
