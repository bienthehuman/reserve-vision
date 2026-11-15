import { Head } from '@inertiajs/react';
import { Alert, Card, Container } from 'react-bootstrap';
import { HourglassSplit } from 'react-bootstrap-icons';
import LandlordLayout from '@/Layouts/LandlordLayout';

interface DashboardProps {
    account: { name: string; slug: string; isVerified: boolean };
    role: 'owner' | 'caretaker';
}

/** Placeholder until phase 4 (ACCESS.md, flow A). */
export default function Dashboard({ account, role }: DashboardProps) {
    return (
        <LandlordLayout>
            <Head title="Dashboard" />
            <Container className="py-4 py-md-5">
                <p className="text-body-secondary mb-1">{role === 'owner' ? 'Owner' : 'Caretaker'}</p>
                <h1 className="h2 mb-4">{account.name}</h1>

                {!account.isVerified && (
                    <Alert variant="warning" className="d-flex gap-2 align-items-start">
                        <HourglassSplit aria-hidden="true" focusable="false" className="flex-shrink-0 mt-1" />
                        <div>
                            <h2 className="h3 mb-1">Your account is awaiting verification</h2>
                            <p className="mb-0">
                                We check every landlord before their listings go live. You'll be able to publish once your
                                account is verified.
                            </p>
                        </div>
                    </Alert>
                )}

                <Card className="auth-card">
                    <Card.Body className="p-3 p-sm-4">
                        <h2 className="h3">Dashboard</h2>
                        <p className="mb-0 text-body-secondary">
                            Dashboard arrives in phase 4: properties, rooms, photos and reservations will be managed here.
                        </p>
                    </Card.Body>
                </Card>
            </Container>
        </LandlordLayout>
    );
}
