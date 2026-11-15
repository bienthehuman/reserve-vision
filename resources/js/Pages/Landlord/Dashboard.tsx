import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Alert, Card, Col, Container, Row } from 'react-bootstrap';
import { ExclamationTriangle, HourglassSplit } from 'react-bootstrap-icons';
import LandlordLayout from '@/Layouts/LandlordLayout';

interface DashboardProps {
    account: { name: string; slug: string; isVerified: boolean };
    role: 'owner' | 'caretaker';
    figures: {
        proofs: { count: number; overdue: number; overdue_hours: number };
        holds: { count: number; hours: number };
        move_ins: { count: number; days: number };
        occupancy: { occupied: number; total: number };
    };
}

interface FigureCardProps {
    title: string;
    href: string;
    figure: ReactNode;
    children?: ReactNode;
}

/** One figure: the title is the link (stretched over the card), so the whole card is the target. */
function FigureCard({ title, href, figure, children }: FigureCardProps) {
    return (
        <Card className="h-100 position-relative">
            <Card.Body className="p-3 p-sm-4">
                <h2 className="h3 mb-2">
                    <Link href={href} className="stretched-link">
                        {title}
                    </Link>
                </h2>
                <p className="display-5 fw-bold text-primary mb-1">{figure}</p>
                {children}
            </Card.Body>
        </Card>
    );
}

function plural(count: number, one: string, many: string): string {
    return count === 1 ? one : many;
}

/** The landlord dashboard (slice 4.1): four figures that link to the reservations behind them. */
export default function Dashboard({ account, role, figures }: DashboardProps) {
    const { proofs, holds, move_ins: moveIns, occupancy } = figures;
    const percent = occupancy.total === 0 ? 0 : Math.round((occupancy.occupied / occupancy.total) * 100);

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

                <Row className="g-3">
                    <Col xs={12} md={6}>
                        <FigureCard title="Proofs to review" href="/landlord/reservations?status=proof_uploaded" figure={proofs.count}>
                            {proofs.overdue > 0 ? (
                                <p className="mb-0 d-flex gap-2 align-items-start">
                                    <ExclamationTriangle aria-hidden="true" focusable="false" className="flex-shrink-0 mt-1 text-warning-emphasis" />
                                    <span>
                                        <Link href="/landlord/reservations?show=proofs_overdue" className="position-relative">
                                            {proofs.overdue} waiting more than {proofs.overdue_hours} hours
                                        </Link>
                                    </span>
                                </p>
                            ) : (
                                <p className="mb-0 text-body-secondary">
                                    {proofs.count === 0 ? 'Nothing is waiting for you.' : `None has waited more than ${proofs.overdue_hours} hours.`}
                                </p>
                            )}
                        </FigureCard>
                    </Col>
                    <Col xs={12} md={6}>
                        <FigureCard title="Holds expiring soon" href="/landlord/reservations?show=holds_expiring" figure={holds.count}>
                            <p className="mb-0 text-body-secondary">
                                {plural(holds.count, 'hold runs', 'holds run')} out within {holds.hours} hours.
                            </p>
                        </FigureCard>
                    </Col>
                    <Col xs={12} md={6}>
                        <FigureCard title="Move-ins this week" href="/landlord/reservations?show=moving_in" figure={moveIns.count}>
                            <p className="mb-0 text-body-secondary">
                                Confirmed {plural(moveIns.count, 'stay starts', 'stays start')} from today to {moveIns.days} days ahead.
                            </p>
                        </FigureCard>
                    </Col>
                    <Col xs={12} md={6}>
                        <FigureCard title="Occupancy today" href="/landlord/properties" figure={`${occupancy.occupied} of ${occupancy.total}`}>
                            <p className="mb-0 text-body-secondary">
                                {occupancy.total === 0
                                    ? 'No spaces to rent yet. Add a property and its rooms.'
                                    : `${percent}% of your ${plural(occupancy.total, 'space is', 'spaces are')} occupied.`}
                            </p>
                        </FigureCard>
                    </Col>
                </Row>
            </Container>
        </LandlordLayout>
    );
}
