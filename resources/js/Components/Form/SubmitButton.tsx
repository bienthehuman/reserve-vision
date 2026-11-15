import type { ReactNode } from 'react';
import { Button, Spinner } from 'react-bootstrap';

interface SubmitButtonProps {
    children: ReactNode;
    /** Shown while the request runs, e.g. "Logging in…". */
    pendingLabel: ReactNode;
    processing: boolean;
    className?: string;
}

/** The form's submit button, with a pending state (spinner + label, disabled) while the request runs. */
export default function SubmitButton({ children, pendingLabel, processing, className = 'w-100' }: SubmitButtonProps) {
    return (
        <Button
            type="submit"
            variant="primary"
            disabled={processing}
            className={`d-inline-flex align-items-center justify-content-center gap-2 ${className}`}
        >
            {processing && <Spinner as="span" animation="border" size="sm" aria-hidden="true" />}
            <span>{processing ? pendingLabel : children}</span>
        </Button>
    );
}
