/**
 * Color tokens shown on /styleguide. The swatch colors come from
 * resources/scss/_components.scss; designTokens.test.ts checks that these hex
 * values match both _tokens.scss and docs/DESIGN_SYSTEM.md.
 */
export interface DesignToken {
    name: string;
    hex: string;
    use: string;
}

export const designTokens: DesignToken[] = [
    { name: 'primary', hex: '#5B3E96', use: 'Buttons, links, focus ring, prices' },
    { name: 'primary-hover', hex: '#47307A', use: 'Hover and pressed' },
    { name: 'primary-subtle', hex: '#F1ECF8', use: 'Selected chips, "Living here"' },
    { name: 'mango', hex: '#F7C948', use: 'Highlight, background only' },
    { name: 'ink', hex: '#1F2A2E', use: 'Text, footer' },
    { name: 'muted', hex: '#5B6770', use: 'Secondary text' },
    { name: 'border-strong', hex: '#7D878F', use: 'Input borders' },
    { name: 'border', hex: '#D9DEE1', use: 'Cards, dividers' },
    { name: 'surface', hex: '#FFFFFF', use: 'Cards, inputs' },
    { name: 'bg', hex: '#FAF8F5', use: 'Page background' },
    { name: 'success', hex: '#1E7B45', use: 'Confirmed, available' },
    { name: 'success-subtle', hex: '#E7F4EC', use: 'Success background' },
    { name: 'warning', hex: '#F7C948', use: 'Holds, deadlines (= mango)' },
    { name: 'warning-subtle', hex: '#FEF6DA', use: 'Warning background' },
    { name: 'danger', hex: '#B42318', use: 'Declined, errors' },
    { name: 'danger-subtle', hex: '#FDECEA', use: 'Danger background' },
    { name: 'info', hex: '#1D5FA8', use: 'Awaiting review' },
    { name: 'info-subtle', hex: '#E8F0FA', use: 'Info background' },
    { name: 'secondary', hex: '#5B6770', use: 'Ended, expired (= muted)' },
    { name: 'secondary-subtle', hex: '#EEF0F1', use: 'Secondary background' },
];
