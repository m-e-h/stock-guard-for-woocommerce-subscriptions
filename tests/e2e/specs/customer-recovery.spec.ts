import { expect, logIn, test } from '../fixtures';

test( 'customer sees the delayed subscription, then the recovered renewal', async ( { page, store } ) => {
	const scenario = await store.createDelayedSubscription( 'cust' );

	await logIn( page, scenario.customer.username, scenario.customer.password );

	const details = page.locator( 'table.subscription_details' );
	const statusCell = details.locator( 'tr', { hasText: 'Status' } ).locator( 'td' ).nth( 1 );
	const relatedOrders = page.locator( '#woocommerce-subscriptions-related-orders-table' );

	await page.goto( `/my-account/view-subscription/${ scenario.subscriptionId }/` );
	await expect( statusCell ).toHaveText( 'Delayed' );
	await expect( page.getByText( scenario.productName ).first() ).toBeVisible();
	// The fixture has no parent order and the blocked renewal created none.
	await expect( relatedOrders.locator( 'tbody tr' ) ).toHaveCount( 0 );

	const recovered = await store.recover( scenario );
	const [ renewal ] = recovered.renewalOrders;

	await page.reload();
	await expect( statusCell ).toHaveText( 'Active' );
	await expect( relatedOrders.locator( 'tbody tr' ) ).toHaveCount( 1 );
	await expect( relatedOrders.locator( 'tbody tr' ).first() ).toContainText( `#${ renewal.number }` );
	await expect( relatedOrders.locator( 'tbody tr' ).first() ).toContainText( 'Processing' );

	// The recovered renewal is also listed with the customer's orders.
	await page.goto( '/my-account/orders/' );
	await expect( page.locator( '.woocommerce-orders-table' ) ).toContainText( `#${ renewal.number }` );
} );
