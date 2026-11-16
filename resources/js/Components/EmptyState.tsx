import type { ComponentType, ReactNode, SVGProps } from 'react';

interface EmptyStateProps {
    Icon: ComponentType<SVGProps<SVGSVGElement> & { size?: number }>;
    children: ReactNode;
    /** At most one action: a link styled as a button. */
    action?: ReactNode;
}

/** Nothing here yet: an icon, one sentence and at most one action (DESIGN_SYSTEM.md). */
export default function EmptyState({ Icon, children, action }: EmptyStateProps) {
    return (
        <div className="empty-state">
            <span className="empty-state__icon">
                <Icon aria-hidden="true" focusable="false" size={22} />
            </span>
            <p>{children}</p>
            {action}
        </div>
    );
}
