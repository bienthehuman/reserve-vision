/** Props shared with every page by App\Http\Middleware\HandleInertiaRequests. */
export interface SharedProps {
    appName: string;
    [key: string]: unknown;
}

export type { ReservationStatus } from './reservation';
