<?php

/**
 * @snippet       	Google Tag Manager Events for MemberPress
 * @author 			Jay Phillips
 * @description 	Add tracking events to MemberPress. Assumes tag manager 
 *					is already installed and the datalayer object is available.
 *
**/

function gtm4mepr_register_page() {
	global $post;
	
	// Check MemberPress plugin is active
	if ( !class_exists('MeprProduct') ) return;
	
	// Get all MemberPress post IDs
	$memberships = MeprProduct::get_all();
	foreach ($memberships as $membership) {
		$membership_ids[] = $membership->ID;
		// Get 'thank you' pages
		if ( $membership->thank_you_page_id ) {
			$thank_you_pages[$membership->ID] = $membership->thank_you_page_id;
		}
	}
	
	// Check we are on a MemberPress page
	if ( isset($post) && ( in_array($post->ID, $membership_ids) || ( in_array($post->ID, $thank_you_pages) ) ) ) {
	
		// Get datalayer object
		if ( $_GET['action'] == "checkout" ) {
			// for 'checkout' event
			$datalayer_obj = gtm4mepr_add_to_cart_object($post->ID);
		} elseif (in_array($post->ID, $membership_ids)) {
			// for 'product' (register form) page view
			$datalayer_obj = gtm4mepr_impression_object($post->ID);	
		} elseif (in_array($post->ID, $thank_you_pages)) {
			// for 'thank you' page (purchase event)
			$force_event_tracking_when_total_is_zero = true;
			// the same thank you page may be used by multiple memberships
			// BUT we can capture the membership ID from the query string params
			$membership_id = intval($_GET['membership_id']);
			$datalayer_obj = gtm4mepr_purchase_object( $membership_id , $force_event_tracking_when_total_is_zero );
		}
		
		if ( $datalayer_obj ) {
			// Output JavaScript
			echo "\n<!-- Google Tag Manager for MemberPress -->\n";
			echo "<script>\n\tdataLayer.push({ ecommerce: null });\n\t"; // Clear the previous ecommerce object.
			echo "dataLayer.push(" . json_encode( $datalayer_obj ) . ");\n";
			//echo "console.log(" . json_encode( $datalayer_obj ) . ");\n";
			echo "</script>\n<!-- End Google Tag Manager for MemberPress -->";
		}
  	}
}
add_action('wp_head', 'gtm4mepr_register_page', 11);

function gtm4mepr_impression_object($id){
	
	// Check MemberPress plugin is active
	if ( !class_exists('MeprProduct') ) return;
	
	// Get general MemberPress options
	$mepr_options 	= MeprOptions::fetch();
	
	// Attempt to get Membership object
	$membership		= new MeprProduct( $id );
	
	// Return empty if no Membership found
	if ( empty( $membership ) ) return;
	
	// Define vars
    $mepr_id		= $id;
	$mepr_name		= $membership->post_title;
	$mepr_curr 		= $mepr_options->currency_code;
	$mepr_price		= $membership->price;
	$mepr_brand		= get_bloginfo('name');
	$mepr_category 	= get_bloginfo('name') . " Membership";
	$mepr_variant	= ""; // string
	$mepr_list		= ""; // string
	$mepr_list_pos	= ""; // int
	
  	// Build the datalayer object for gtag impressions
	// https://developers.google.com/tag-manager/enhanced-ecommerce#product-impressions
	$datalayer_obj = array(
		"event"	=>	"meprImpression",
		"ecommerce" => array(
			"currencyCode"	=> $mepr_curr,
			"impressions"	=> array(
								array(
									"name"		=> $mepr_name,
									"id"		=> $mepr_id,
									"price"		=> $mepr_price,
									"brand" 	=> $mepr_brand,
									"category"	=> $mepr_category,

									// skip these for now...
									// "variant"	=> $mepr_variant,
									// "list"		=> $mepr_list,
									// "position"	=> $mepr_list_pos
								)
			)
		)
	);
	
	return $datalayer_obj;
}


