#!/usr/bin/env node
/**
 * Start a disposable WordPress Playground site for the Stock Guard E2E suite.
 *
 * WooCommerce Subscriptions is a commercial extension and cannot be downloaded
 * by the blueprint, so the suite mounts local copies. By default it uses the
 * plugins next to this one; set SGFWS_E2E_PLUGINS_DIR to point elsewhere.
 */
import { spawn } from 'node:child_process';
import { existsSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const pluginDir = resolve( here, '../../..' );
const pluginsDir = resolve( process.env.SGFWS_E2E_PLUGINS_DIR || resolve( pluginDir, '..' ) );
const port = process.env.SGFWS_E2E_PORT || '9400';

const mounts = {
	woocommerce: resolve( pluginsDir, 'woocommerce' ),
	'woocommerce-subscriptions': resolve( pluginsDir, 'woocommerce-subscriptions' ),
	'stock-guard-for-woocommerce-subscriptions': pluginDir,
};

for ( const [ slug, path ] of Object.entries( mounts ) ) {
	if ( ! existsSync( path ) ) {
		console.error( `Missing ${ slug } at ${ path }. Set SGFWS_E2E_PLUGINS_DIR to a directory containing WooCommerce and WooCommerce Subscriptions.` );
		process.exit( 1 );
	}
}

// PHP errors from the Playground site are written here (WP_DEBUG_LOG in blueprint.json).
const logDir = resolve( pluginDir, '.cache/e2e-logs' );
mkdirSync( logDir, { recursive: true } );

const args = [
	'server',
	`--port=${ port }`,
	'--php=8.2',
	`--blueprint=${ resolve( here, '../blueprint.json' ) }`,
	...Object.entries( mounts ).map( ( [ slug, path ] ) => `--mount-before-install=${ path }:/wordpress/wp-content/plugins/${ slug }` ),
	`--mount-before-install=${ resolve( here, '../fixtures' ) }:/wordpress/wp-content/mu-plugins`,
	`--mount-before-install=${ logDir }:/wordpress/wp-content/sgfws-e2e-logs`,
];

const child = spawn( resolve( pluginDir, 'node_modules/.bin/wp-playground-cli' ), args, { stdio: 'inherit' } );

for ( const signal of [ 'SIGINT', 'SIGTERM' ] ) {
	process.on( signal, () => child.kill( signal ) );
}

child.on( 'exit', ( code ) => process.exit( code ?? 0 ) );
