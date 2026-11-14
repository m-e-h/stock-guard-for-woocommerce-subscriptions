import { ADMIN, expect, logIn, test } from '../fixtures';

test( 'store manager sees the delay and the recovered renewal in admin', async ( { page, store } ) => {
	const scenario = await store.createDelayedSubscription( 'admin' );
	const delayed = await store.state( scenario.subscriptionId );

	await logIn( page, ADMIN.username, ADMIN.password );

	await page.goto( delayed.editUrl );
	await expect( page.locator( '#order_status' ) ).toHaveValue( 'wc-delayed' );
	await expect( page.locator( '.order_notes' ) ).toContainText( 'Renewal paused before order creation' );
	await expect( page.locator( '.order_notes' ) ).toContainText( scenario.productName );

	const recovered = await store.recover( scenario );
	const [ renewal ] = recovered.renewalOrders;

	await page.reload();
	await expect( page.locator( '#order_status' ) ).toHaveValue( 'wc-active' );
	await expect( page.locator( '.order_notes' ) ).toContainText( `Renewal order #${ renewal.number } was created automatically` );

	const relatedOrders = page.locator( '#subscription_renewal_orders' );
	await expect( relatedOrders ).toContainText( `#${ renewal.number }` );
	await expect( relatedOrders ).toContainText( 'Renewal Order' );

	await page.goto( renewal.editUrl );
	await expect( page.locator( '#order_status' ) ).toHaveValue( 'wc-processing' );
	await expect( page.locator( '#order_line_items' ) ).toContainText( scenario.productName );
} );
