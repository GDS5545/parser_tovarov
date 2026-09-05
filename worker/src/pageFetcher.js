'use strict';

const browserPool = require( './browserPool' );

// Text patterns matched case-insensitively against clickable elements to
// dismiss the most common cookie/newsletter/age-gate popups (spec §35).
// Best-effort only, and always time-boxed so a popup can never hang a scrape.
const POPUP_CLICK_PATTERNS = [
	'accept all',
	'accept cookies',
	'accept',
	'i agree',
	'agree',
	'got it',
	'allow all',
	'close',
];

async function dismissPopups( page ) {
	for ( const text of POPUP_CLICK_PATTERNS ) {
		try {
			const locator = page.getByText( text, { exact: false } ).first();
			await locator.click( { timeout: 800 } );
			await page.waitForTimeout( 150 );
		} catch ( error ) {
			// No matching element, or it wasn't clickable in time — that's fine.
		}
	}
}

/**
 * @param {import('playwright').Page} page
 * @returns {Promise<string[]>} Raw textContent of every JSON-LD <script> block.
 */
async function extractJsonLdBlocks( page ) {
	try {
		return await page.$$eval( 'script[type="application/ld+json"]', ( nodes ) =>
			nodes.map( ( node ) => node.textContent || '' )
		);
	} catch ( error ) {
		return [];
	}
}

/**
 * @param {string} url Already SSRF-validated by both PHP and ssrfGuard.
 * @param {object} options wait_until, extra_delay_ms, viewport, locale,
 *                         timezone, user_agent, cookies, close_popups, screenshot.
 * @returns {Promise<object>}
 */
async function fetchPage( url, options = {} ) {
	const context = await browserPool.newContext( options );
	const page = await context.newPage();

	const consoleErrors = [];
	const networkErrors = [];

	page.on( 'console', ( message ) => {
		if ( message.type() === 'error' ) {
			consoleErrors.push( message.text().slice( 0, 500 ) );
		}
	} );
	page.on( 'pageerror', ( error ) => {
		consoleErrors.push( String( error ).slice( 0, 500 ) );
	} );
	page.on( 'requestfailed', ( request ) => {
		networkErrors.push( {
			url: request.url(),
			failure: request.failure() ? request.failure().errorText : 'unknown',
		} );
	} );

	let response;
	try {
		response = await page.goto( url, {
			waitUntil: options.wait_until || 'networkidle',
			timeout: 30000,
		} );
	} catch ( error ) {
		await context.close();
		throw new Error( `Navigation failed: ${ error.message }` );
	}

	if ( options.extra_delay_ms ) {
		await page.waitForTimeout( Math.min( 10000, Number( options.extra_delay_ms ) || 0 ) );
	}

	if ( options.close_popups !== false ) {
		await dismissPopups( page );
	}

	const html = await page.content();
	const jsonLdBlocks = await extractJsonLdBlocks( page );
	const finalUrl = page.url();
	const statusCode = response ? response.status() : null;

	let screenshotBase64 = null;
	if ( options.screenshot ) {
		const buffer = await page.screenshot( { fullPage: true } );
		screenshotBase64 = buffer.toString( 'base64' );
	}

	await context.close();

	return {
		html,
		final_url: finalUrl,
		status_code: statusCode,
		json_ld_blocks: jsonLdBlocks,
		console_errors: consoleErrors.slice( 0, 50 ),
		network_errors: networkErrors.slice( 0, 50 ),
		screenshot_base64: screenshotBase64,
	};
}

// Utility/navigation link keywords excluded from listing-page discovery —
// these are almost never product detail pages on a conventional store.
const EXCLUDED_PATH_KEYWORDS = [
	'/cart',
	'/checkout',
	'/login',
	'/account',
	'/wishlist',
	'/compare',
	'/search',
	'/contact',
	'/about',
	'/blog',
	'/wp-content',
	'/wp-admin',
	'#',
];

/**
 * Discovers candidate product URLs from a category/listing page, following
 * simple pagination or clicking a "Load more" control (spec §19–20).
 *
 * @param {string} url
 * @param {object} options max_pages, pagination_strategy ('query'|'path'|'load_more'|'infinite_scroll'|'none').
 * @returns {Promise<string[]>}
 */
async function discoverProductUrls( url, options = {} ) {
	const maxPages = Math.max( 1, Math.min( 50, Number( options.max_pages ) || 1 ) );
	const strategy = options.pagination_strategy || 'none';

	const context = await browserPool.newContext( options );
	const page = await context.newPage();
	const found = new Set();

	try {
		await page.goto( url, { waitUntil: 'networkidle', timeout: 30000 } );

		for ( let current = 1; current <= maxPages; current++ ) {
			const links = await page.$$eval( 'a[href]', ( anchors ) => anchors.map( ( a ) => a.href ) );
			for ( const link of links ) {
				if ( isLikelyProductLink( link, url ) ) {
					found.add( link );
				}
			}

			if ( current === maxPages ) {
				break;
			}

			const advanced = await advancePage( page, strategy, current + 1, url );
			if ( ! advanced ) {
				break;
			}
		}
	} finally {
		await context.close();
	}

	return Array.from( found );
}

/**
 * @param {string} link
 * @param {string} baseUrl
 * @returns {boolean}
 */
function isLikelyProductLink( link, baseUrl ) {
	try {
		const parsed = new URL( link );
		const base = new URL( baseUrl );
		if ( parsed.hostname !== base.hostname ) {
			return false;
		}
		const path = parsed.pathname.toLowerCase();
		if ( EXCLUDED_PATH_KEYWORDS.some( ( keyword ) => path.includes( keyword ) ) ) {
			return false;
		}
		// Require at least one path segment beyond root — filters out the
		// homepage and bare category root links.
		return path.split( '/' ).filter( Boolean ).length >= 1 && path !== base.pathname;
	} catch ( error ) {
		return false;
	}
}

/**
 * @param {import('playwright').Page} page
 * @param {string} strategy
 * @param {number} nextPageNumber
 * @param {string} baseUrl
 * @returns {Promise<boolean>} Whether a next page was loaded.
 */
async function advancePage( page, strategy, nextPageNumber, baseUrl ) {
	if ( 'load_more' === strategy || 'infinite_scroll' === strategy ) {
		try {
			const button = page.getByText( /load more|show more|показать ещё|показать еще/i ).first();
			await button.click( { timeout: 2000 } );
			await page.waitForLoadState( 'networkidle', { timeout: 10000 } );
			return true;
		} catch ( error ) {
			if ( 'infinite_scroll' === strategy ) {
				const previousHeight = await page.evaluate( () => document.body.scrollHeight );
				await page.evaluate( () => window.scrollTo( 0, document.body.scrollHeight ) );
				await page.waitForTimeout( 1000 );
				const newHeight = await page.evaluate( () => document.body.scrollHeight );
				return newHeight > previousHeight;
			}
			return false;
		}
	}

	if ( 'query' === strategy || 'path' === strategy ) {
		const base = new URL( baseUrl );
		let nextUrl;
		if ( 'query' === strategy ) {
			base.searchParams.set( 'page', String( nextPageNumber ) );
			nextUrl = base.toString();
		} else {
			nextUrl = base.origin + base.pathname.replace( /\/$/, '' ) + `/page/${ nextPageNumber }/`;
		}
		try {
			const response = await page.goto( nextUrl, { waitUntil: 'networkidle', timeout: 20000 } );
			return !! response && response.ok();
		} catch ( error ) {
			return false;
		}
	}

	return false;
}

module.exports = { fetchPage, discoverProductUrls };
