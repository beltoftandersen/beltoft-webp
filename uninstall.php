<?php
/**
 * Uninstall cleanup: removes plugin settings only. Does not touch any
 * .webp/.avif sibling files already generated — those are meant to keep
 * working (served by the vhost) independently of whether this plugin that
 * created them is still active.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'bwebp_options' );

// Deactivation already does this; repeated in case the plugin was removed without it.
wp_unschedule_hook( 'bwebp_convert_attachment' );
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'bwebp_convert_attachment' );
}
delete_post_meta_by_key( '_bwebp_pending' );
