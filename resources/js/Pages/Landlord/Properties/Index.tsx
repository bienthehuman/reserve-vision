import { Head, Link } from '@inertiajs/react';
import { Card, Container, Table } from 'react-bootstrap';
import { Building, PlusLg } from 'react-bootstrap-icons';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import PropertyState from '@/Components/PropertyState';
import LandlordLayout from '@/Layouts/LandlordLayout';

interface IndexProps {
    can_create: boolean;
    properties: {
        id: number;
        name: string;
        kind: 'apartment' | 'boarding_house';
        barangay: string;
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
                <PageHeader
                    title="Properties"
                    actions={
                        canCreate && (
                            <Link href="/landlord/properties/create" className="btn btn-primary">
                                <PlusLg aria-hidden="true" focusable="false" />
                                Add a property
                            </Link>
                        )
                    }
                />

                {properties.length === 0 ? (
                    <Card>
                        <EmptyState Icon={Building}>No properties yet.{canCreate && ' Add your first one to start taking reservations.'}</EmptyState>
                    </Card>
                ) : (
                    <Card className="table-card">
                        <Table className="stack-table align-middle">
                            <thead>
                                <tr>
                                    <th scope="col">Property</th>
                                    <th scope="col">Type</th>
                                    <th scope="col">Location</th>
                                    <th scope="col">Rooms</th>
                                    <th scope="col">Listing</th>
                                </tr>
                            </thead>
                            <tbody>
                                {properties.map((property) => (
                                    <tr key={property.id}>
                                        <td data-label="Property">
                                            <Link href={`/landlord/properties/${property.id}`} className="link-plain">
                                                {property.name}
                                            </Link>
                                        </td>
                                        <td data-label="Type">{kindLabel[property.kind]}</td>
                                        <td data-label="Location">
                                            {property.barangay}, {property.city}
                                        </td>
                                        <td data-label="Rooms">{property.room_count}</td>
                                        <td data-label="Listing">
                                            <PropertyState published={property.is_published} archived={property.is_archived} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </Card>
                )}
            </Container>
        </LandlordLayout>
    );
}
