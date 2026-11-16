import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { CheckCircleFill, ExclamationTriangleFill } from 'react-bootstrap-icons';

/** How long a confirmation stays before it closes itself. Long enough to read two lines; paused while hovered or focused. */
export const TOAST_MS = 6000;

/**
 * A confirmation ("Saved Room 1.", "We've sent an invitation to…"): a toast (DESIGN_SYSTEM.md "Feedback").
 * Fixed under the header, so it never pushes the page down or sits above the menu; it closes itself after
 * six seconds, waits while the pointer or the keyboard focus is on it, and has a close button.
 */
export function FlashToast() {
    const { flash } = usePage().props;
    // Each response brings a new `flash` object, so "closed" is remembered per response: the same sentence shows again after the next action.
    const [closed, setClosed] = useState<unknown>(null);
    const [paused, setPaused] = useState(false);
    const open = !!flash.status && closed !== flash;

    useEffect(() => {
        if (!open || paused) {
            return;
        }

        const timer = window.setTimeout(() => setClosed(flash), TOAST_MS);

        return () => window.clearTimeout(timer);
    }, [open, paused, flash]);

    if (!open) {
        return null;
    }

    return (
        <div className="toast-region">
            <div
                className="alert alert-success flash-toast"
                role="status"
                onMouseEnter={() => setPaused(true)}
                onMouseLeave={() => setPaused(false)}
                onFocus={() => setPaused(true)}
                onBlur={() => setPaused(false)}
            >
                <CheckCircleFill aria-hidden="true" focusable="false" className="flash-toast__icon" />
                <span className="flash-toast__text">{flash.status}</span>
                <button type="button" className="btn-close" aria-label="Dismiss this message" onClick={() => setClosed(flash)} />
            </div>
        </div>
    );
}

/**
 * A broken rule ("Room 1 can't be archived while…"): something the user must read, so it stays on the page,
 * at the top of the content (below any menu), until they close it or do something else. It scrolls into
 * view and takes focus when it appears.
 */
export function FlashError() {
    const { flash } = usePage().props;
    const [closed, setClosed] = useState<unknown>(null);
    const ref = useRef<HTMLDivElement>(null);

    // A form post keeps the scroll position, and the message is at the top: bring it into view and
    // move focus to it (as the error summary of a form does), so it can't be missed halfway down a page.
    useEffect(() => {
        if (flash.error) {
            ref.current?.scrollIntoView?.({ block: 'center' });
            ref.current?.focus({ preventScroll: true });
        }
    }, [flash]);

    if (!flash.error || closed === flash) {
        return null;
    }

    return (
        <div className="container pt-3">
            <div className="alert alert-danger flash-error mb-0" role="alert" tabIndex={-1} ref={ref}>
                <ExclamationTriangleFill aria-hidden="true" focusable="false" className="flash-toast__icon" />
                <span className="flash-toast__text">{flash.error}</span>
                <button type="button" className="btn-close" aria-label="Dismiss this message" onClick={() => setClosed(flash)} />
            </div>
        </div>
    );
}
