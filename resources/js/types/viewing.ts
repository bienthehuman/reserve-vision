/** Mirrors the PHP ViewingRequestStatus enum (slice 6.2) and docs/ACCESS.md flow C. */
export const viewingStatuses = ['requested', 'accepted', 'declined', 'done', 'cancelled'] as const;

export type ViewingStatus = (typeof viewingStatuses)[number];
