import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ArrowLeft } from 'react-bootstrap-icons';

interface PageHeaderProps {
    title: ReactNode;
    /** The page one level up: "Back to properties". */
    back?: { href: string; label: string };
    /** A quiet line above the title: the account's role, the property a room belongs to. */
    eyebrow?: ReactNode;
    /** Under the title: an address, a status badge. */
    children?: ReactNode;
    /** Buttons, on the right from `sm` and under the title on a phone. */
    actions?: ReactNode;
}

/** The top of every page inside the app: where you came from, where you are, what you can do here. */
export default function PageHeader({ title, back, eyebrow, children, actions }: PageHeaderProps) {
    return (
        <header className="page-header">
            {back && (
                <p className="mb-2">
                    <Link href={back.href} className="back-link">
                        <ArrowLeft aria-hidden="true" focusable="false" />
                        {back.label}
                    </Link>
                </p>
            )}
            <div className="page-header__row">
                <div>
                    {eyebrow && <p className="small text-body-secondary fw-semibold mb-1">{eyebrow}</p>}
                    <h1 className="h2 page-header__title">{title}</h1>
                    {children}
                </div>
                {actions && <div className="page-header__actions">{actions}</div>}
            </div>
        </header>
    );
}
