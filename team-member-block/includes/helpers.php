<?php

/**
 * Load google fonts.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Team_Member_Helper {
    private static $instance;

    /**
     * Registers the plugin.
     */
    public static function register() {
        if ( null === self::$instance ) {
            self::$instance = new self;
        }
        return self::$instance;
    }

    /**
     * The Constructor.
     */
    public function __construct() {
        add_action( 'admin_enqueue_scripts', [$this, 'enqueues'] );
    }

    /**
     * Load fonts.
     *
     * @access public
     */
    public function enqueues( $hook ) {
        global $pagenow;

        /**
         * Only for admin add/edit pages/posts
         */
        $query_string = isset( $_SERVER['QUERY_STRING'] ) ? sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ) : '';

        if ( $pagenow == 'post-new.php' || $pagenow == 'post.php' || $pagenow == 'site-editor.php' || ( $pagenow == 'themes.php' && ! empty( $query_string ) && false !== strpos( $query_string, 'gutenberg-edit-site' ) ) ) {
            /**
             * Must be `require`, not `include_once`: on a second call within the same
             * request `include_once` returns bool(true) rather than the asset array,
             * which makes the array access below a TypeError on PHP 8.
             */
            $controls_asset_path = TEAM_MEMBER_BLOCK_ADMIN_PATH . '/dist/modules.asset.php';
            if ( ! file_exists( $controls_asset_path ) ) {
                return;
            }
            $controls_dependencies = require $controls_asset_path;
            if ( ! is_array( $controls_dependencies ) || ! isset( $controls_dependencies['dependencies'] ) || ! is_array( $controls_dependencies['dependencies'] ) ) {
                $controls_dependencies = [
                    'dependencies' => [],
                    'version'      => TEAM_MEMBER_BLOCK_VERSION
                ];
            }
            if ( ! isset( $controls_dependencies['version'] ) ) {
                $controls_dependencies['version'] = TEAM_MEMBER_BLOCK_VERSION;
            }

            wp_register_script(
                "team-member-block-controls-util",
                TEAM_MEMBER_BLOCK_ADMIN_URL . 'dist/modules.js',
                array_merge( $controls_dependencies['dependencies'], ['lodash'] ),
                $controls_dependencies['version'],
                true
            );

            wp_localize_script( 'team-member-block-controls-util', 'EssentialBlocksLocalize', [
                'eb_wp_version'  => (float) get_bloginfo( 'version' ),
                'rest_rootURL'   => get_rest_url(),
                'eb_plugins_url' => TEAM_MEMBER_BLOCK_ADMIN_URL,
				'fontAwesome' => "true"
            ] );

            wp_localize_script( 'team-member-block-controls-util', 'TeamMemberLocalize', [
                'eb_plugins_url' => TEAM_MEMBER_BLOCK_ADMIN_URL
            ] );

            if ( $pagenow == 'post-new.php' || $pagenow == 'post.php' ) {
                wp_localize_script( 'team-member-block-controls-util', 'eb_conditional_localize', [
                    'editor_type' => 'edit-post'
                ] );
            } else if ( $pagenow == 'site-editor.php' || $pagenow == 'themes.php' ) {
                wp_localize_script( 'team-member-block-controls-util', 'eb_conditional_localize', [
                    'editor_type' => 'edit-site'
                ] );
            }

			wp_register_style(
				'essential-blocks-iconpicker-css',
				TEAM_MEMBER_BLOCK_ADMIN_URL . 'dist/style-modules.css',
				[],
				TEAM_MEMBER_BLOCK_VERSION,
				'all'
			);

            wp_enqueue_style(
                'essential-blocks-editor-css',
                TEAM_MEMBER_BLOCK_ADMIN_URL . 'dist/modules.css',
                ['essential-blocks-iconpicker-css'],
                $controls_dependencies['version'],
                'all'
            );
        }
    }

    public static function get_block_register_path( $blockname, $blockPath ) {
        if ( (float) get_bloginfo( 'version' ) <= 5.6 ) {
            return $blockname;
        } else {
            return $blockPath;
        }
    }
}
Team_Member_Helper::register();
