import { router } from '@inertiajs/react';
import { useId, useState, type ReactNode } from 'react';
import { Button, Modal } from 'react-bootstrap';
import TextAreaField from '@/Components/Form/TextAreaField';

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
    /** The dialog's confirming button: "Remove member". */
    confirmLabel: string;
    pendingLabel: string;
    /** The dialog's way out: "Keep them". */
    cancelLabel: string;
    size?: 'sm' | 'md';
    /**
     * `danger` (the default) for what can't be undone: `outline-danger` on the page, `danger` in the dialog.
     * `primary` for a move that asks first but isn't destructive (slice 6.1: "Ask for a new proof"):
     * `outline-primary`, then `primary`.
     */
    tone?: 'danger' | 'primary';
    /**
     * A required text field in the dialog, sent as `reason` (slice 6.1: reject, decline, withdraw). A validation
     * error keeps the dialog open and shows under the field.
     */
    reason?: { label: string; help?: string };
    disabled?: boolean;
    /** The id of text that explains the button, e.g. why it is disabled. */
    describedBy?: string;
}

/**
 * A button for something that can't be undone with one tap (DESIGN_SYSTEM.md "Buttons"):
 * `outline-danger` on the page opens a dialog, and the dialog's `danger` button does it.
 */
export default function ConfirmButton({
    href,
    method,
    children,
    accessibleName,
    title,
    body,
    confirmLabel,
    pendingLabel,
    cancelLabel,
    size = 'sm',
    tone = 'danger',
    reason,
    disabled = false,
    describedBy,
}: ConfirmButtonProps) {
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [text, setText] = useState('');
    const [error, setError] = useState<string | undefined>();
    const titleId = useId();
    const fieldName = `reason-${useId().replace(/[^a-zA-Z0-9]/g, '')}`;

    const close = () => {
        setConfirming(false);
        setError(undefined);
    };

    const confirm = () => {
        if (reason && text.trim() === '') {
            setError('Write a reason. The renter will see it.');

            return;
        }

        const options = {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onError: (errors: Record<string, string>) => setError(errors.reason),
            onSuccess: () => {
                close();
                setText('');
            },
            onFinish: () => setProcessing(false),
        };
        const data = reason ? { reason: text } : {};

        if (method === 'post') {
            router.post(href, data, options);
        } else {
            router.delete(href, { ...options, data });
        }
    };

    return (
        <>
            <Button
                type="button"
                size={size === 'sm' ? 'sm' : undefined}
                variant={tone === 'danger' ? 'outline-danger' : 'outline-primary'}
                aria-label={accessibleName}
                aria-describedby={describedBy}
                disabled={disabled}
                onClick={() => setConfirming(true)}
            >
                {children}
            </Button>
            {/* No fade: the dialog is there at once (and axe, which reads colours mid-fade as low contrast, sees the real ones). */}
            <Modal show={confirming} onHide={close} centered animation={false} aria-labelledby={titleId}>
                <Modal.Header closeButton>
                    <Modal.Title as="h2" className="h3" id={titleId}>
                        {title}
                    </Modal.Title>
                </Modal.Header>
                <Modal.Body>
                    {typeof body === 'string' ? <p className={reason ? undefined : 'mb-0'}>{body}</p> : body}
                    {reason && (
                        <TextAreaField
                            name={fieldName}
                            label={reason.label}
                            help={reason.help}
                            value={text}
                            onChange={(value) => {
                                setText(value);
                                setError(undefined);
                            }}
                            error={error}
                            rows={3}
                            required
                            className="mb-0 mt-3"
                        />
                    )}
                </Modal.Body>
                <Modal.Footer>
                    <Button type="button" variant="outline-primary" onClick={close}>
                        {cancelLabel}
                    </Button>
                    <Button type="button" variant={tone} disabled={processing} onClick={confirm}>
                        {processing ? pendingLabel : confirmLabel}
                    </Button>
                </Modal.Footer>
            </Modal>
        </>
    );
}
