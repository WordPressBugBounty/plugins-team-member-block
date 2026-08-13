<?php

/**
 * Plugin Name:     Team Member Block
 * Plugin URI:         https://essential-blocks.com
 * Description:     Present your team members beautifully & gain instant credibility
 * Version:         1.3.0
 * Author:          WPDeveloper
 * Author URI:         https://wpdeveloper.net
 * License:         GPL-3.0-or-later
 * License URI:     https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:     team-member-block
 * Requires PHP:    7.4
 * Requires at least: 6.0
 * Tested up to:    7.0
 *
 * @package         team-member-block
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers all block assets so that they can be enqueued through the block editor
 * in the corresponding context.
 *
 * @see https://developer.wordpress.org/block-editor/tutorials/block-tutorial/applying-styles-with-stylesheets/
 */

define( 'TEAM_MEMBER_BLOCK_VERSION', "1.3.0" );
define( 'TEAM_MEMBER_BLOCK_ADMIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TEAM_MEMBER_BLOCK_ADMIN_PATH', dirname( __FILE__ ) );

require_once __DIR__ . '/includes/font-loader.php';
require_once __DIR__ . '/includes/post-meta.php';
require_once __DIR__ . '/includes/helpers.php';

/**
 * `lib/style-handler` ships as a git submodule. If the plugin is running from a
 * checkout where the submodule was never initialised the directory is empty, and
 * an unconditional require_once is a hard fatal on every request. Guard it, and
 * surface the cause in the admin instead of taking the site down.
 */
$team_member_block_style_handler = __DIR__ . '/lib/style-handler/style-handler.php';
if ( file_exists( $team_member_block_style_handler ) ) {
    require_once $team_member_block_style_handler;
} else {
    team_member_block_flag_missing_file( 'lib/style-handler/style-handler.php' );
}
unset( $team_member_block_style_handler );

/**
 * Records a required file that could not be found and makes sure the admin
 * notice is hooked exactly once.
 *
 * @param string $relative_path Path of the missing file, relative to the plugin root.
 * @return array Every path recorded so far.
 */
function team_member_block_flag_missing_file( $relative_path = null ) {
    static $missing = [];

    if ( null !== $relative_path && ! in_array( $relative_path, $missing, true ) ) {
        $missing[] = $relative_path;
        if ( ! has_action( 'admin_notices', 'team_member_block_missing_build_notice' ) ) {
            add_action( 'admin_notices', 'team_member_block_missing_build_notice' );
        }
    }

    return $missing;
}

/**
 * Admin notice shown when a required runtime file is missing.
 *
 * Names the actual missing files rather than guessing at a cause: the files
 * under lib/style-handler/ come from a git submodule and are unrelated to
 * `npm run build`, which the previous wording incorrectly prescribed.
 */
function team_member_block_missing_build_notice() {
    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }
    $missing = team_member_block_flag_missing_file();
    if ( empty( $missing ) ) {
        return;
    }
    echo '<div class="notice notice-error"><p>';
    printf(
        /* translators: %s: comma separated list of missing file paths. */
        esc_html__( 'Team Member Block is missing required files: %s. This copy of the plugin is incomplete, so please reinstall it from an official release. If you are running it from a git checkout, run `npm run ensure-submodules` in the plugin directory.', 'team-member-block' ),
        '<code>' . implode( '</code>, <code>', array_map( 'esc_html', $missing ) ) . '</code>'
    );
    echo '</p></div>';
}

function create_block_team_member_block_init() {

    $script_asset_path = TEAM_MEMBER_BLOCK_ADMIN_PATH . "/dist/index.asset.php";
    if ( ! file_exists( $script_asset_path ) ) {
        team_member_block_flag_missing_file( 'dist/index.asset.php' );
        return;
    }
    $script_asset = require $script_asset_path;
    if ( ! is_array( $script_asset ) || ! isset( $script_asset['dependencies'] ) || ! is_array( $script_asset['dependencies'] ) ) {
        $script_asset = [
            'dependencies' => [],
            'version'      => TEAM_MEMBER_BLOCK_VERSION
        ];
    }
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
        isset( $script_asset['version'] ) ? $script_asset['version'] : TEAM_MEMBER_BLOCK_VERSION,
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

/**
 * Absolute URL of the Font Awesome stylesheet this plugin ships (6.5.1).
 *
 * @return string
 */
function team_member_block_fontawesome_src() {
    return TEAM_MEMBER_BLOCK_ADMIN_URL . 'assets/css/fontawesome/css/all.min.css';
}

/**
 * Makes sure the shared `fontawesome-frontend-css` handle resolves to Font
 * Awesome 6.5.1 rather than an older copy from another Essential Blocks plugin.
 *
 * Several EB plugins register this same handle from their own bundle, and
 * wp_register_style() is a silent no-op once a handle exists, so the first
 * plugin to run wins purely on alphabetical load order. When that winner ships
 * Font Awesome 5 (advanced-heading ships 5.15.2), every icon added in Font
 * Awesome 6 loses its glyph — `fa-x-twitter` among 749 others — while this
 * plugin still believes its dependency is satisfied.
 *
 * Re-pointing the existing handle keeps a single Font Awesome stylesheet and a
 * single webfont download, and leaves the other plugin's dependency graph and
 * queue position untouched. 6.5.1 is a superset of 5.15.2 for every icon class
 * those plugins emit, and it also declares the Font Awesome 5 family names, so
 * older markup keeps rendering.
 */
function team_member_block_ensure_fontawesome6() {
    $handle = 'fontawesome-frontend-css';
    $ours   = team_member_block_fontawesome_src();
    $styles = wp_styles();

    if ( ! isset( $styles->registered[ $handle ] ) ) {
        return;
    }

    $registered = $styles->registered[ $handle ];

    if ( $registered->src === $ours ) {
        return;
    }

    // Preserve everything the current owner set up, so re-registering is not
    // observable to it beyond the file that ends up being served.
    $deps     = $registered->deps;
    $args     = null !== $registered->args ? $registered->args : 'all';
    $extra    = $registered->extra;
    $enqueued = wp_style_is( $handle, 'enqueued' );

    wp_deregister_style( $handle );
    wp_register_style( $handle, $ours, $deps, TEAM_MEMBER_BLOCK_VERSION, $args );

    foreach ( $extra as $key => $value ) {
        wp_style_add_data( $handle, $key, $value );
    }

    if ( $enqueued ) {
        wp_enqueue_style( $handle );
    }
}

/**
 * Run after every plugin has had its chance to register the handle.
 *
 * `init` priority 100 covers the plugins that register at 99 like this one.
 * The enqueue hooks re-assert it for anything registering later, and the
 * function is idempotent, so repeating it costs a single string comparison.
 */
add_action( 'init', 'team_member_block_ensure_fontawesome6', 100 );
add_action( 'wp_enqueue_scripts', 'team_member_block_ensure_fontawesome6', 0 );
add_action( 'admin_enqueue_scripts', 'team_member_block_ensure_fontawesome6', 0 );
add_action( 'enqueue_block_assets', 'team_member_block_ensure_fontawesome6', 0 );
