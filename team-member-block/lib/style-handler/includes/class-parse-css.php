<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'EbStyleHandlerParseCss' ) ) {
    class EbStyleHandlerParseCss
    {

        private static $instance;

        public static function init()
        {
            if ( null === self::$instance ) {
                self::$instance = new self;
            }
            return self::$instance;
        }

        public function __construct()
        {
        }

        /**
         * Get Responsive Breakpoints
         */
        public static function get_responsive_breakpoints( $size = 'mobile' )
        {
            $settings     = get_option( 'eb_settings', [  ] );
            $settingsData = [  ];

            if ( array_key_exists( 'responsiveBreakpoints', $settings ) ) {
                $settingsData = $settings[ 'responsiveBreakpoints' ];
                if ( gettype( $settingsData ) === 'string' && strlen( $settingsData ) > 0 ) {
                    $settingsData = (array) json_decode( html_entity_decode( stripslashes( $settingsData ) ) );
                }
            } else {
                $settings[ 'responsiveBreakpoints' ] = [
                    'tablet' => 1024,
                    'mobile' => 767
                 ];

                update_option( 'eb_settings', $settings );

                $settingsData = $settings[ 'responsiveBreakpoints' ];
            }

            return $settingsData[ $size ];
        }

        /**
         * Recursive function for parsing blocks
         */
        public static function eb_block_style_recursive( $block, &$eb_blocks )
        {
            if ( count( $block ) > 0 ) {
                foreach ( $block as $item ) {
                    $attributes = $item[ 'attrs' ];

                    $blockId = "";
                    if ( isset( $attributes[ 'blockId' ] ) && ! empty( $attributes[ 'blockId' ] ) ) {
                        $blockId = $attributes[ 'blockId' ];
                    }
                    $blockMeta = "";
                    if ( isset( $attributes[ 'blockMeta' ] ) && ! empty( $attributes[ 'blockMeta' ] ) ) {
                        $blockMeta = $attributes[ 'blockMeta' ];
                    }
                    $commonStyles = "";
                    if ( isset( $attributes[ 'commonStyles' ] ) && ! empty( $attributes[ 'commonStyles' ] ) ) {
                        $commonStyles = $attributes[ 'commonStyles' ];
                    }
                    $customCss = "";
                    if ( isset( $attributes[ 'customCss' ] ) && ! empty( $attributes[ 'customCss' ] ) ) {
                        $customCss = $attributes[ 'customCss' ];
                    }

                    if ( isset( $attributes[ 'ref' ] ) && ! empty( $attributes[ 'ref' ] ) && $item[ "blockName" ] === "core/block" ) {
                        // `ref` comes from the saved block and can name any post ID.
                        // Only a published reusable block (wp_block) is read: anything
                        // else (another user's private post, a missing ID) is skipped,
                        // so its CSS is never copied into a public file.
                        $reusable_block = get_post( absint( $attributes[ 'ref' ] ) );
                        if (
                            $reusable_block instanceof WP_Post
                            && 'wp_block' === $reusable_block->post_type
                            && 'publish' === $reusable_block->post_status
                        ) {
                            $reusable_content                                   = parse_blocks( $reusable_block->post_content );
                            $reusable_blocks                                    = [  ];
                            $eb_blocks[ "reusableBlocks" ][ $reusable_block->ID ] = self::eb_block_style_recursive( $reusable_content, $reusable_blocks );
                        }
                    } else {
                        if ( isset( $item[ "innerBlocks" ] ) && count( $item[ "innerBlocks" ] ) > 0 ) {
                            self::eb_block_style_recursive( $item[ 'innerBlocks' ], $eb_blocks );
                            if ( isset( $attributes[ 'blockMeta' ] ) && ! empty( $attributes[ 'blockMeta' ] ) ) {
                                $eb_blocks[ $blockId ] = [
                                    'blockMeta'    => $blockMeta,
                                    'commonStyles' => $commonStyles,
                                    'customCss'    => $customCss
                                 ];
                            }
                        } else if ( isset( $attributes[ 'blockMeta' ] ) && ! empty( $attributes[ 'blockMeta' ] ) ) {
                            $eb_blocks[ $blockId ] = [
                                'blockMeta'    => $blockMeta,
                                'commonStyles' => $commonStyles,
                                'customCss'    => $customCss
                             ];
                        }
                    }
                }
            }

            return $eb_blocks;
        }

        /**
         * Blockarray to Style Array Function
         */
        public static function blocks_to_style_array( $blocks, $styles = [  ] )
        {
            if ( is_array( $blocks ) && count( $blocks ) > 0 ) {
                foreach ( $blocks as $blockId => $block ) {
                    if ( 'reusableBlocks' === $blockId ) {
                        if ( is_array( $block ) && count( $block ) > 0 ) {
                            foreach ( $block as $reusableId => $reusableBlock ) {
                                self::blocks_to_style_array( $reusableBlock, $styles );
                            }
                        }
                    } else {
                        $styles[ $blockId ] = [
                            'desktop'   => "",
                            'tab'       => "",
                            'mobile'    => "",
                            'customCss' => ""
                         ];

                        if ( is_array( $block ) && count( $block ) > 0 ) {
                            foreach ( $block as $value ) {
                                if ( is_array( $value ) && count( $value ) > 0 ) {
                                    if ( isset( $value[ "desktop" ] ) ) {
                                        $styles[ $blockId ][ "desktop" ] .= $value[ "desktop" ];
                                    }
                                    if ( isset( $value[ "tab" ] ) ) {
                                        $styles[ $blockId ][ "tab" ] .= $value[ "tab" ];
                                    }
                                    if ( isset( $value[ "mobile" ] ) ) {
                                        $styles[ $blockId ][ "mobile" ] .= $value[ "mobile" ];
                                    }
                                } else if ( isset( $block[ 'customCss' ] ) && is_string( $block[ 'customCss' ] ) && strlen( $block[ 'customCss' ] ) > 0 ) {
                                    $styles[ $blockId ][ "customCss" ] .= $block[ 'customCss' ];
                                }
                            }
                        }
                    }
                }
            }
            return $styles;
        }

        /**
         * Whether CSS stored with a post may be written to a public file as is.
         *
         * Block CSS (blockMeta, commonStyles, customCss) and blockId are block
         * attributes, so anyone who can save a post controls them. Content by an
         * author without unfiltered_html is passed through sanitize_css().
         *
         * @param WP_Post|null $post Post the CSS belongs to.
         * @return bool
         */
        public static function is_trusted_post( $post )
        {
            if ( ! ( $post instanceof WP_Post ) || empty( $post->post_author ) ) {
                return false;
            }
            return user_can( (int) $post->post_author, 'unfiltered_html' );
        }

        /**
         * Remove constructs that have no place in block styles: @import (loads
         * remote CSS), at-rules hidden behind CSS escapes, legacy script-in-CSS
         * (expression(), behavior, -moz-binding), script URLs and anything that
         * looks like markup.
         *
         * @param string $css CSS.
         * @return string
         */
        public static function sanitize_css( $css )
        {
            if ( ! is_string( $css ) || '' === $css ) {
                return '';
            }
            // No markup can be formed without "<" (or its CSS escape).
            $css = str_replace( [ '<', '\\3c', '\\3C' ], '', $css );
            $css = preg_replace( '/@import\b[^;]*;?/i', '', $css );
            // An escaped at-keyword such as `@\69mport` is still @import to a browser.
            $css = preg_replace( '/@[\w-]*\\\\[^;{]*;?/', '', $css );
            $css = preg_replace( '/expression\s*\(/i', '(', $css );
            $css = preg_replace( '/(?:javascript|vbscript)\s*:/i', '', $css );
            $css = preg_replace( '/(?:-moz-binding|behavior)\s*:[^;}]*;?/i', '', $css );
            // A function name written with CSS escapes (`u\72l(`) can hide url().
            $css = preg_replace( '/[\w-]*\\\\[0-9a-fA-F]{1,6}\s?[\w\\\\-]*\(/', '(', $css );
            // image-set() takes bare strings as URLs, so it cannot be checked like url().
            $css = preg_replace( '/(?:-webkit-)?image-set\s*\(/i', '(', $css );
            // Off-site url() lets attribute selectors send page data to another host.
            $css = preg_replace_callback( '/url\(\s*([\'"]?)(.*?)\1\s*\)/i', [ __CLASS__, 'filter_css_url' ], $css );
            return $css;
        }

        /**
         * Keep a url() only when it is relative, same-site or an inline image.
         *
         * @param array $matches preg_replace_callback() matches.
         * @return string
         */
        public static function filter_css_url( $matches )
        {
            $url = trim( $matches[ 2 ] );
            // The browser decodes CSS escapes before it resolves the URL, so an
            // escaped value cannot be checked as written. Image URLs need none.
            if ( false !== strpos( $url, '\\' ) ) {
                return 'none';
            }
            if ( '' === $url || 0 === stripos( $url, 'data:image/' ) ) {
                return $matches[ 0 ];
            }
            if ( ! preg_match( '#^(?:[a-z][a-z0-9+.-]*:|//)#i', $url ) ) {
                return $matches[ 0 ];
            }
            $host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
            $hosts = array_map( 'strtolower', [
                (string) wp_parse_url( home_url(), PHP_URL_HOST ),
                (string) wp_parse_url( site_url(), PHP_URL_HOST ),
                (string) wp_parse_url( content_url(), PHP_URL_HOST )
             ] );
            if ( '' !== $host && in_array( $host, $hosts, true ) && preg_match( '#^(?:https?:)?//#i', $url ) ) {
                return $matches[ 0 ];
            }
            return 'none';
        }

        /**
         * Block style keys are block IDs and are written inside CSS comments.
         *
         * @param string $key Block ID.
         * @return string
         */
        public static function sanitize_block_key( $key )
        {
            return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $key );
        }

        /**
         * Enqueue frontend css for post if have one
         * @param array
         * @param bool $sanitize Pass untrusted CSS through sanitize_css().
         * @return string
         * @since 1.0.2
         */
        public static function build_css( $style_object, $sanitize = false )
        {
            $block_styles = $style_object;

            $css = '';
            foreach ( $block_styles as $block_style_key => $block_style ) {
                $block_style_key = self::sanitize_block_key( $block_style_key );
                if ( $sanitize && is_array( $block_style ) ) {
                    $block_style = array_map( [ __CLASS__, 'sanitize_css' ], $block_style );
                }
                if ( ! empty( $block_css = (array) $block_style ) ) {
                    $css .= sprintf(
                        '/* %1$s Starts */',
                        $block_style_key
                    );
                    foreach ( $block_css as $media => $style ) {
                        switch ( $media ) {
                            case "desktop":
                                $css .= preg_replace( '/\s+/', ' ', $style );
                                break;
                            case "tab":
                                $css .= ' @media(max-width: ' . self::get_responsive_breakpoints( 'tablet' ) . 'px){';
                                $css .= preg_replace( '/\s+/', ' ', $style );
                                $css .= '}';
                                break;
                            case "mobile":
                                $css .= ' @media(max-width: ' . self::get_responsive_breakpoints( 'mobile' ) . 'px){';
                                $css .= preg_replace( '/\s+/', ' ', $style );
                                $css .= '}';
                                break;
                            case "customCss":
                                $css .= preg_replace( '/\s+/', ' ', $style );
                                break;
                        }
                    }
                    $css .= sprintf(
                        '/* =%1$s= Ends */',
                        $block_style_key
                    );
                }
            }
            return trim( $css );
        }

        /**
         * Helper function to get string between 2 string
         * @since 3.3.0
         */
        public static function get_between_data( $string, $start, $end )
        {
            $pos_string   = stripos( $string, $start );
            $substr_data  = substr( $string, $pos_string );
            $string_two   = substr( $substr_data, strlen( $start ) );
            $second_pos   = stripos( $string_two, $end );
            $string_three = substr( $string_two, 0, $second_pos );

            // remove whitespaces from result
            $result_unit = trim( $string_three );

            // return result_unit
            return $result_unit;
        }

        /**
         * Is Gutenberg Editor
         */
        public static function eb_stylehandler_is_gutenberg_editor( $pagenow, $param )
        {
            if ( $pagenow == 'post-new.php' || $pagenow == 'post.php' || $pagenow == 'site-editor.php' ) {
                return true;
            }

            if ( $pagenow == 'themes.php' && ! empty( $param ) && str_contains( $param, 'gutenberg-edit-site' ) ) {
                return true;
            }

            return false;
        }
    }

    EbStyleHandlerParseCss::init();
}
