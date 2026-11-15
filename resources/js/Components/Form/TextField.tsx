import type { ChangeEvent, InputHTMLAttributes, ReactNode } from 'react';
import { Form } from 'react-bootstrap';
import { ExclamationCircle } from 'react-bootstrap-icons';

export interface TextFieldProps {
    /** The form field name; the input's id is `field-<name>`, which the error summary links to. */
    name: string;
    label: ReactNode;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    /** Help text below the input (Form.Text), linked with aria-describedby. */
    help?: ReactNode;
    type?: 'text' | 'email' | 'password' | 'tel';
    autoComplete?: string;
    inputMode?: InputHTMLAttributes<HTMLInputElement>['inputMode'];
    placeholder?: string;
    required?: boolean;
    optional?: boolean;
    readOnly?: boolean;
    autoFocus?: boolean;
    className?: string;
}

export function fieldId(name: string): string {
    return `field-${name}`;
}

/**
 * A labelled input (DESIGN_SYSTEM.md, "Forms"): label above, help and error below,
 * both linked with aria-describedby; the error is danger text with an icon.
 */
export default function TextField({
    name,
    label,
    value,
    onChange,
    error,
    help,
    type = 'text',
    autoComplete,
    inputMode,
    placeholder,
    required = false,
    optional = false,
    readOnly = false,
    autoFocus = false,
    className = 'mb-3',
}: TextFieldProps) {
    const id = fieldId(name);
    const helpId = help ? `${id}-help` : undefined;
    const errorId = error ? `${id}-error` : undefined;
    const describedBy = [errorId, helpId].filter(Boolean).join(' ') || undefined;

    return (
        <Form.Group className={className} controlId={id}>
            <Form.Label>
                {label}
                {optional && <span className="text-body-secondary fw-normal"> (optional)</span>}
            </Form.Label>
            <Form.Control
                type={type}
                name={name}
                value={value}
                onChange={(event: ChangeEvent<HTMLInputElement>) => onChange(event.target.value)}
                isInvalid={!!error}
                aria-invalid={error ? true : undefined}
                aria-describedby={describedBy}
                autoComplete={autoComplete}
                inputMode={inputMode}
                placeholder={placeholder}
                required={required}
                readOnly={readOnly}
                autoFocus={autoFocus}
            />
            {error && (
                <Form.Control.Feedback type="invalid" id={errorId}>
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
