import { router } from '@inertiajs/react';
import { useId, useState, type ReactNode } from 'react';
import { Button, Modal } from 'react-bootstrap';

interface ConfirmButtonProps {
    /** The endpoint the confirming button calls. */
    href: string;
    method: 'post' | 'delete';
    /** The button on the page: "Remove". */
    children: string;
    /** For screen readers when the visible text alone is ambiguous in a list: "Remove Ana Cruz". Starts with the visible text. */
    accessibleName?: string;
    /** The dialog's question: "Remove Ana Cruz?". */
    title: string;
    /** What will happen, in a sentence or two (a string is wrapped in a paragraph; anything else is shown as it is). */
    body: ReactNode;
    /** The dialog's danger button: "Remove member". */
    confirmLabel: string;
    pendingLabel: string;
    /** The dialog's way out: "Keep them". */
    cancelLabel: string;
    size?: 'sm' | 'md';
}

/**
 * A button for something that can't be undone with one tap (DESIGN_SYSTEM.md "Buttons"):
 * `outline-danger` on the page opens a dialog, and the dialog's `danger` button does it.
 */
export default function ConfirmButton({ href, method, children, accessibleName, title, body, confirmLabel, pendingLabel, cancelLabel, size = 'sm' }: ConfirmButtonProps) {
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);
    const titleId = useId();

    const confirm = () => {
        const options = {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setConfirming(false);
            },
        };

        if (method === 'post') {
            router.post(href, {}, options);
        } else {
            router.delete(href, options);
        }
    };

    return (
        <>
            <Button type="button" size={size === 'sm' ? 'sm' : undefined} variant="outline-danger" aria-label={accessibleName} onClick={() => setConfirming(true)}>
                {children}
            </Button>
            {/* No fade: the dialog is there at once (and axe, which reads colours mid-fade as low contrast, sees the real ones). */}
            <Modal show={confirming} onHide={() => setConfirming(false)} centered animation={false} aria-labelledby={titleId}>
                <Modal.Header closeButton>
                    <Modal.Title as="h2" className="h3" id={titleId}>
                        {title}
                    </Modal.Title>
                </Modal.Header>
                <Modal.Body>
                    {typeof body === 'string' ? <p className="mb-0">{body}</p> : body}
                </Modal.Body>
                <Modal.Footer>
                    <Button type="button" variant="outline-primary" onClick={() => setConfirming(false)}>
                        {cancelLabel}
                    </Button>
                    <Button type="button" variant="danger" disabled={processing} onClick={confirm}>
                        {processing ? pendingLabel : confirmLabel}
                    </Button>
                </Modal.Footer>
            </Modal>
        </>
    );
}
