import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Container } from 'react-bootstrap';
import PublicLayout from './PublicLayout';

interface NavItem {
    href: string;
    label: string;
    ownerOnly?: boolean;
}

const items: NavItem[] = [
    { href: '/landlord', label: 'Dashboard' },
    { href: '/landlord/reservations', label: 'Reservations' },
    { href: '/landlord/viewings', label: 'Viewings' },
    { href: '/landlord/properties', label: 'Properties' },
    { href: '/landlord/members', label: 'Members', ownerOnly: true },
];

/** The page's path without the query string. */
function pathOf(url: string): string {
    return url.split('?')[0] ?? url;
}

/** Whether `href` is the current page or the section the current page belongs to. */
function isCurrent(href: string, url: string): boolean {
    const path = pathOf(url);

    return href === '/landlord' ? path === href : path === href || path.startsWith(`${href}/`);
}

/** Every /landlord page: the public header plus the landlord menu. A caretaker has no Members link (owners only). */
export default function LandlordLayout({ children }: { children: ReactNode }) {
    const { url, props } = usePage();
    const isOwner = props.auth.user?.landlord_role === 'owner';

    return (
        <PublicLayout
            subnav={
                <nav aria-label="Landlord" className="landlord-nav">
                    <Container>
                        <ul className="landlord-nav__list">
                            {items
                                .filter((item) => !item.ownerOnly || isOwner)
                                .map((item) => (
                                    <li key={item.href}>
                                        <Link
                                            href={item.href}
                                            className="landlord-nav__link"
                                            aria-current={isCurrent(item.href, url) ? 'page' : undefined}
                                        >
                                            {item.label}
                                        </Link>
                                    </li>
                                ))}
                        </ul>
                    </Container>
                </nav>
            }
        >
            {children}
        </PublicLayout>
    );
}
