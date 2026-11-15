import { useState, type ChangeEvent, type InputHTMLAttributes, type ReactNode } from 'react';
import { Form } from 'react-bootstrap';
import { ExclamationCircle, Eye, EyeSlash } from 'react-bootstrap-icons';

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
 * A password field gets a show/hide button inside the input.
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
    const isPassword = type === 'password';
    const [revealed, setRevealed] = useState(false);

    const input = (
        <Form.Control
            type={isPassword && revealed ? 'text' : type}
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
            // A revealed password is still a password: no spellcheck, no autocorrect.
            spellCheck={isPassword ? false : undefined}
            autoCapitalize={isPassword ? 'none' : undefined}
        />
    );

    return (
        <Form.Group className={className} controlId={id}>
            <Form.Label>
                {label}
                {optional && <span className="text-body-secondary fw-normal"> (optional)</span>}
            </Form.Label>
            {isPassword ? (
                <div className="password-field">
                    {input}
                    <button
                        type="button"
                        className="password-field__toggle"
                        aria-label="Show password"
                        aria-pressed={revealed}
                        aria-controls={id}
                        onClick={() => setRevealed((shown) => !shown)}
                    >
                        {revealed ? <EyeSlash aria-hidden="true" focusable="false" size={20} /> : <Eye aria-hidden="true" focusable="false" size={20} />}
                    </button>
                </div>
            ) : (
                input
            )}
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
