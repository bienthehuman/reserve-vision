/** Mirrors the PHP ViewingRequestStatus enum (slice 6.2) and docs/ACCESS.md flow C. */
export const viewingStatuses = ['requested', 'accepted', 'declined', 'done', 'cancelled'] as const;

export type ViewingStatus = (typeof viewingStatuses)[number];

/** What pages show: the status, or `expired` for a request nobody answered before its time (display only). */
export type ShownViewingStatus = ViewingStatus | 'expired';
