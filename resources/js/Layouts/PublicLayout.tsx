import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Alert, Container } from 'react-bootstrap';
import { HouseDoorFill, PersonCircle } from 'react-bootstrap-icons';

interface PublicLayoutProps {
    children: ReactNode;
}

/** Log in / Sign up, or the user's name, Dashboard (landlords) and Log out. Wraps under the brand on narrow phones. */
function SiteNav() {
    const { auth } = usePage().props;
    const user = auth.user;

    if (!user) {
        return (
            <nav aria-label="Account" className="site-nav d-flex align-items-center gap-2">
                <Link href="/login" className="btn btn-outline-primary">
                    Log in
                </Link>
                <Link href="/register" className="btn btn-primary">
                    Sign up
                </Link>
            </nav>
        );
    }

    return (
        <nav aria-label="Account" className="site-nav d-flex flex-wrap align-items-center gap-2">
            <span className="site-nav__user d-inline-flex align-items-center gap-1 text-truncate">
                <PersonCircle aria-hidden="true" focusable="false" className="flex-shrink-0" />
                <span className="text-truncate">{user.full_name}</span>
            </span>
            {user.type === 'landlord' && (
                <Link href="/landlord" className="btn btn-outline-primary">
                    Dashboard
                </Link>
            )}
            <Link href="/logout" method="post" as="button" className="btn btn-outline-primary">
                Log out
            </Link>
        </nav>
    );
}

export default function PublicLayout({ children }: PublicLayoutProps) {
    const { appName, flash } = usePage().props;

    return (
        <>
            <a href="#main" className="visually-hidden-focusable position-absolute top-0 start-0 m-2 btn btn-primary">
                Skip to content
            </a>
            <header className="site-header">
                <Container className="d-flex flex-wrap align-items-center justify-content-between gap-2 py-2">
                    <Link href="/" className="d-inline-flex align-items-center gap-2 text-decoration-none text-body py-2">
                        <span className="brand-mark">
                            <HouseDoorFill aria-hidden="true" focusable="false" />
                        </span>
                        <span className="h3 mb-0">{appName}</span>
                    </Link>
                    <SiteNav />
                </Container>
            </header>
            <main id="main" tabIndex={-1}>
                {flash.error && (
                    <Container className="pt-3">
                        <Alert variant="danger" className="mb-0" role="alert">
                            {flash.error}
                        </Alert>
                    </Container>
                )}
                {flash.status && (
                    <Container className="pt-3">
                        <Alert variant="success" className="mb-0" role="status">
                            {flash.status}
                        </Alert>
                    </Container>
                )}
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
