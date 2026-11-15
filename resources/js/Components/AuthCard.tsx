import type { ReactNode } from 'react';
import { Card, Col, Container, Row } from 'react-bootstrap';

interface AuthCardProps {
    title: string;
    intro?: ReactNode;
    children: ReactNode;
    /** Below the card: "New here? Sign up" and similar. */
    footer?: ReactNode;
    /** Long forms (sign-up): a wider card, so paired fields can sit side by side. */
    wide?: boolean;
}

/** The narrow, centred card the auth pages share. */
export default function AuthCard({ title, intro, children, footer, wide = false }: AuthCardProps) {
    const columns = wide ? { xs: 12, md: 10, lg: 8, xl: 7 } : { xs: 12, sm: 10, md: 8, lg: 6, xl: 5 };

    return (
        <Container className="py-4 py-md-5">
            <Row className="justify-content-center">
                <Col {...columns}>
                    <Card className="auth-card">
                        <Card.Body className={wide ? 'p-3 p-sm-4 p-md-5' : 'p-3 p-sm-4'}>
                            <h1 className="h2 mb-2">{title}</h1>
                            {intro && <div className="text-body-secondary mb-4">{intro}</div>}
                            {children}
                        </Card.Body>
                    </Card>
                    {footer && <div className="text-center mt-3">{footer}</div>}
                </Col>
            </Row>
        </Container>
    );
}
