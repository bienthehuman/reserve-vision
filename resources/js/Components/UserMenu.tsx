import { Link, usePage } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';
import { BoxArrowRight, CalendarCheck, ChevronDown, Speedometer2 } from 'react-bootstrap-icons';
import type { AuthUser } from '@/types';

function roleOf(user: AuthUser): string {
    if (user.type === 'renter') {
        return 'Renter';
    }

    return user.landlord_role === 'caretaker' ? 'Caretaker' : user.landlord_role === 'owner' ? 'Owner' : 'Landlord';
}

/**
 * The header's account menu: the user's first name is a button that opens a small panel
 * with their full name, their role and Log out (and, for a landlord away from the
 * landlord pages, the way back to the dashboard; for a renter, My reservations). A disclosure, not an ARIA menu: Tab
 * moves through it, Escape closes it and returns focus, and a click or focus outside closes it.
 */
export default function UserMenu({ user }: { user: AuthUser }) {
    const { url } = usePage();
    const [open, setOpen] = useState(false);
    const root = useRef<HTMLDivElement>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    const panelId = useId();
    const onLandlordPages = url.split('?')[0]?.startsWith('/landlord') ?? false;

    useEffect(() => {
        if (!open) {
            return;
        }

        const closeIfOutside = (event: Event) => {
            if (root.current && event.target instanceof Node && !root.current.contains(event.target)) {
                setOpen(false);
            }
        };
        const closeOnEscape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
                trigger.current?.focus();
            }
        };

        document.addEventListener('pointerdown', closeIfOutside);
        document.addEventListener('focusin', closeIfOutside);
        document.addEventListener('keydown', closeOnEscape);

        return () => {
            document.removeEventListener('pointerdown', closeIfOutside);
            document.removeEventListener('focusin', closeIfOutside);
            document.removeEventListener('keydown', closeOnEscape);
        };
    }, [open]);

    return (
        <div className="user-menu" ref={root}>
            <button
                type="button"
                ref={trigger}
                className="user-menu__trigger"
                aria-expanded={open}
                aria-controls={panelId}
                onClick={() => setOpen((shown) => !shown)}
            >
                <span className="user-menu__avatar" aria-hidden="true">
                    {user.first_name.charAt(0)}
                </span>
                <span className="text-truncate">{user.first_name}</span>
                <span className="visually-hidden">, account menu</span>
                <ChevronDown aria-hidden="true" focusable="false" size={14} className="user-menu__chevron" />
            </button>
            <div id={panelId} className="user-menu__panel" hidden={!open}>
                <p className="user-menu__who">
                    <span className="user-menu__name">{user.full_name}</span>
                    <span className="user-menu__role">{roleOf(user)}</span>
                </p>
                <ul className="user-menu__list">
                    {user.type === 'landlord' && !onLandlordPages && (
                        <li>
                            <Link href="/landlord" className="user-menu__item">
                                <Speedometer2 aria-hidden="true" focusable="false" />
                                Dashboard
                            </Link>
                        </li>
                    )}
                    {user.type === 'renter' && (
                        <li>
                            <Link href="/reservations" className="user-menu__item">
                                <CalendarCheck aria-hidden="true" focusable="false" />
                                My reservations
                            </Link>
                        </li>
                    )}
                    <li>
                        <Link href="/logout" method="post" as="button" className="user-menu__item">
                            <BoxArrowRight aria-hidden="true" focusable="false" />
                            Log out
                        </Link>
                    </li>
                </ul>
            </div>
        </div>
    );
}
