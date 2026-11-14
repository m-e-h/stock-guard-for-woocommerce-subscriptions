import { ADMIN, expect, logIn, test } from '../fixtures';

const SECTION = 'sgfws_customer_subscription_renewal_delayed';
const FIELD_PREFIX = `#woocommerce_${ SECTION }_`;
const EMAIL_CLASS = 'StockGuardForWooCommerceSubscriptions\\Emails\\CustomerRenewalDelayed';

test.describe( 'renewal delayed email settings', () => {
	test.beforeEach( async ( { page } ) => {
		await logIn( page, ADMIN.username, ADMIN.password );
	} );

	test( 'is listed with the other WooCommerce emails', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=wc-settings&tab=email' );
		await expect( page.getByText( 'Subscription renewal delayed' ).first() ).toBeVisible();
	} );

	test( 'settings load and save', async ( { page } ) => {
		const settingsUrl = `/wp-admin/admin.php?page=wc-settings&tab=email&section=${ SECTION }`;
		const subject = `Renewal on hold ${ Date.now() }`;

		await page.goto( settingsUrl );
		await expect( page.locator( `${ FIELD_PREFIX }enabled` ) ).toBeChecked();
		await expect( page.locator( `${ FIELD_PREFIX }subject` ) ).toHaveAttribute( 'placeholder', /subscription renewal is delayed/ );

		await page.locator( `${ FIELD_PREFIX }subject` ).fill( subject );
		await page.locator( `${ FIELD_PREFIX }email_type` ).selectOption( 'plain' );
		await page.locator( '.woocommerce-save-button' ).click();

		await expect( page.locator( '#message, .notice-success, .updated' ).first() ).toContainText( 'Your settings have been saved' );

		await page.goto( settingsUrl );
		await expect( page.locator( `${ FIELD_PREFIX }subject` ) ).toHaveValue( subject );
		await expect( page.locator( `${ FIELD_PREFIX }email_type` ) ).toHaveValue( 'plain' );
	} );

	test( 'preview renders sample subscription data', async ( { page } ) => {
		await page.goto( `/wp-admin/admin.php?page=wc-settings&tab=email&section=${ SECTION }` );

		const previewUrl = await page.locator( '#wc_settings_email_preview_slotfill' ).getAttribute( 'data-preview-url' );
		expect( previewUrl, 'WooCommerce should expose an email preview URL.' ).toBeTruthy();

		const response = await page.goto( `${ previewUrl }&type=${ encodeURIComponent( EMAIL_CLASS ) }` );
		expect( response?.status() ).toBe( 200 );

		await expect( page.locator( 'body' ) ).toContainText( 'Hi John,' );
		await expect( page.locator( 'body' ) ).toContainText( 'subscription #12346' );
		await expect( page.locator( 'body' ) ).toContainText( 'Example subscription product' );
		await expect( page.locator( 'body' ) ).not.toContainText( 'Fatal error' );
	} );
} );
