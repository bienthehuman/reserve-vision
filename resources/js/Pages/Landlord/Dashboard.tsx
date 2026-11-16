import { Head, Link } from '@inertiajs/react';
import type { ComponentType, ReactNode, SVGProps } from 'react';
import { Alert, Card, Col, Container, Row } from 'react-bootstrap';
import { BoxArrowInRight, ExclamationTriangle, FileEarmarkCheck, Hourglass, HourglassSplit, HouseDoor } from 'react-bootstrap-icons';
import PageHeader from '@/Components/PageHeader';
import LandlordLayout from '@/Layouts/LandlordLayout';

interface DashboardProps {
    account: { name: string; slug: string; isVerified: boolean };
    role: 'owner' | 'caretaker';
    figures: {
        proofs: { count: number; overdue: number; overdue_hours: number; oldest_id: number | null };
        holds: { count: number; hours: number };
        move_ins: { count: number; days: number };
        occupancy: { occupied: number; total: number };
    };
}

interface FigureCardProps {
    title: string;
    href: string;
    figure: ReactNode;
    Icon: ComponentType<SVGProps<SVGSVGElement>>;
    children?: ReactNode;
}

/** One figure: the title is the link (stretched over the card), so the whole card is the target. */
function FigureCard({ title, href, figure, Icon, children }: FigureCardProps) {
    return (
        <Card className="figure-card">
            <Card.Body>
                <div className="figure-card__head">
                    <span className="figure-card__icon">
                        <Icon aria-hidden="true" focusable="false" />
                    </span>
                    <h2 className="figure-card__title">
                        <Link href={href} className="link-plain stretched-link">
                            {title}
                        </Link>
                    </h2>
                </div>
                <p className="figure-card__value">{figure}</p>
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
                <PageHeader title={account.name} eyebrow={role === 'owner' ? 'Owner' : 'Caretaker'} />

                {!account.isVerified && (
                    <Alert variant="warning" className="d-flex gap-2 align-items-start mb-4">
                        <HourglassSplit aria-hidden="true" focusable="false" className="flex-shrink-0 mt-1" />
                        <div>
                            <h2 className="h3 mb-1">Your account is being checked</h2>
                            <p className="mb-0">
                                We check every landlord before their listings can be seen by renters. You can set everything
                                up now and publish as soon as we're done.
                            </p>
                        </div>
                    </Alert>
                )}

                <Row className="g-3">
                    <Col xs={12} md={6}>
                        {/* Slice 6.1: the card opens the payment that has waited longest; the list is one link further. */}
                        <FigureCard
                            title="Payments to review"
                            href={proofs.oldest_id === null ? '/landlord/reservations?status=proof_uploaded' : `/landlord/reservations/${proofs.oldest_id}`}
                            figure={proofs.count}
                            Icon={FileEarmarkCheck}
                        >
                            {proofs.count > 1 && (
                                <p className="figure-card__note mb-1">
                                    Opens the one waiting longest.{' '}
                                    <Link href="/landlord/reservations?status=proof_uploaded" className="link-action position-relative">
                                        See all {proofs.count}
                                    </Link>
                                </p>
                            )}
                            {proofs.overdue > 0 ? (
                                <p className="figure-card__note d-flex gap-2 align-items-start">
                                    <ExclamationTriangle aria-hidden="true" focusable="false" className="flex-shrink-0 mt-1 text-body" />
                                    <span>
                                        <Link href="/landlord/reservations?show=proofs_overdue" className="link-action position-relative">
                                            {proofs.overdue} waiting more than {proofs.overdue_hours} hours
                                        </Link>
                                    </span>
                                </p>
                            ) : (
                                <p className="figure-card__note">
                                    {proofs.count === 0 ? 'Nothing is waiting for you.' : `None has waited more than ${proofs.overdue_hours} hours.`}
                                </p>
                            )}
                        </FigureCard>
                    </Col>
                    <Col xs={12} md={6}>
                        <FigureCard title="Holds ending soon" href="/landlord/reservations?show=holds_expiring" figure={holds.count} Icon={Hourglass}>
                            <p className="figure-card__note">
                                {holds.count === 0 ? `No hold ends in the next ${holds.hours} hours.` : `Ending within ${holds.hours} hours unless the renter pays.`}
                            </p>
                        </FigureCard>
                    </Col>
                    <Col xs={12} md={6}>
                        <FigureCard title="Move-ins this week" href="/landlord/reservations?show=moving_in" figure={moveIns.count} Icon={BoxArrowInRight}>
                            <p className="figure-card__note">
                                Confirmed renters moving in within the next {moveIns.days} days.
                            </p>
                        </FigureCard>
                    </Col>
                    <Col xs={12} md={6}>
                        <FigureCard title="Occupancy today" href="/landlord/properties" figure={`${occupancy.occupied} of ${occupancy.total}`} Icon={HouseDoor}>
                            <p className="figure-card__note">
                                {occupancy.total === 0
                                    ? 'Nothing to rent out yet. Add a property and its rooms.'
                                    : `${percent}% of your rooms and beds ${plural(occupancy.total, 'is', 'are')} occupied.`}
                            </p>
                        </FigureCard>
                    </Col>
                </Row>
            </Container>
        </LandlordLayout>
    );
}
