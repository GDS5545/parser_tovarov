'use strict';

const express = require( 'express' );
const { validateUrl } = require( './ssrfGuard' );
const { fetchPage, discoverProductUrls } = require( './pageFetcher' );

const PORT = Number( process.env.PORT ) || 4790;
const API_KEY = process.env.WORKER_API_KEY || '';

const app = express();
app.use( express.json( { limit: '1mb' } ) );

// Shared-secret auth: WordPress sends the same key configured in Browser
// Settings as the X-UWS-Api-Key header. If WORKER_API_KEY is unset the
// worker refuses every request rather than silently running open — a
// worker with browser + outbound-network access is not something to
// expose unauthenticated (spec §39).
app.use( ( request, response, next ) => {
	if ( request.path === '/health' ) {
		return next();
	}
	if ( ! API_KEY ) {
		return response.status( 503 ).json( { error: 'Worker has no WORKER_API_KEY configured; refusing all requests.' } );
	}
	if ( request.get( 'X-UWS-Api-Key' ) !== API_KEY ) {
		return response.status( 401 ).json( { error: 'Missing or invalid API key.' } );
	}
	return next();
} );

app.get( '/health', ( request, response ) => {
	response.json( { status: 'ok', configured: Boolean( API_KEY ) } );
} );

app.post( '/fetch', async ( request, response ) => {
	const { url, options } = request.body || {};

	const check = validateUrl( url || '' );
	if ( ! check.ok ) {
		return response.status( 400 ).json( { error: check.reason } );
	}

	try {
		const page = await fetchPage( check.url.toString(), options || {} );
		return response.json( page );
	} catch ( error ) {
		return response.status( 502 ).json( { error: error.message } );
	}
} );

app.post( '/discover', async ( request, response ) => {
	const { url, options } = request.body || {};

	const check = validateUrl( url || '' );
	if ( ! check.ok ) {
		return response.status( 400 ).json( { error: check.reason } );
	}

	try {
		const urls = await discoverProductUrls( check.url.toString(), options || {} );
		return response.json( { urls } );
	} catch ( error ) {
		return response.status( 502 ).json( { error: error.message } );
	}
} );

app.use( ( error, request, response, next ) => { // eslint-disable-line no-unused-vars
	response.status( 500 ).json( { error: 'Internal worker error.' } );
} );

app.listen( PORT, () => {
	console.log( `Universal Woo Scraper worker listening on port ${ PORT } (auth ${ API_KEY ? 'enabled' : 'DISABLED — set WORKER_API_KEY' })` );
} );
