<?php
/**
 * Team Member "Copy Settings": copy/paste block settings in the editor and a site-wide
 * default that new Team Member blocks start with.
 *
 * The default is stored in the `team_member_block_default_settings` option and edited through
 * `team-member-block/v1/default-settings`:
 *  - GET            users who can edit posts.
 *  - POST / DELETE  users with `edit_others_posts` (Editors, Administrators) by default,
 *                   filterable with `team_member_block_default_settings_capability`.
 *
 * @package team-member-block
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Team_Member_Block_Copy_Settings' ) ) {

    class Team_Member_Block_Copy_Settings {
        const OPTION     = 'team_member_block_default_settings';
        const REST_NS    = 'team-member-block/v1';
        const REST_ROUTE = '/default-settings';
        const HANDLE     = 'team-member-block-copy-settings';

        /** Limits that keep a stored default small and harmless. */
        const MAX_KEYS          = 2000;
        const MAX_STRING_LENGTH = 500;
        const MAX_DEPTH         = 4;
        const MAX_PROFILES      = 30;

        /**
         * Never part of a default: identity of one block instance, CSS generated for it,
         * and the person's own text and profile link. Mirrors the editor script.
         */
        const EXCLUDED_KEYS = [
            'blockId', 'blockMeta', 'commonStyles', 'resOption', 'lock', 'metadata', 'anchor',
            'name', 'jobTitle', 'description', 'avatarURL', 'newWindow', 'profilesOnly',
        ];

        public static function init() {
            add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
            add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_editor_assets' ] );
        }

        /** Capability needed to change the site-wide default. */
        public static function manage_capability() {
            return (string) apply_filters( 'team_member_block_default_settings_capability', 'edit_others_posts' );
        }

        public static function can_manage() {
            return current_user_can( self::manage_capability() );
        }

        public static function can_read() {
            return current_user_can( 'edit_posts' );
        }

        public static function register_routes() {
            register_rest_route(
                self::REST_NS,
                self::REST_ROUTE,
                [
                    [
                        'methods'             => WP_REST_Server::READABLE,
                        'callback'            => [ __CLASS__, 'rest_get' ],
                        'permission_callback' => [ __CLASS__, 'can_read' ],
                    ],
                    [
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => [ __CLASS__, 'rest_save' ],
                        'permission_callback' => [ __CLASS__, 'can_manage' ],
                        'args'                => [
                            'settings'  => [ 'type' => 'object' ],
                            'autoApply' => [ 'type' => 'boolean' ],
                        ],
                    ],
                    [
                        'methods'             => WP_REST_Server::DELETABLE,
                        'callback'            => [ __CLASS__, 'rest_delete' ],
                        'permission_callback' => [ __CLASS__, 'can_manage' ],
                    ],
                ]
            );
        }

        /**
         * The stored default, or null.
         *
         * @return array|null { settings: array, autoApply: bool }
         */
        public static function get_default() {
            $stored = get_option( self::OPTION, [] );
            if ( ! is_array( $stored ) || empty( $stored['settings'] ) || ! is_array( $stored['settings'] ) ) {
                return null;
            }
            return [
                'settings'  => $stored['settings'],
                'autoApply' => ! empty( $stored['autoApply'] ),
            ];
        }

        /** Shape shared by the REST responses and the editor bootstrap. */
        public static function client_state() {
            $default = self::get_default();
            return [
                'default'   => $default ? $default['settings'] : null,
                'autoApply' => $default ? $default['autoApply'] : false,
                'canManage' => self::can_manage(),
            ];
        }

        public static function rest_get() {
            return rest_ensure_response( self::client_state() );
        }

        public static function rest_save( WP_REST_Request $request ) {
            $current = self::get_default();
            $data    = $current ? $current : [ 'settings' => [], 'autoApply' => true ];

            if ( null !== $request->get_param( 'settings' ) ) {
                $settings = self::sanitize_settings( $request->get_param( 'settings' ) );
                if ( is_wp_error( $settings ) ) {
                    return $settings;
                }
                $data['settings'] = $settings;
            }
            if ( null !== $request->get_param( 'autoApply' ) ) {
                $data['autoApply'] = (bool) $request->get_param( 'autoApply' );
            }
            if ( empty( $data['settings'] ) ) {
                return new WP_Error( 'team_member_block_no_settings', __( 'Save default settings first.', 'team-member-block' ), [ 'status' => 400 ] );
            }

            update_option( self::OPTION, $data, false );
            return rest_ensure_response( self::client_state() );
        }

        public static function rest_delete() {
            delete_option( self::OPTION );
            return rest_ensure_response( self::client_state() );
        }

        /**
         * Block settings: known-safe keys, each value cleaned for what it is.
         *
         * @param mixed $settings Raw settings.
         * @return array|WP_Error
         */
        public static function sanitize_settings( $settings ) {
            if ( ! is_array( $settings ) || count( $settings ) > self::MAX_KEYS ) {
                return new WP_Error( 'team_member_block_invalid_settings', __( 'Invalid settings.', 'team-member-block' ), [ 'status' => 400 ] );
            }

            $clean = [];
            foreach ( $settings as $key => $value ) {
                if ( ! is_string( $key ) || ! preg_match( '/^[A-Za-z0-9_]{1,100}$/', $key ) || in_array( $key, self::EXCLUDED_KEYS, true ) ) {
                    continue;
                }
                switch ( $key ) {
                    case 'imageUrl':
                        $url   = is_string( $value ) ? esc_url_raw( $value, [ 'http', 'https' ] ) : '';
                        $value = '' !== $url ? $url : null;
                        break;
                    case 'imageId':
                        // Declared as a string, but the media picker stores a number: keep the type.
                        if ( is_int( $value ) ) {
                            $value = absint( $value );
                        } elseif ( is_string( $value ) && preg_match( '/^[0-9]{1,20}$/', $value ) ) {
                            $value = (string) $value;
                        } else {
                            $value = null;
                        }
                        break;
                    case 'imageAlt':
                        $value = is_string( $value ) ? sanitize_text_field( $value ) : null;
                        break;
                    case 'socialDetails':
                        $value = self::sanitize_profiles( $value );
                        break;
                    default:
                        $value = self::sanitize_value( $value, 0 );
                }
                if ( null !== $value ) {
                    $clean[ $key ] = $value;
                }
            }
            return $clean;
        }

        /**
         * Scalars are kept; strings lose anything that could end a CSS declaration or rule,
         * or break out of an HTML attribute.
         *
         * @param mixed $value Raw value.
         * @param int   $depth Nesting level.
         * @return mixed|null Null drops the value.
         */
        private static function sanitize_value( $value, $depth ) {
            if ( is_bool( $value ) || is_int( $value ) ) {
                return $value;
            }
            if ( is_float( $value ) ) {
                return is_finite( $value ) ? $value : null;
            }
            if ( is_string( $value ) ) {
                $value = wp_strip_all_tags( $value );
                $value = str_replace( [ '<', '>', '{', '}', ';', '\\', '"', '`' ], '', $value );
                return substr( $value, 0, self::MAX_STRING_LENGTH );
            }
            if ( is_array( $value ) && $depth < self::MAX_DEPTH ) {
                $clean = [];
                foreach ( array_slice( $value, 0, 100, true ) as $k => $v ) {
                    if ( is_string( $k ) && ! preg_match( '/^[A-Za-z0-9_-]{1,100}$/', $k ) ) {
                        continue;
                    }
                    $v = self::sanitize_value( $v, $depth + 1 );
                    if ( null !== $v ) {
                        $clean[ $k ] = $v;
                    }
                }
                return $clean;
            }
            return null;
        }

        /**
         * Social profiles for a default: networks, icons and colours, links left empty.
         *
         * @param mixed $profiles Raw list.
         * @return array|null
         */
        private static function sanitize_profiles( $profiles ) {
            if ( ! is_array( $profiles ) ) {
                return null;
            }
            $clean = [];
            foreach ( array_slice( array_values( $profiles ), 0, self::MAX_PROFILES ) as $profile ) {
                if ( ! is_array( $profile ) ) {
                    continue;
                }
                $icon    = isset( $profile['icon'] ) && is_string( $profile['icon'] ) ? $profile['icon'] : '';
                $clean[] = [
                    'title'          => isset( $profile['title'] ) && is_string( $profile['title'] ) ? sanitize_text_field( $profile['title'] ) : '',
                    // Icon classes such as "fab fa-facebook-f" or "dashicons dashicons-admin-users".
                    'icon'           => preg_match( '/^[A-Za-z0-9 _-]{0,100}$/', $icon ) ? $icon : '',
                    'color'          => self::sanitize_color( $profile['color'] ?? '' ),
                    'bgColor'        => self::sanitize_color( $profile['bgColor'] ?? '' ),
                    'link'           => '',
                    'linkOpenNewTab' => false,
                    'isExpanded'     => false,
                ];
            }
            return $clean;
        }

        /**
         * A CSS colour value, or '' when it is not one.
         *
         * @param mixed $color Raw value.
         * @return string
         */
        private static function sanitize_color( $color ) {
            if ( ! is_string( $color ) ) {
                return '';
            }
            $color    = trim( $color );
            $patterns = [
                '/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/',
                '/^(?:rgb|rgba|hsl|hsla)\(\s*[0-9.,%\s\/deg]+\)$/i',
                '/^var\(\s*--[A-Za-z0-9_-]+\s*\)$/',
                '/^[A-Za-z]{3,30}$/',
            ];
            foreach ( $patterns as $pattern ) {
                if ( preg_match( $pattern, $color ) ) {
                    return $color;
                }
            }
            return '';
        }

        public static function enqueue_editor_assets() {
            $asset_file = TEAM_MEMBER_BLOCK_ADMIN_PATH . '/dist/copy-settings/index.asset.php';
            if ( ! file_exists( $asset_file ) ) {
                return;
            }
            $asset = require $asset_file;
            if ( ! is_array( $asset ) || ! isset( $asset['dependencies'], $asset['version'] ) || ! is_array( $asset['dependencies'] ) ) {
                return;
            }

            wp_enqueue_script(
                self::HANDLE,
                TEAM_MEMBER_BLOCK_ADMIN_URL . 'dist/copy-settings/index.js',
                $asset['dependencies'],
                $asset['version'],
                true
            );

            // JSON_HEX_* flags keep "</script>" and quotes inert inside the inline script.
            wp_add_inline_script(
                self::HANDLE,
                'window.teamMemberBlockCopySettings = ' . wp_json_encode( self::client_state(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';',
                'before'
            );

            if ( function_exists( 'wp_set_script_translations' ) ) {
                wp_set_script_translations( self::HANDLE, 'team-member-block' );
            }
        }
    }

    Team_Member_Block_Copy_Settings::init();
}
