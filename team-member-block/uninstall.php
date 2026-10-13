<?php
/**
 * Removes the plugin's own data when it is deleted from the Plugins screen.
 * Team Member blocks already saved in posts are left untouched.
 *
 * @package team-member-block
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'team_member_block_default_settings' );
