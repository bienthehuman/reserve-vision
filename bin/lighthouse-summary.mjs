#!/usr/bin/env node
// Reads the Lighthouse reports lhci wrote (.lighthouseci/reports/lhr-*.json), picks the MEDIAN run of
// each URL (by performance score, the way lhci's `median-run` does) and prints a Markdown table:
// the four category scores, LCP, CLS, TBT and the transfer size. On GitHub Actions it is also
// appended to the job summary.
//
//   node bin/lighthouse-summary.mjs [reports-dir]
import { appendFileSync, readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

const dir = process.argv[2] ?? '.lighthouseci';
const runs = readdirSync(dir)
    .filter((f) => /^lhr-.*\.json$/.test(f))
    .map((f) => JSON.parse(readFileSync(join(dir, f), 'utf8')));

if (runs.length === 0) {
    console.error(`No lhr-*.json in ${dir}.`);
    process.exit(1);
}

const byUrl = new Map();
for (const lhr of runs) {
    const key = new URL(lhr.finalUrl).pathname + new URL(lhr.finalUrl).search;
    byUrl.set(key, [...(byUrl.get(key) ?? []), lhr]);
}

const score = (lhr, id) => Math.round((lhr.categories[id]?.score ?? 0) * 100);
const kb = (bytes) => `${(bytes / 1000).toFixed(0)} kB`;
const rows = [];

for (const [path, list] of byUrl) {
    const sorted = [...list].sort((a, b) => score(a, 'performance') - score(b, 'performance') || a.audits['largest-contentful-paint'].numericValue - b.audits['largest-contentful-paint'].numericValue);
    const m = sorted[Math.floor(sorted.length / 2)];
    const a = m.audits;
    rows.push(
        `| \`${path}\` | ${list.length} | ${score(m, 'performance')} | ${score(m, 'accessibility')} | ${score(m, 'best-practices')} | ${score(m, 'seo')} | ${(a['largest-contentful-paint'].numericValue / 1000).toFixed(2)} s | ${a['cumulative-layout-shift'].numericValue.toFixed(3)} | ${Math.round(a['total-blocking-time'].numericValue)} ms | ${kb(a['total-byte-weight'].numericValue)} |`,
    );
}

const out = [
    '### Lighthouse (mobile, simulated slow 4G, production stack, median of runs)',
    '',
    '| Page | Runs | Perf | A11y | Best practices | SEO | LCP | CLS | TBT | Transfer |',
    '| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |',
    ...rows,
    '',
    `Lighthouse ${runs[0].lighthouseVersion}. Skipped on purpose (plain HTTP on localhost in CI): \`is-on-https\`, \`redirects-http\`. \`/login\` is \`noindex\`, so its SEO score is not asserted.`,
    '',
].join('\n');

console.log(out);
if (process.env.GITHUB_STEP_SUMMARY) {
    appendFileSync(process.env.GITHUB_STEP_SUMMARY, `${out}\n`);
}
