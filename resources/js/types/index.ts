/** Props shared with every page by App\Http\Middleware\HandleInertiaRequests. */
export interface SharedProps {
    appName: string;
    /** `error`: the message of a broken business rule after a form post. */
    flash: { error: string | null };
    [key: string]: unknown;
}

export type { ReservationStatus } from './reservation';
