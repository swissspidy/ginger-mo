#!/usr/bin/env node

import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import tablemark from 'tablemark';

process.env.WP_ARTIFACTS_PATH ??= join( process.cwd(), 'artifacts' );

const args = process.argv.slice( 2 );

const beforeFile = args[ 1 ];
const afterFile = args[ 0 ];

if ( ! existsSync( afterFile ) ) {
	console.error( `File not found: ${ afterFile }` );
	process.exit( 1 );
}

if ( beforeFile && ! existsSync( beforeFile ) ) {
	console.error( `File not found: ${ beforeFile }` );
	process.exit( 1 );
}

/**
 * Format test results as a Markdown table.
 *
 * @param {Array<Record<string,string|number|boolean>>} results Test results.
 *
 * @return {string} Markdown content.
 */
function formatAsMarkdownTable( results ) {
	if ( ! results?.length ) {
		return '';
	}

	function toCellText( v ) {
		if ( v === true || v === 'true' ) return '✅';
		if ( ! v || v === 'false' ) return '';
		return v?.toString() || String( v );
	}

	return tablemark( results, {
		// In v2 the option is still called stringify
		stringify: toCellText,
		caseHeaders: false,
		columns: [
			{ align: 'left' },
			{ align: 'center' },
			{ align: 'center' },
			{ align: 'center' },
			{ align: 'center' },
		],
	} );
}

/**
 * @type {Array<{file: string, title: string, results: Record<string,string|number|boolean>}>}
 */
let beforeStats = [];

/**
 * @type {Array<{file: string, title: string, results: Record<string,string|number|boolean>}>}
 */
let afterStats;

if ( beforeFile ) {
	try {
		beforeStats = JSON.parse(
			readFileSync( beforeFile, { encoding: 'UTF-8' } )
		);
	} catch {}
}

try {
	afterStats = JSON.parse( readFileSync( afterFile, { encoding: 'UTF-8' } ) );
} catch {
	console.error( `Could not read file: ${ afterFile }` );
	process.exit( 1 );
}

let summaryMarkdown = `**Performance Test Results**\n\n`;

if ( process.env.GITHUB_SHA ) {
	summaryMarkdown += `Performance test results for ${ process.env.GITHUB_SHA } are in 🛎️!\n\n`;
} else {
	summaryMarkdown += `Performance test results are in 🛎️!\n\n`;
}

if ( beforeFile ) {
	summaryMarkdown += `Note: the numbers in parentheses show the difference to the previous (baseline) test run.\n\n`;
}

console.log( 'Performance Test Results\n' );

if ( beforeFile ) {
	console.log(
		'Note: the numbers in parentheses show the difference to the previous (baseline) test run.\n'
	);
}

const DELTA_VARIANCE = 0.5;
const PERCENTAGE_VARIANCE = 2;

/**
 * Format value and add unit.
 *
 * Turns bytes into MB (base 10).
 *
 * @todo Dynamic formatting based on definition in result.json.
 *
 * @param {number} value Value.
 * @param {string} key   Key.
 * @return {string} Formatted value.
 */
function formatValue( value, key ) {
	if ( key === 'CLS' ) {
		return value.toFixed( 2 );
	}

	if ( key === 'wp-memory-usage' ) {
		return `${ ( value / Math.pow( 10, 6 ) ).toFixed( 2 ) } MB`;
	}

	return `${ value.toFixed( 2 ) } ms`;
}

for ( const { file, title, results } of afterStats ) {
	const prevStat = beforeStats.find( ( s ) => s.file === file );

	/**
	 * @type {Array<Record<string,string|number|boolean>>}
	 */
	const diffResults = [];

	for ( const i in results ) {
		const newResult = results[ i ];
		// Only do comparison if the number of results is the same.
		// TODO: what if order of results has changed?
		const prevResult =
			prevStat?.results.length === results.length
				? prevStat?.results[ i ]
				: null;

		const diffResult = {};

		for ( const [ key, value ] of Object.entries( newResult ) ) {
			// Do not diff anything that is not a number.
			if ( ! Number.isFinite( value ) ) {
				diffResult[ key ] = value;
				continue;
			}

			const prevValue = prevResult?.[ key ] || 0;
			const delta = value - prevValue;
			const percentage = Math.round( ( delta / value ) * 100 );

			// Skip if there is not a significant delta or none at all.
			if (
				! prevResult?.[ key ] ||
				! percentage ||
				Math.abs( percentage ) <= PERCENTAGE_VARIANCE ||
				! delta ||
				Math.abs( delta ) <= DELTA_VARIANCE
			) {
				diffResult[ key ] = formatValue( value, key );
				continue;
			}

			const prefix = delta > 0 ? '+' : '';

			diffResult[ key ] = `${ formatValue(
				value,
				key
			) } (${ prefix }${ formatValue(
				delta,
				key
			) } / ${ prefix }${ percentage }%)`;
		}

		diffResults.push( diffResult );
	}

	console.log( 'Results for:', title );
	console.table( diffResults );

	summaryMarkdown += `**${ title }**\n\n`;
	summaryMarkdown += `${ formatAsMarkdownTable( diffResults ) }\n`;
}

writeFileSync(
	join( process.env.WP_ARTIFACTS_PATH, '/performance-results.md' ),
	summaryMarkdown
);
