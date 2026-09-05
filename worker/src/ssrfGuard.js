'use strict';

/**
 * Defense-in-depth SSRF guard. WordPress already runs every URL through
 * Uws\Security\UrlValidator (DNS-resolved, checked against private/loopback
 * ranges) before it ever reaches this worker, but the worker is a network
 * service in its own right — anyone who can reach it with a valid API key
 * should still not be able to use it to probe the worker's own local
 * network. This is a cheap, synchronous string/host check, not a full
 * DNS-resolution guard; it exists as a second layer, not the only one.
 */

const BLOCKED_HOSTNAMES = new Set(['localhost']);

const BLOCKED_V4_PREFIXES = ['127.', '169.254.', '0.', '10.', '192.168.'];

function isBlockedIpv4( host ) {
	if ( BLOCKED_V4_PREFIXES.some( ( prefix ) => host.startsWith( prefix ) ) ) {
		return true;
	}
	// 172.16.0.0 - 172.31.255.255
	const match = host.match( /^172\.(\d{1,3})\./ );
	if ( match ) {
		const second = parseInt( match[ 1 ], 10 );
		return second >= 16 && second <= 31;
	}
	return false;
}

function isBlockedHost( hostname ) {
	const host = hostname.toLowerCase().replace( /^\[|\]$/g, '' );

	if ( BLOCKED_HOSTNAMES.has( host ) ) {
		return true;
	}
	if ( host === '::1' || host.startsWith( 'fe80:' ) || host.startsWith( 'fc' ) || host.startsWith( 'fd' ) ) {
		return true;
	}
	if ( /^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/.test( host ) ) {
		return isBlockedIpv4( host );
	}
	return false;
}

/**
 * @param {string} rawUrl
 * @returns {{ok: true, url: URL}|{ok: false, reason: string}}
 */
function validateUrl( rawUrl ) {
	let parsed;
	try {
		parsed = new URL( rawUrl );
	} catch ( error ) {
		return { ok: false, reason: 'URL could not be parsed.' };
	}

	if ( ! [ 'http:', 'https:' ].includes( parsed.protocol ) ) {
		return { ok: false, reason: 'Only http/https URLs are allowed.' };
	}

	if ( parsed.username || parsed.password ) {
		return { ok: false, reason: 'URLs containing credentials are not allowed.' };
	}

	if ( isBlockedHost( parsed.hostname ) ) {
		return { ok: false, reason: 'This host is not allowed (local/private address).' };
	}

	return { ok: true, url: parsed };
}

module.exports = { validateUrl, isBlockedHost };
