import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { designTokens } from './designTokens';

// Vitest runs from the repo root.
const root = `${process.cwd()}/`;
const scss = readFileSync(`${root}resources/scss/_tokens.scss`, 'utf8');
const doc = readFileSync(`${root}docs/DESIGN_SYSTEM.md`, 'utf8');

/** Resolves `$rv-name: #hex;` and `$rv-name: $rv-other;` declarations. */
function scssToken(name: string): string | undefined {
    const match = scss.match(new RegExp(`^\\$rv-${name}:\\s*([^;]+);`, 'm'));
    const value = match?.[1]?.trim();
    if (value?.startsWith('$rv-')) {
        return scssToken(value.slice(4));
    }
    return value?.toUpperCase();
}

describe('design tokens', () => {
    it.each(designTokens)('$name matches _tokens.scss and DESIGN_SYSTEM.md', ({ name, hex }) => {
        expect(scssToken(name)).toBe(hex);
        expect(doc.toUpperCase()).toContain(hex);
    });
});