function gtm4mepr_add_to_cart_object($id){
	
	// Check MemberPress plugin is active
	if ( !class_exists('MeprProduct') ) return;
	
	// Get general MemberPress options
	$mepr_options 	= MeprOptions::fetch();
	
	// Attempt to get Membership object
	$membership		= new MeprProduct( $id );
	
	// Return empty if no Membership found
	if ( empty( $membership ) ) return;
	
	// Define vars
    $mepr_id		= $id;
	$mepr_name		= $membership->post_title;
	$mepr_curr 		= $mepr_options->currency_code;
	$mepr_price		= $membership->price;
	$mepr_brand		= get_bloginfo('name');
	$mepr_category 	= get_bloginfo('name') . " Membership";
	$mepr_variant	= ""; // string
	$mepr_list		= ""; // string
	$mepr_list_pos	= ""; // int
	

  	// Build the datalayer object for gtag impressions
	// https://developers.google.com/tag-manager/enhanced-ecommerce#cart
	$datalayer_obj = array(
		"event"	=>	"meprAddToCart",
		"ecommerce" => array(
			"currencyCode"	=> $mepr_curr,
			"add"			=> array(
				"products"	=> array(
								array(
									"name"		=> $mepr_name,
									"id"		=> $mepr_id,
									"price"		=> $mepr_price,
									"brand" 	=> $mepr_brand,
									"category"	=> $mepr_category
								)
				)
			)
		)
	);
	
	return $datalayer_obj;
}

function gtm4mepr_purchase_object($id, $force_event_tracking_when_total_is_zero = false) {
	
	// Check MemberPress plugin is active, and we have a transaction string
	if ( !class_exists('MeprTransaction') || !$_GET['trans_num'] ) return;
	
	// Get Transaction data
	$txn = MeprTransaction::get_one_by_trans_num($_GET['trans_num']);
	
	// Only proceed if total > zero OR if total is zero and force tracking is enabled
	if ( $txn->total == 0 && $force_event_tracking_when_total_is_zero == false ){
		return;
	}
	
	// Define vars
	$mepr_trans_id	= $txn->trans_num;					// Transaction ID
    $mepr_brand		= get_bloginfo('name');				// Brand
	$mepr_revenue	= $txn->total;						// Transaction total
	$mepr_tax		= $txn->tax_amount;					// Tax on membership
	$mepr_shipping	= 0;								// no shipping on membership
	$mepr_coupon	= $txn->coupon_id ? get_the_title($txn->coupon_id) : ''; // coupon used (if any)
	
	// Attempt to get Membership object
	$membership		= new MeprProduct( $id );
	
	// Define more vars
	$mepr_name		= $membership->post_title;			// Membership name
	$mepr_id		= $id;								// Membership ID
	$mepr_price		= $txn->total;						// Price paid for membership
	$mepr_category 	= get_bloginfo('name') . " Membership";
	
	// Build the datalayer object for gtag impressions
	// https://developers.google.com/tag-manager/enhanced-ecommerce#purchases
	// (apparently there is no way to send recurring subscription / purchase data to google)
	$datalayer_obj = array(
		"event"	=>	"meprPurchase",
		"ecommerce" => array(
			"purchase"	=> array(
				"actionField" 	=> array(
					"id"			=> $mepr_trans_id,
					"affiliation"	=> $mepr_brand,
					"revenue"		=> $mepr_revenue,
					"tax"			=> $mepr_tax,
					"shipping"		=> $mepr_shipping,
					"coupon"		=> $mepr_coupon
				),
				"products"		=> array(
										array(
											"name"		=> $mepr_name,
											"id"		=> $mepr_id,
											"price"		=> $mepr_price,
											"brand"		=> $mepr_brand,
											"category"	=> $mepr_category,
											"quantity"	=> 1
										)
				)
			)
		)
	);
	
	return $datalayer_obj;
}
