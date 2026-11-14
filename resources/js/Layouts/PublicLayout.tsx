import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Container } from 'react-bootstrap';
import { HouseDoorFill } from 'react-bootstrap-icons';

interface PublicLayoutProps {
    children: ReactNode;
}

export default function PublicLayout({ children }: PublicLayoutProps) {
    const { appName } = usePage().props;

    return (
        <>
            <a href="#main" className="visually-hidden-focusable position-absolute top-0 start-0 m-2 btn btn-primary">
                Skip to content
            </a>
            <header className="site-header">
                <Container className="d-flex align-items-center py-3">
                    <Link href="/" className="d-inline-flex align-items-center gap-2 text-decoration-none text-body">
                        <span className="brand-mark">
                            <HouseDoorFill aria-hidden="true" focusable="false" />
                        </span>
                        <span className="h3 mb-0">{appName}</span>
                    </Link>
                </Container>
            </header>
            <main id="main" tabIndex={-1}>
                {children}
            </main>
            <footer className="site-footer mt-6">
                <Container className="py-4 small">
                    <p className="mb-0">
                        {appName}: reservations for apartments and boarding-house beds.
                    </p>
                </Container>
            </footer>
        </>
    );
}
