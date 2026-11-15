import { forwardRef } from 'react';
import { Alert } from 'react-bootstrap';
import { fieldId } from './TextField';

interface ErrorSummaryProps {
    /** Field name → message, as Inertia's form.errors. */
    errors: Partial<Record<string, string>>;
    /** Field order on the page, so the list reads top to bottom. */
    order: readonly string[];
}

/**
 * "There's a problem" box at the top of a form (DESIGN_SYSTEM.md, "Forms"): one
 * link per error that moves focus to its field. Focusable, so the page can move
 * focus here after a failed submit; screen readers then read it out.
 */
const ErrorSummary = forwardRef<HTMLDivElement, ErrorSummaryProps>(function ErrorSummary({ errors, order }, ref) {
    const names = [...order.filter((name) => errors[name]), ...Object.keys(errors).filter((name) => !order.includes(name) && errors[name])];

    if (names.length === 0) {
        return null;
    }

    return (
        <Alert variant="danger" ref={ref} tabIndex={-1} aria-labelledby="error-summary-title" className="mb-4">
            <h2 id="error-summary-title" className="h3 mb-2">
                {names.length === 1 ? 'Please fix this:' : `Please fix these ${names.length} things:`}
            </h2>
            <ul className="mb-0 ps-3">
                {names.map((name) => (
                    <li key={name}>
                        <a href={`#${fieldId(name)}`} className="alert-link">
                            {errors[name]}
                        </a>
                    </li>
                ))}
            </ul>
        </Alert>
    );
});

export default ErrorSummary;
