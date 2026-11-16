import { Form } from 'react-bootstrap';
import { ExclamationCircle } from 'react-bootstrap-icons';
import type { ReactNode } from 'react';
import { fieldId } from './TextField';

export interface SelectFieldProps {
    /** The form field name; the select's id is `field-<name>`, which the error summary links to. */
    name: string;
    label: ReactNode;
    value: string;
    onChange: (value: string) => void;
    options: readonly { value: string; label: string }[];
    /** A first, empty option such as "Choose a city". */
    placeholder?: string;
    error?: string;
    help?: ReactNode;
    required?: boolean;
    disabled?: boolean;
    className?: string;
}

/** A native select (DESIGN_SYSTEM.md, "Forms"), labelled above, help and error below, like TextField. */
export default function SelectField({
    name,
    label,
    value,
    onChange,
    options,
    placeholder,
    error,
    help,
    required = false,
    disabled = false,
    className = 'mb-3',
}: SelectFieldProps) {
    const id = fieldId(name);
    const helpId = help ? `${id}-help` : undefined;
    const errorId = error ? `${id}-error` : undefined;

    return (
        <Form.Group className={className} controlId={id}>
            <Form.Label>{label}</Form.Label>
            <Form.Select
                name={name}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                isInvalid={!!error}
                aria-invalid={error ? true : undefined}
                aria-describedby={[errorId, helpId].filter(Boolean).join(' ') || undefined}
                required={required}
                disabled={disabled}
            >
                {placeholder !== undefined && <option value="">{placeholder}</option>}
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </Form.Select>
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
