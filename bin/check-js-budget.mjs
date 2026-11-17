#!/usr/bin/env node
// JavaScript budget (docs/ARCHITECTURE.md, "Budgets"), the sibling of bin/check-css-budget.mjs.
// Run after `npm run build`. Two gzipped limits, each set at the size after the 7.2 fixes plus about 5 %:
//
//   entry chunk    resources/js/app.tsx's file: React DOM + Inertia, which every page loads
//   listing page   what /listings/{city}/{slug} loads BEYOND the entry: Pages/Listings/Show.tsx and
//                  every chunk it imports statically (Bootstrap pieces, icons, shared components)
//
// Sizes are gzip level 9 of the built files, in 1000-byte kB (as Vite reports). Prints both and, on
// GitHub Actions, adds them to the job summary. Raising a limit is a decision: say why in the PR.
//
//   node bin/check-js-budget.mjs
import { appendFileSync, readFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';

const ENTRY = 'resources/js/app.tsx';
const LISTING_PAGE = 'resources/js/Pages/Listings/Show.tsx';
const BUDGETS = {
    entry: 118_900, // 113.21 kB at 7.2, +5 %
    listing: 23_700, // 22.57 kB at 7.2, +5 %
};

const manifest = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'));

/** The chunk for a manifest key, plus every chunk it imports statically (not dynamic imports). */
function closure(key, into = new Set()) {
    const chunk = manifest[key];
    if (!chunk || into.has(key)) {
        return into;
    }
    into.add(key);
    (chunk.imports ?? []).forEach((child) => closure(child, into));

    return into;
}

const gzipped = (key) => gzipSync(readFileSync(`public/build/${manifest[key].file}`), { level: 9 }).length;
const sum = (keys) => [...keys].reduce((total, key) => total + gzipped(key), 0);

if (!manifest[ENTRY] || !manifest[LISTING_PAGE]) {
    console.error(`Expected ${ENTRY} and ${LISTING_PAGE} in public/build/manifest.json (run npm run build first).`);
    process.exit(1);
}

const entryChunks = closure(ENTRY);
const entry = sum(entryChunks);
const listingChunks = [...closure(LISTING_PAGE)].filter((key) => !entryChunks.has(key));
const listing = sum(listingChunks);

const kb = (bytes) => `${(bytes / 1000).toFixed(2)} kB`;
const rows = [
    ['Entry chunk (`app.js`)', entry, BUDGETS.entry, manifest[ENTRY].file],
    [`Listing page (+${listingChunks.length} chunks beyond the entry)`, listing, BUDGETS.listing, manifest[LISTING_PAGE].file],
];

let failed = false;
for (const [label, size, budget] of rows) {
    const ok = size <= budget;
    failed ||= !ok;
    console.log(`${label.replaceAll('`', '')}: ${kb(size)} gzipped, budget ${kb(budget)}: ${ok ? 'within budget' : 'OVER BUDGET'}`);
}

if (process.env.GITHUB_STEP_SUMMARY) {
    appendFileSync(
        process.env.GITHUB_STEP_SUMMARY,
        [
            '### JavaScript budget',
            '',
            '| Bundle | Gzipped | Budget | Result |',
            '| --- | ---: | ---: | --- |',
            ...rows.map(([label, size, budget]) => `| ${label} | **${kb(size)}** | ${kb(budget)} | ${size <= budget ? '✅ within budget' : '❌ over budget'} |`),
            '',
        ].join('\n'),
    );
}

if (failed) {
    console.error('::error file=docs/ARCHITECTURE.md::A JavaScript bundle is over its gzipped budget (docs/ARCHITECTURE.md, "Budgets"). Trim it, or raise the limit in bin/check-js-budget.mjs and say why in the PR.');
    process.exit(1);
}
