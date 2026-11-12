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
                'team-member-block-controls-css',
                TEAM_MEMBER_BLOCK_ADMIN_URL . 'dist/modules.css',
                ['essential-blocks-iconpicker-css'],
                $controls_dependencies['version'],
                'all'
            );

            // The photo's delete button is absolutely positioned but its box was not, so the
            // button landed outside the photo, away from where it is hovered and clicked.
            //
            // Social Profiles list: core gives .dashicons `vertical-align: top`, so a Dashicon sat
            // at the top of its 2.5em-tall row while the label stayed centred. Centre both, and give
            // every icon the same 20px column so the labels line up whatever the icon's width.
            wp_add_inline_style(
                'team-member-block-controls-css',
                '.image-avatar{position:relative}.image-avatar .image-avatar-delete{top:10px}'
                . '.profile-icon-container{display:flex;align-items:center}'
                . '.profile-icon-container>:first-child{flex:0 0 20px;text-align:center}'
            );

            // The rules below aren't scoped to
            // this block (popovers render outside its panel), so they'd restyle every EB block's sidebar.
            if ( defined( 'ESSENTIAL_BLOCKS_VERSION' ) ) {
                return;
            }

            wp_add_inline_style(
                'team-member-block-controls-css',
                // Range controls' reset button: in the responsive variant it sat at the top of the
                // row, above the centred number box and against its corner. Centre it, add a gap.
                '.eb-component-wrapper>button.eb-range-reset-button{align-self:center;top:0;margin-left:4px}'
                // Range control layout rules the newer controls build ships but this plugin's
                // dist/modules.css lacks. Without them the number box overflowed under the reset
                // button and the row's 10px bottom margin pushed the centred button low. Other
                // single-block plugins load these rules, which hid the problem when they were active.
                . '.eb-panel-control .eb-range-controller-container{overflow:hidden}'
                . '.eb-panel-control .components-range-control__root .components-input-control__container{max-width:100%;min-width:0}'
                . '.eb-panel-control .responsiveRangeControllerWrapper .components-base-control{margin-bottom:0!important}'
            );

            /**
             * Range controls in popovers (Typography, Box Shadow) place the reset button with a
             * fixed margin-top sized for the old 36px controls. WordPress 7.0's 40px controls put
             * the number box lower, so the button ended up above it. Older versions keep the
             * original offsets.
             */
            if ( version_compare( get_bloginfo( 'version' ), '7.0', '>=' ) ) {
                wp_add_inline_style(
                    'team-member-block-controls-css',
                    '.eb-panel-control .eb-range-controller-container button.eb-range-reset-button{margin-top:28px;margin-left:4px}'
                    . '.eb-panel-control.eb-typography-component-panel .eb-range-controller-container button.eb-range-reset-button{margin-top:23px}'
                );
            }
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
