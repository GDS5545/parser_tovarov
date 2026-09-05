'use strict';

const { chromium } = require( 'playwright' );

/**
 * Lazily launches a single shared headless Chromium instance and hands out
 * fresh browser contexts per request (spec §33: locale/timezone/user-agent/
 * viewport are per-context, not global). Keeping one browser process alive
 * avoids paying Chromium's ~1s startup cost on every scrape.
 */
class BrowserPool {
	constructor() {
		this.browserPromise = null;
	}

	async getBrowser() {
		if ( ! this.browserPromise ) {
			this.browserPromise = chromium.launch( {
				headless: true,
				args: [ '--disable-dev-shm-usage', '--no-sandbox' ],
			} );
		}
		return this.browserPromise;
	}

	/**
	 * @param {object} options viewport, locale, timezone, user_agent, cookies
	 * @returns {Promise<import('playwright').BrowserContext>}
	 */
	async newContext( options = {} ) {
		const browser = await this.getBrowser();

		const context = await browser.newContext( {
			viewport: options.viewport || { width: 1366, height: 900 },
			locale: options.locale || 'en-US',
			timezoneId: options.timezone || 'UTC',
			userAgent:
				options.user_agent ||
				'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 UniversalWooScraper/0.1',
		} );

		if ( Array.isArray( options.cookies ) && options.cookies.length ) {
			await context.addCookies( options.cookies );
		}

		return context;
	}

	async close() {
		if ( this.browserPromise ) {
			const browser = await this.browserPromise;
			await browser.close();
			this.browserPromise = null;
		}
	}
}

module.exports = new BrowserPool();
