<?php
/*
Plugin Name: CiviMember Role Synchronize
Depends: CiviCRM
Plugin URI: https://github.com/jeevajoy/Wordpress-CiviCRM-Member-Role-Sync/
Description: Plugin for CiviCRM Member Check
Author: Jag Kandasamy, Playgen
Version: 2.0.0alpha
Author URI: http:// www.orangecreative.net
*/

define( 'CIVISYNC_USER_ROLE', 'civi_sync' );

function civisync_setup_db()
{
	global $wpdb;
	$table_name = "{$wpdb->prefix}civi_member_sync";

	$sql = "CREATE TABLE IF NOT EXISTS $table_name (
		`id` int(11) NOT NULL AUTO_INCREMENT,
		`civi_mem_type` int(11) NOT NULL,
		`wp_role` varchar(255) NOT NULL,
		`expire_wp_role` varchar(255) NOT NULL,
		`current_rule` varchar(255) NOT NULL,
		PRIMARY KEY (`id`),
		UNIQUE KEY `civi_mem_type` (`civi_mem_type`)
		) DEFAULT CHARSET=utf8";

	$wpdb->query( $sql );
}
function civisync_register_roles()
{
	add_role( 'civi_sync', 'Civi Sync User' );
}
register_activation_hook( __FILE__, function()
{
	civisync_setup_db();
	civisync_register_roles();
} );

function civisync_wp_login( /* string */ $user_login, WP_User $user )
{
	if ( ! is_plugin_active( "civicrm/civicrm.php" ) )
		return;
	civicrm_initialize(); // In case it's not already
	civisync_perform_sync( $user );
}
add_action( 'wp_login', 'civisync_wp_login', 10, 2 );

/**
 * Syncs a wordpress user's roles with their memberships.
 * This only works if the user's primary role is the civisync role.
 * Warning: This will override any other roles assigned to it.
 * Note: CiviCRM must be active when this function is called.
 * @param WP_User $user
 * @throws CiviCRM_API3_Exception
 */
function civisync_perform_sync( WP_User $user )
{
	if ( ! in_array( CIVISYNC_USER_ROLE, $user->roles ) )
		return;
	$match = civicrm_api3( "UFMatch", "get", array(
		'sequential' => true,
		'uf_id' => $user->ID
	) );
	if ( 0 == $match['count'] )
		return; // hmm
	$match = reset( $match['values'] );
	$contact_id = $match['contact_id'];

	$membershibs = civicrm_api3( "Membership", "get", array(
		'sequential' => true,
		'contact_id' => $contact_id
	) );

	$roles = array();

	global $wpdb;
	$query = "SELECT * FROM `{$wpdb->prefix}civi_member_sync` WHERE `civi_mem_type`=%s LIMIT 1";
	foreach( (array) $membershibs['values'] as $membershibe ) { // wow
		$type   = $membershibe['membership_type_id'];
		$status = $membershibe['status_id'];
		$res = $wpdb->get_row( $wpdb->prepare( $query, $type ) );
		if ( ! $res )
			continue;
		$current_rule = unserialize( $res->current_rule );
		if ( ! isset( $roles[ $type ] ) )
			$roles[ $type ] = array();
		if ( in_array( $status, $current_rule ) ) {
			$roles[ $type ]['active'] = $res->wp_role;
		} elseif ( $res->expire_wp_role ) {
			$roles[ $type ]['inactive'] = $res->expire_wp_role;
		}
	}

	$civi_roles = array( CIVISYNC_USER_ROLE );
	foreach( $roles as $deets ) {
		if ( isset( $deets['active'] ) ) {
			$civi_roles[] = $deets['active'];
		} elseif ( ! empty( $deets['inactive'] ) ) {
			$civi_roles[] = $deets['inactive'];
		}
	}

	$user_roles = (array) $user->roles;
	$to_remove  = array_diff( $user_roles, $civi_roles );
	$to_add     = array_diff( $civi_roles, $user_roles );

	// Both remove role and add role call update_user_meta every call. Jazzhands!
	foreach( $to_remove as $role_name )
		$user->remove_role( $role_name );
	foreach( $to_add as $role_name )
		$user->add_role( $role_name );
}

function civisync_manual_sync()
{
	$errors = array();
	foreach( (array) get_users() as $user ) {
		try {
			civisync_perform_sync( $user );
		} catch( CiviCRM_API3_Exception $e ) {
			$errors[ $user->display_name ] = $e->getErrorMessage();
		}
	}
	add_action( 'admin_notices', function() use( $errors )
	{
?>
<div class="updated">
	<p>Manual Synchronisation completed
<?php if ( count( $errors ) > 0 ): ?>
	with <?= count( $errors ) ?> errors.
<?php endif; ?>
	</p>
</div>
<?php foreach( $errors as $user => $message ): ?>
<div class="error">
	<p><strong><?= $user ?></strong>: <?= $message ?></p>
</div>
<?php endforeach;
	} );
}

function _civisync_get_name( array $arr ) {
	return $arr['name'];
}
function _civisync_get_the_thing( $name )
{
	try {
		civicrm_initialize();
		$things = civicrm_api3( $name, "get" );
		return array_map( '_civisync_get_name', $things['values'] );
	} catch ( CiviCRM_API3_Exception $e ) {
		CRM_Core_Error::handleUnhandledException( $e );
	}
}

function civisync_rule_message( $action, $error = false )
{
	add_action( 'admin_notices', function() use( $action, $error )
	{
		if ( $error ):
?>
<div class="error">
	<p><strong>Unable to <?= $action; ?> rule</strong>: <?= $error; ?></p>
</div>
<?php else: ?>
<div class="updated">
	<p>Rule <?= $action; ?>d</p>
</div>
<?php
		endif;
	} );
}

