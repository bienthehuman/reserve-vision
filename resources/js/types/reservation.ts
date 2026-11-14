/** Mirrors the PHP ReservationStatus enum (slice 2.1) and docs/RESERVATIONS.md. */
export const reservationStatuses = [
    'held',
    'proof_uploaded',
    'confirmed',
    'active',
    'expired',
    'declined',
    'cancelled',
    'ended',
] as const;

export type ReservationStatus = (typeof reservationStatuses)[number];
