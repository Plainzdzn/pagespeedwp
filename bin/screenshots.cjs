/**
 * Screenshots aller Plugin-Seiten und der Mockups, für den Abgleich mit dem Design.
 *
 * Voraussetzung: bin/setup-env.sh --serve läuft, Playwright mit Chromium ist installiert.
 * Nutzung:       NODE_PATH="$(npm root -g)" node bin/screenshots.cjs [ausgabeordner]
 *
 * Umgebungsvariablen: AKWU_URL (Standard http://localhost:8080), AKWU_USER / AKWU_PASS (admin / admin).
 */

const path = require( 'path' );
const fs = require( 'fs' );
const { chromium } = require( 'playwright' );

const base = process.env.AKWU_URL || 'http://localhost:8080';
const outDir = path.resolve( process.argv[ 2 ] || 'screenshots' );
const designDir = path.resolve( __dirname, '..', 'design' );

const pages = [
	'akwu',
	'akwu-umwandlung',
	'akwu-bericht',
	'akwu-bilder',
	'akwu-einstellungen',
	'akwu-systempruefung',
	'akwu-rueckgaengig',
];

( async () => {
	fs.mkdirSync( outDir, { recursive: true } );

	const browser = await chromium.launch();
	const context = await browser.newContext( { viewport: { width: 1440, height: 1000 } } );
	const page = await context.newPage();
	const problems = [];

	page.on( 'console', ( msg ) => {
		// Nur WordPress-Seiten prüfen, die Mockups laden fehlende Hilfsdateien des Design-Tools.
		if ( msg.type() === 'error' && page.url().startsWith( base ) ) {
			problems.push( `${ page.url() }: ${ msg.text() }` );
		}
	} );
	page.on( 'response', ( res ) => {
		if ( res.status() >= 400 && res.url().startsWith( base ) ) {
			problems.push( `${ res.status() } ${ res.url() }` );
		}
	} );

	await page.goto( `${ base }/wp-login.php` );
	await page.fill( '#user_login', process.env.AKWU_USER || 'admin' );
	await page.fill( '#user_pass', process.env.AKWU_PASS || 'admin' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /\/wp-admin\// );

	for ( const slug of pages ) {
		await page.goto( `${ base }/wp-admin/admin.php?page=${ slug }` );
		await page.waitForLoadState( 'networkidle' );
		await page.evaluate( () => document.fonts.ready );
		const file = path.join( outDir, `${ slug }.png` );
		await page.screenshot( { path: file, fullPage: true } );
		const title = await page.title();
		console.log( `${ slug }: ${ title }` );
	}

	for ( const mockup of fs.readdirSync( designDir ).filter( ( f ) => f.endsWith( '.dc.html' ) ) ) {
		await page.goto( `file://${ path.join( designDir, mockup ) }` );
		await page.waitForTimeout( 500 );
		await page.screenshot( { path: path.join( outDir, `mockup-${ mockup.replace( '.dc.html', '' ) }.png` ), fullPage: true } );
	}

	await browser.close();

	if ( problems.length ) {
		console.log( '\nProbleme:' );
		problems.forEach( ( p ) => console.log( `- ${ p }` ) );
		process.exitCode = 1;
	} else {
		console.log( '\nKeine Konsolenfehler, keine fehlerhaften Antworten.' );
	}
} )();
