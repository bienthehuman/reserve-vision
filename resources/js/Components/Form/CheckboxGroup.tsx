import { Form } from 'react-bootstrap';
import type { ReactNode } from 'react';
import { fieldId } from './TextField';

export interface CheckboxGroupProps {
    /** The form field name; the fieldset's id is `field-<name>`, which the error summary links to. */
    name: string;
    legend: ReactNode;
    options: readonly { id: number; name: string }[];
    value: readonly number[];
    onChange: (value: number[]) => void;
    help?: ReactNode;
    className?: string;
}

/** A fieldset of checkboxes (multi-select), two columns from `sm`. */
export default function CheckboxGroup({ name, legend, options, value, onChange, help, className = 'mb-3' }: CheckboxGroupProps) {
    const id = fieldId(name);
    const helpId = help ? `${id}-help` : undefined;

    const toggle = (optionId: number, checked: boolean) => {
        onChange(checked ? [...value, optionId] : value.filter((current) => current !== optionId));
    };

    return (
        <fieldset className={className} id={id} aria-describedby={helpId} tabIndex={-1}>
            <legend className="form-label fs-6 float-none">{legend}</legend>
            <div className="row row-cols-1 row-cols-sm-2 g-0">
                {options.map((option) => (
                    <div className="col" key={option.id}>
                        <Form.Check
                            type="checkbox"
                            id={`${id}-${option.id}`}
                            label={option.name}
                            checked={value.includes(option.id)}
                            onChange={(event) => toggle(option.id, event.target.checked)}
                        />
                    </div>
                ))}
            </div>
            {help && (
                <Form.Text id={helpId} className="d-block">
                    {help}
                </Form.Text>
            )}
        </fieldset>
    );
}