function _civisync_param_require( $name, $optional = false )
{
	if ( empty( $_REQUEST[ $name ] ) ) {
		if ( $optional && isset( $_REQUEST[ $name ] ) )
			return '';
		wp_die( "Missing parameter '$name'!", "Missing parameter" );
	}
	return $_REQUEST[ $name ];
}

function _civisync_get_req_data()
{
	$params = array(
		'civi_mem_type'  => _civisync_param_require( 'civi_mem_type' ),
		'wp_role'        => _civisync_param_require( 'wp_role' ),
		'expire_wp_role' => _civisync_param_require( 'expire_wp_role', true ),
		'current_rule'   => _civisync_param_require( 'activation_rules' ),
	);
	if ( ! is_array( $params['current_rule'] ) )
		wp_die( "Parameter 'activation_rules' is supposed to be an array!" );
	$params['current_rule'] = serialize( $params['current_rule'] );
	return $params;
}

function civisync_rule_create()
{
	global $wpdb;
	$params = _civisync_get_req_data();
	$wpdb->insert( $wpdb->prefix . 'civi_member_sync', $params );
	civisync_rule_message( 'create' );
}
function civisync_rule_edit()
{
	global $wpdb;
	$id = _civisync_param_require( 'rule' );
	$params = _civisync_get_req_data();
	$wpdb->update( $wpdb->prefix . 'civi_member_sync', $params, array(
		'id' => $id
	), null, array( '%d' ) );
	civisync_rule_message( 'update' );
}

function civisync_rule_delete( $id )
{
	global $wpdb;
	$wpdb->delete( $wpdb->prefix . 'civi_member_sync', array(
		'id' => $id
	), array( '%d' ) );
}

function civisync_handle_table_actions( $list_table )
{
	$action = $list_table->current_action();
	if ( ! $action )
		return;
	// These actions don't require anything happening
	if ( 'new' == $action || 'edit' == $action ) {
		return;
	} elseif ( 'post-new' == $action ) {
		check_admin_referer( 'civisync-rule-new' );
		return civisync_rule_create();
	} elseif ( 'post-edit' == $action ) {
		check_admin_referer( 'civisync-rule-edit' );
		return civisync_rule_edit();
	} elseif ( 'sync-confirm' == $action ) {
		check_admin_referer( 'civisync-manual-sync' );
		return civisync_manual_sync();
	}

	if ( empty( $_REQUEST['rule'] ) )
		return;

	check_admin_referer( 'bulk-' . $list_table->_args['plural'] );
	$rules = (array) $_REQUEST['rule']; // Abuse that (array) "2" == array("2")
	// if ( 'disable' == $action )
	// 	array_walk($rules, 'shib_provider_disable');
	// elseif ( 'enable' == $action )
	// 	array_walk($rules, 'shib_provider_enable');
	// else
	if ( 'delete' == $action )
		array_walk($rules, 'civisync_rule_delete');
}

function civisync_get_memberships()
{
	return _civisync_get_the_thing( "MembershipType" );
}
function civisync_get_stati()
{
	return _civisync_get_the_thing( "MembershipStatus" );
}

add_action( 'admin_menu', function() {
	$list_table = null;
	// add_options_page( $page_title, $menu_title, $capability, $menu_slug, $function );
	$id = add_options_page( "CiviCRM Membership to WordPress Roles", "CiviCRM ↔ WP Sync", 'manage_options', 'civisync', function() use( &$list_table )
	{
		$action = $list_table->current_action();
		if ( $action == 'sync' )
			include "civisync-options-page-manual-sync.php";
		if ( $action == 'new' || $action == 'edit' )
			include "civisync-options-page-editor.php";
		else
			include "civisync-options-page-table.php";
	} );
	add_action( "load-{$id}", function() use( &$list_table )
	{
		$ms = civisync_get_memberships();
		$ss = civisync_get_stati();
		require 'class-civisync-rule-table.php';
		$list_table = new Civisync_Rule_Table( $ms, $ss );
		civisync_get_memberships();
		civisync_handle_table_actions( $list_table );
		$list_table->prepare_items();
		add_screen_option( 'per_page', array(
			'label' => 'Rules per page',
			'default' => 10,
			'option' => 'civisync_rules_per_page'
		) );
	} );
} );
add_filter('set-screen-option', function( $status, $option, $value )
{
	if ( 'civisync_rules_per_page' == $option )
		return $value;
	return $status;
}, 10, 3);

/**
function to set setings page for the plugin in menu
**/
function setup_civi_member_sync_check_menu() {
	add_submenu_page('CiviMember Role Sync', 'CiviMember Role Sync', 'List of Rules', 'add_users', 'civi_member_sync/settings.php');
	add_options_page( 'CiviMember Role Sync', 'CiviMember Role Sync', 'manage_options', 'civi_member_sync/list.php');
}

add_action("admin_menu", "setup_civi_member_sync_check_menu");
add_action('admin_init', 'my_plugin_admin_init');

// create the function called by your new action
function my_plugin_admin_init() {
	wp_enqueue_script('jquery');
	wp_enqueue_script('jquery-form');
}

function plugin_add_settings_link($links) {
	$settings_link = '<a href="admin.php?page=civi_member_sync/list.php">Settings</a>';
	array_push( $links, $settings_link );
	return $links;
}
$plugin = plugin_basename(__FILE__);
add_filter( "plugin_action_links_$plugin", 'plugin_add_settings_link' );
