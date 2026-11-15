import { Form } from 'react-bootstrap';
import { ExclamationCircle } from 'react-bootstrap-icons';
import type { ReactNode } from 'react';
import { fieldId } from './TextField';

export interface TextAreaFieldProps {
    name: string;
    label: ReactNode;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    help?: ReactNode;
    rows?: number;
    optional?: boolean;
    required?: boolean;
    placeholder?: string;
    className?: string;
}

/** A multi-line text field with the same label, help and error pattern as TextField. */
export default function TextAreaField({
    name,
    label,
    value,
    onChange,
    error,
    help,
    rows = 4,
    optional = false,
    required = false,
    placeholder,
    className = 'mb-3',
}: TextAreaFieldProps) {
    const id = fieldId(name);
    const helpId = help ? `${id}-help` : undefined;
    const errorId = error ? `${id}-error` : undefined;

    return (
        <Form.Group className={className} controlId={id}>
            <Form.Label>
                {label}
                {optional && <span className="text-body-secondary fw-normal"> (optional)</span>}
            </Form.Label>
            <Form.Control
                as="textarea"
                rows={rows}
                name={name}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                isInvalid={!!error}
                aria-invalid={error ? true : undefined}
                aria-describedby={[errorId, helpId].filter(Boolean).join(' ') || undefined}
                required={required}
                placeholder={placeholder}
            />
            {error && (
                <Form.Control.Feedback type="invalid" id={errorId} className="d-flex">
                    <ExclamationCircle aria-hidden="true" focusable="false" className="flex-shrink-0" />
                    <span>{error}</span>
                </Form.Control.Feedback>
            )}
            {help && (
                <Form.Text id={helpId} className="d-block">
                    {help}
                </Form.Text>
            )}
        </Form.Group>
    );
}
