#!/usr/bin/env node
// CSS budget (docs/DESIGN_SYSTEM.md): app.css must be at most 35 KB gzipped.
// Run after `npm run build`. Reads the Vite manifest to find the built app CSS,
// prints the size and, on GitHub Actions, adds it to the job summary.
//
//   node bin/check-css-budget.mjs
import { appendFileSync, readFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';

const BUDGET_BYTES = 35_000; // 35 KB, in the same 1000-byte kB that Vite reports
const ENTRY = 'resources/js/app.tsx';

const manifest = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'));
const cssFiles = manifest[ENTRY]?.css ?? [];

if (cssFiles.length !== 1) {
    console.error(`Expected exactly one CSS file for ${ENTRY} in public/build/manifest.json, found ${cssFiles.length}.`);
    process.exit(1);
}

const file = `public/build/${cssFiles[0]}`;
const raw = readFileSync(file);
const gzipped = gzipSync(raw, { level: 9 }).length;
const kb = (bytes) => `${(bytes / 1000).toFixed(2)} kB`;
const ok = gzipped <= BUDGET_BYTES;

const line = `app.css: ${kb(gzipped)} gzipped (${kb(raw.length)} raw), budget ${kb(BUDGET_BYTES)}: ${ok ? 'within budget' : 'OVER BUDGET'}`;
console.log(`${file}\n${line}`);

if (process.env.GITHUB_STEP_SUMMARY) {
    appendFileSync(
        process.env.GITHUB_STEP_SUMMARY,
        [
            '### CSS budget',
            '',
            '| File | Raw | Gzipped | Budget | Result |',
            '| --- | ---: | ---: | ---: | --- |',
            `| \`${cssFiles[0]}\` | ${kb(raw.length)} | **${kb(gzipped)}** | ${kb(BUDGET_BYTES)} | ${ok ? '✅ within budget' : '❌ over budget'} |`,
            '',
        ].join('\n'),
    );
}

if (!ok) {
    console.error(`::error file=resources/scss/app.scss::app.css is ${kb(gzipped)} gzipped, over the ${kb(BUDGET_BYTES)} budget (docs/DESIGN_SYSTEM.md).`);
    process.exit(1);
}
