<?php

/**
 * Plugin Name:     Team Member Block
 * Plugin URI:         https://essential-blocks.com
 * Description:     Present your team members beautifully & gain instant credibility
 * Version:         1.2.1
 * Author:          WPDeveloper
 * Author URI:         https://wpdeveloper.net
 * License:         GPL-3.0-or-later
 * License URI:     https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:     team-member-block
 *
 * @package         team-member-block
 */

/**
 * Registers all block assets so that they can be enqueued through the block editor
 * in the corresponding context.
 *
 * @see https://developer.wordpress.org/block-editor/tutorials/block-tutorial/applying-styles-with-stylesheets/
 */

define( 'TEAM_MEMBER_BLOCK_VERSION', "1.2.1" );
define( 'TEAM_MEMBER_BLOCK_ADMIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TEAM_MEMBER_BLOCK_ADMIN_PATH', dirname( __FILE__ ) );

require_once __DIR__ . '/includes/font-loader.php';
require_once __DIR__ . '/includes/post-meta.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/lib/style-handler/style-handler.php';

function create_block_team_member_block_init() {

    $script_asset_path = TEAM_MEMBER_BLOCK_ADMIN_PATH . "/dist/index.asset.php";
    if ( ! file_exists( $script_asset_path ) ) {
        throw new Error(
            'You need to run `npm start` or `npm run build` for the "team-member-block/team-member-block" block first.'
        );
    }
    $script_asset     = require $script_asset_path;
    $all_dependencies = array_merge( $script_asset['dependencies'], [
        'wp-blocks',
        'wp-i18n',
        'wp-element',
        'wp-block-editor',
        'team-member-block-controls-util',
        'essential-blocks-eb-animation'
    ] );

    $index_js = TEAM_MEMBER_BLOCK_ADMIN_URL . 'dist/index.js';
    wp_register_script(
        'create-block-team-member-block-editor',
        $index_js,
        $all_dependencies,
        $script_asset['version'],
        true
    );

    $load_animation_js = TEAM_MEMBER_BLOCK_ADMIN_URL . 'assets/js/eb-animation-load.js';
    wp_register_script(
        'essential-blocks-eb-animation',
        $load_animation_js,
        [],
        TEAM_MEMBER_BLOCK_VERSION,
        true
    );

    $animate_css = TEAM_MEMBER_BLOCK_ADMIN_URL . 'assets/css/animate.min.css';
    wp_register_style(
        'essential-blocks-animation',
        $animate_css,
        [],
        TEAM_MEMBER_BLOCK_VERSION
    );

    wp_register_style(
        'fontawesome-frontend-css',
        TEAM_MEMBER_BLOCK_ADMIN_URL . 'assets/css/fontawesome/css/all.min.css',
        [],
        TEAM_MEMBER_BLOCK_VERSION,
        "all"
    );

    wp_register_style(
        'essential-blocks-hover-css',
        TEAM_MEMBER_BLOCK_ADMIN_URL . 'assets/css/hover-min.css',
        [],
        TEAM_MEMBER_BLOCK_VERSION,
        "all"
    );

    $style_css = TEAM_MEMBER_BLOCK_ADMIN_URL . 'dist/style.css';
    //Frontend & Editor Style
    wp_register_style(
        'create-block-team-member-frontend-style',
        $style_css,
        [
            'essential-blocks-hover-css',
            'fontawesome-frontend-css',
            'essential-blocks-animation'
        ],
        TEAM_MEMBER_BLOCK_VERSION
    );

    if ( ! WP_Block_Type_Registry::get_instance()->is_registered( 'essential-blocks/team-member' ) ) {
        register_block_type(
            Team_Member_Helper::get_block_register_path( "team-member-block/team-member-block", TEAM_MEMBER_BLOCK_ADMIN_PATH ),
            [
                'editor_script'   => 'create-block-team-member-block-editor',
                'editor_style'    => 'create-block-team-member-frontend-style',
                'render_callback' => function ( $attributes, $content ) {
                    if ( ! is_admin() ) {
                        wp_enqueue_style( 'create-block-team-member-frontend-style' );
                        wp_enqueue_style( 'essential-blocks-animation' );
                        wp_enqueue_script( 'essential-blocks-eb-animation' );
                    }
                    return $content;
                }
            ]
        );
    }
}

add_action( 'init', 'create_block_team_member_block_init', 99 );
