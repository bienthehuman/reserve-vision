import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from 'react-bootstrap';

interface ActionButtonProps {
    /** The endpoint; a POST archives and a DELETE brings back, as the routes do. */
    href: string;
    method: 'post' | 'delete';
    children: string;
    /** For screen readers when the visible text alone is ambiguous in a list: "Archive Bed A". Starts with the visible text. */
    accessibleName?: string;
    variant?: string;
    /** Sent with a POST, e.g. `{ direction: 'up' }`. */
    data?: Record<string, string>;
    disabled?: boolean;
}

/** A small button that sends one Inertia request and shows it is working (disabled while it runs). */
export default function ActionButton({ href, method, children, accessibleName, variant = 'outline-primary', data = {}, disabled = false }: ActionButtonProps) {
    const [processing, setProcessing] = useState(false);

    const send = () => {
        const options = { preserveScroll: true, onStart: () => setProcessing(true), onFinish: () => setProcessing(false) };

        if (method === 'post') {
            router.post(href, data, options);
        } else {
            router.delete(href, options);
        }
    };

    return (
        <Button type="button" size="sm" variant={variant} disabled={processing || disabled} aria-label={accessibleName} onClick={send}>
            {processing ? 'Working…' : children}
        </Button>
    );
}
