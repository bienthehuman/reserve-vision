// The Lighthouse Performance bar (0-100), mobile, asserted for every page in lighthouserc.cjs.
// Set from a measurement, not a wish: the lowest page after the 7.2 fixes is the last-bed listing at 90-91
// over four sets of 3 runs, so the bar is 90 (rounded down to a multiple of 5). docs/perf/lighthouse.md has the numbers.
module.exports = { performance: 90 };
