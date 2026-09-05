'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const { validateUrl } = require( '../src/ssrfGuard' );

test( 'accepts a normal https URL', () => {
	const result = validateUrl( 'https://example.com/product/1' );
	assert.equal( result.ok, true );
} );

test( 'rejects non-http(s) schemes', () => {
	const result = validateUrl( 'ftp://example.com/file' );
	assert.equal( result.ok, false );
} );

test( 'rejects credentials in URL', () => {
	const result = validateUrl( 'https://user:pass@example.com/' );
	assert.equal( result.ok, false );
} );

test( 'rejects loopback', () => {
	const result = validateUrl( 'http://127.0.0.1:9000/' );
	assert.equal( result.ok, false );
} );

test( 'rejects link-local metadata address', () => {
	const result = validateUrl( 'http://169.254.169.254/latest/meta-data/' );
	assert.equal( result.ok, false );
} );

test( 'rejects private 10.x range', () => {
	const result = validateUrl( 'http://10.1.2.3/' );
	assert.equal( result.ok, false );
} );

test( 'rejects private 172.16-31.x range but not 172.32', () => {
	assert.equal( validateUrl( 'http://172.20.0.5/' ).ok, false );
	assert.equal( validateUrl( 'http://172.32.0.5/' ).ok, true );
} );

test( 'rejects localhost hostname', () => {
	const result = validateUrl( 'http://localhost/' );
	assert.equal( result.ok, false );
} );

test( 'rejects malformed URL', () => {
	const result = validateUrl( 'not a url' );
	assert.equal( result.ok, false );
} );
