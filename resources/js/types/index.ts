/** The logged-in user, as shared by HandleInertiaRequests::user(). Only what the header needs. */
export interface AuthUser {
    id: number;
    first_name: string;
    full_name: string;
    type: 'renter' | 'landlord';
    email_verified: boolean;
}

/** Props shared with every page by App\Http\Middleware\HandleInertiaRequests. */
export interface SharedProps {
    appName: string;
    auth: { user: AuthUser | null };
    /**
     * `error`: the message of a broken business rule after a form post.
     * `status`: a short confirmation ("We've sent you a link…").
     */
    flash: { error: string | null; status: string | null };
    [key: string]: unknown;
}

export type { ReservationStatus } from './reservation';
