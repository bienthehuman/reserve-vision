import type { ComponentType, SVGProps } from 'react';
import { Badge } from 'react-bootstrap';
import { CheckCircle, CheckCircleFill, Hourglass, SlashCircle, XCircle } from 'react-bootstrap-icons';
import type { BadgeColor } from '@/Components/StatusBadge';
import type { ViewingStatus } from '@/types/viewing';

interface StatusStyle {
    label: string;
    color: BadgeColor;
    Icon: ComponentType<SVGProps<SVGSVGElement>>;
}

/** The one place viewing status colors, words and icons are chosen (DESIGN_SYSTEM.md: requested → info, accepted → success, declined → danger, done or cancelled → secondary). */
export const viewingStatusStyles: Record<ViewingStatus, StatusStyle> = {
    requested: { label: 'Requested', color: 'info', Icon: Hourglass },
    accepted: { label: 'Accepted', color: 'success', Icon: CheckCircle },
    declined: { label: 'Declined', color: 'danger', Icon: XCircle },
    done: { label: 'Done', color: 'secondary', Icon: CheckCircleFill },
    cancelled: { label: 'Cancelled', color: 'secondary', Icon: SlashCircle },
};

interface ViewingStatusBadgeProps {
    status: ViewingStatus;
    /** Extra context after the label, e.g. "time has passed". */
    detail?: string;
}

/** A viewing request's status as the same pill the reservations use. */
export default function ViewingStatusBadge({ status, detail }: ViewingStatusBadgeProps) {
    const { label, color, Icon } = viewingStatusStyles[status];

    return (
        <Badge pill bg="" className={`status-badge status-badge--${color}`} data-status={status}>
            <Icon aria-hidden="true" focusable="false" />
            <span>{detail ? `${label} · ${detail}` : label}</span>
        </Badge>
    );
}
