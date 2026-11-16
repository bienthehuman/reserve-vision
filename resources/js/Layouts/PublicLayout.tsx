import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Alert, Container } from 'react-bootstrap';
import { HouseDoorFill } from 'react-bootstrap-icons';
import UserMenu from '@/Components/UserMenu';

interface PublicLayoutProps {
    children: ReactNode;
}

/** Log in / Sign up, or the account menu (the user's name, which opens Log out). */
function SiteNav() {
    const { auth } = usePage().props;
    const user = auth.user;

    return (
        <nav aria-label="Account" className="d-flex align-items-center gap-2">
            {user ? (
                <UserMenu user={user} />
            ) : (
                <>
                    <Link href="/login" className="btn btn-outline-primary">
                        Log in
                    </Link>
                    <Link href="/register" className="btn btn-primary">
                        Sign up
                    </Link>
                </>
            )}
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
                <Container className="d-flex align-items-center justify-content-between gap-2 py-2">
                    <Link href="/" className="site-brand">
                        <span className="brand-mark">
                            <HouseDoorFill aria-hidden="true" focusable="false" />
                        </span>
                        <span>{appName}</span>
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
            <footer className="site-footer mt-5">
                <Container className="py-4 small">
                    <p className="mb-0">
                        {appName}: reservations for apartments and boarding-house beds.
                    </p>
                </Container>
            </footer>
        </>
    );
}
