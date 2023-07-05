import {
	test as base,
	Admin,
	RequestUtils,
} from '@wordpress/e2e-test-utils-playwright';
import { Page } from '@playwright/test';
import { chromium, type Browser } from 'playwright';
import type { Result } from 'lighthouse';
import getPort from 'get-port';

class SettingsPage {
	admin: Admin;
	page: Page;

	constructor( { admin, page }: { admin: Admin; page: Page } ) {
		this.admin = admin;
		this.page = page;
	}

	async setLocale( locale ) {
		await this.admin.visitAdminPage( 'options-general.php', '' );

		// en_US has an empty value in the language dropdown.
		await this.page
			.locator( 'id=WPLANG' )
			.selectOption( locale === 'en_US' ? '' : locale );

		await this.page.locator( 'id=submit' ).click();
	}
}

class WpPerformancePack {
	admin: Admin;
	page: Page;

	constructor( { admin, page }: { admin: Admin; page: Page } ) {
		this.admin = admin;
		this.page = page;
	}

	async enableL10n() {
		await this.admin.visitAdminPage(
			'options-general.php',
			'page=wppp_options_page'
		);

		await this.page.locator( 'id=mod_l10n_improvements_id' ).click();

		await this.page.locator( 'id=submit' ).click();

		await this.admin.visitAdminPage(
			'options-general.php',
			'page=wppp_options_page&tab=l10n_improvements'
		);

		await this.page.locator( 'id=use_mo_dynamic_id' ).click();
		await this.page.locator( 'id=mo-caching' ).click();

		await this.page.locator( 'id=submit' ).click();
	}
}

class Metrics {
	constructor( public readonly page: Page, public readonly port: number ) {
		this.page = page;
		this.port = port;
	}

	async getServerTiming() {
		return this.page.evaluate< Record< string, number > >( () =>
			(
				performance.getEntriesByType(
					'navigation'
				) as PerformanceNavigationTiming[]
			 )[ 0 ].serverTiming.reduce( ( acc, entry ) => {
				acc[ entry.name ] = entry.duration;
				return acc;
			}, {} )
		);
	}

	async getLighthouseReport() {
		const lighthouse = await import( 'lighthouse/core/index.cjs' );
		// @ts-ignore
		const { lhr } = await lighthouse.default(
			this.page.url(),
			{ port: this.port },
			undefined
		);

		const LCP = ( lhr as Result ).audits[ 'largest-contentful-paint' ]
			.numericValue;
		const TBT = ( lhr as Result ).audits[ 'total-blocking-time' ]
			.numericValue;
		const TTI = ( lhr as Result ).audits.interactive.numericValue;
		const CLS = ( lhr as Result ).audits[ 'cumulative-layout-shift' ]
			.numericValue;

		return {
			LCP,
			TBT,
			TTI,
			CLS,
		};
	}
}

type PerformanceFixtures = {
	settingsPage: SettingsPage;
	metrics: Metrics;
	wpPerformancePack: WpPerformancePack;
};

export const test = base.extend<
	PerformanceFixtures,
	{ port: number; browser: Browser }
>( {
	settingsPage: async ( { admin, page }, use ) => {
		await use( new SettingsPage( { admin, page } ) );
	},
	wpPerformancePack: async ( { admin, page }, use ) => {
		await use( new WpPerformancePack( { admin, page } ) );
	},
	port: [
		async ( {}, use ) => {
			const port = await getPort();
			await use( port );
		},
		{ scope: 'worker' },
	],
	browser: [
		async ( { port }, use ) => {
			const browser = await chromium.launch( {
				args: [ `--remote-debugging-port=${ port }` ],
			} );
			await use( browser );
		},
		{ scope: 'worker' },
	],
	metrics: async ( { page, port }, use ) => {
		await use( new Metrics( page, port ) );
	},
	// Override requestUtils from @wordpress/e2e-test-utils-playwright
	// to not trash all posts initially.
	requestUtils: [
		async ( {}, use, workerInfo ) => {
			const requestUtils = await RequestUtils.setup( {
				baseURL: workerInfo.project.use.baseURL,
				storageStatePath: process.env.STORAGE_STATE_PATH,
			} );

			await use( requestUtils );
		},
		// @ts-ignore -- False positive, see https://playwright.dev/docs/test-fixtures#automatic-fixtures
		{ scope: 'worker', auto: true },
	],
} );

export { expect } from '@wordpress/e2e-test-utils-playwright';
