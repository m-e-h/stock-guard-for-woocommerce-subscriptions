import { test as base, expect, type APIRequestContext, type Page } from '@playwright/test';

/**
 * Must match SGFWS_E2E_KEY in blueprint.json.
 */
const FIXTURE_KEY = 'sgfws-e2e-local-key';

export const ADMIN = { username: 'admin', password: 'password' };

export type Scenario = {
	customer: { username: string; password: string };
	productId: number;
	productName: string;
	subscriptionId: number;
};

export type RenewalOrder = { id: number; number: string; status: string; editUrl: string };

export type SubscriptionState = {
	status: string;
	number: string;
	editUrl: string;
	renewalOrders: RenewalOrder[];
};

/**
 * Deterministic scenario controls backed by tests/e2e/fixtures/sgfws-e2e-fixtures.php.
 */
export class StoreFixtures {
	constructor( private readonly request: APIRequestContext ) {}

	private async call< T >( method: 'GET' | 'POST', route: string, params: Record< string, string | number > = {} ): Promise< T > {
		const query = new URLSearchParams( { rest_route: `/sgfws-e2e/v1${ route }` } );

		for ( const [ key, value ] of Object.entries( params ) ) {
			query.set( key, String( value ) );
		}

		const response = await this.request.fetch( `/?${ query.toString() }`, {
			method,
			headers: { 'X-SGFWS-E2E-Key': FIXTURE_KEY },
		} );

		expect( response.ok(), `${ method } ${ route } failed: ${ await response.text() }` ).toBeTruthy();

		return ( await response.json() ) as T;
	}

	/** Create a customer with an active subscription to an out-of-stock product. */
	createScenario( label: string ): Promise< Scenario > {
		return this.call( 'POST', '/scenario', { label: `${ label }${ Date.now().toString( 36 ) }` } );
	}

	/** Fire the scheduled renewal, as Action Scheduler would. */
	renew( subscriptionId: number ): Promise< SubscriptionState > {
		return this.call( 'POST', `/subscriptions/${ subscriptionId }/renew` );
	}

	/** Set managed stock through WooCommerce so Stock Guard's stock hooks run. */
	setStock( productId: number, quantity: number ): Promise< { stock: number } > {
		return this.call( 'POST', `/products/${ productId }/stock`, { quantity } );
	}

	/** Run queued Stock Guard recovery jobs now. */
	runRecovery(): Promise< { ran: number } > {
		return this.call( 'POST', '/recovery/run' );
	}

	state( subscriptionId: number ): Promise< SubscriptionState > {
		return this.call( 'GET', `/subscriptions/${ subscriptionId }` );
	}

	/** Create a scenario whose renewal has already been delayed by Stock Guard. */
	async createDelayedSubscription( label: string ): Promise< Scenario > {
		const scenario = await this.createScenario( label );
		const state = await this.renew( scenario.subscriptionId );

		expect( state.status ).toBe( 'delayed' );
		expect( state.renewalOrders ).toHaveLength( 0 );

		return scenario;
	}

	/** Restock and run recovery, asserting a single renewal order results. */
	async recover( scenario: Scenario ): Promise< SubscriptionState > {
		await this.setStock( scenario.productId, 5 );
		expect( ( await this.runRecovery() ).ran ).toBeGreaterThanOrEqual( 1 );

		const state = await this.state( scenario.subscriptionId );

		expect( state.renewalOrders ).toHaveLength( 1 );

		return state;
	}
}

export async function logIn( page: Page, username: string, password: string ): Promise< void > {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( username );
	await page.locator( '#user_pass' ).fill( password );
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL( ( url ) => ! url.pathname.endsWith( '/wp-login.php' ) );
}

export const test = base.extend< { store: StoreFixtures } >( {
	store: async ( { request }, use ) => {
		await use( new StoreFixtures( request ) );
	},
} );

export { expect };
