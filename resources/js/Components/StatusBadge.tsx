import type { ComponentType, SVGProps } from 'react';
import { Badge } from 'react-bootstrap';
import {
    BoxArrowRight,
    CheckCircle,
    ClockHistory,
    FileEarmarkCheck,
    Hourglass,
    HouseDoor,
    SlashCircle,
    XCircle,
} from 'react-bootstrap-icons';
import type { ReservationStatus } from '@/types/reservation';

export type BadgeColor = 'primary' | 'success' | 'warning' | 'danger' | 'info' | 'secondary';

interface StatusStyle {
    label: string;
    color: BadgeColor;
    Icon: ComponentType<SVGProps<SVGSVGElement>>;
}

/** The one place reservation status colors, words and icons are chosen (DESIGN_SYSTEM.md). */
export const statusStyles: Record<ReservationStatus, StatusStyle> = {
    held: { label: 'On hold', color: 'warning', Icon: Hourglass },
    proof_uploaded: { label: 'Awaiting review', color: 'info', Icon: FileEarmarkCheck },
    confirmed: { label: 'Confirmed', color: 'success', Icon: CheckCircle },
    active: { label: 'Living here', color: 'primary', Icon: HouseDoor },
    expired: { label: 'Hold expired', color: 'secondary', Icon: ClockHistory },
    declined: { label: 'Declined', color: 'danger', Icon: XCircle },
    cancelled: { label: 'Cancelled', color: 'secondary', Icon: SlashCircle },
    ended: { label: 'Stay ended', color: 'secondary', Icon: BoxArrowRight },
};

interface StatusBadgeProps {
    status: ReservationStatus;
    /** Extra context after the label, e.g. "23h left" for a hold. */
    detail?: string;
}

export default function StatusBadge({ status, detail }: StatusBadgeProps) {
    const { label, color, Icon } = statusStyles[status];

    return (
        <Badge pill bg="" className={`status-badge status-badge--${color}`} data-status={status}>
            <Icon aria-hidden="true" focusable="false" />
            <span>{detail ? `${label} · ${detail}` : label}</span>
        </Badge>
    );
}
