<?php
// Exit if accessed directly.
use EssentialBlocks\Integrations\AssetGeneration;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'EbStyleHandler' ) ) {
    final class EbStyleHandler
    {
        private static $instance;

        private $prefix = 'eb-style';
        private $eb_style_dir;
        private $eb_style_url;
        /**
         * Holds block styles array
         *
         * @var array
         */
        public static $_block_styles = [  ];

        public static function init()
        {
            if ( null === self::$instance ) {
                self::$instance = new self;
            }
            return self::$instance;
        }

        public function __construct()
        {
            $upload_dir = wp_upload_dir();

            $this->eb_style_dir = $upload_dir[ 'basedir' ] . '/' . $this->prefix . DIRECTORY_SEPARATOR;
            $this->eb_style_url = set_url_scheme( $upload_dir[ 'baseurl' ] ) . '/' . $this->prefix . '/';

            $this->load_style_handler_dependencies();

            // add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ], 99 );

            add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ], 99 );
            add_action( 'save_post', [ $this, 'stylehandler_get_post_content' ], 10, 3 );
            add_action( 'wp', [ $this, 'stylehandler_generate_post_content' ] );
            add_action( 'rest_after_save_widget', [ $this, 'eb_save_widget_action' ], 10, 4 );
            add_action( 'eb_after_save_responsiveBreakpoints_settings', [ $this, 'generate_style_for_breakpoint_change' ], 10, 1 );
            add_action( 'eb_after_reset_responsiveBreakpoints_settings', [ $this, 'reset_breakpoint' ], 10 );
            // FSE assets generation
            add_action( 'init', function () {
                if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
                    add_filter( "404_template", [ $this, 'fse_assets_generation' ], 99, 3 );
                    add_filter( "archive_template", [ $this, 'fse_assets_generation' ], 99, 3 );
                    add_filter( "category_template", [ $this, 'fse_assets_generation' ], 99, 3 );
                    add_filter( "frontpage_template", [ $this, 'fse_assets_generation' ], 99, 3 );
                    add_filter( "home_template", [ $this, 'fse_assets_generation' ], 99, 3 );
                    add_filter( "index_template", [ $this, 'fse_assets_generation' ], 99, 3 );
                    add_filter( "page_template", [ $this, 'fse_assets_generation' ], 99, 3 );
                    add_filter( "search_template", [ $this, 'fse_assets_generation' ], 99, 3 );
                    add_filter( "single_template", [ $this, 'fse_assets_generation' ], 99, 3 );
                    add_filter( "singular_template", [ $this, 'fse_assets_generation' ], 99, 3 );
                    add_filter( "tag_template", [ $this, 'fse_assets_generation' ], 99, 3 );
                    add_filter( "taxonomy_template", [ $this, 'fse_assets_generation' ], 99, 3 );
                }
            }, 999 );
        }

        /**
         * Generate FSE Assets
         */
        public function fse_assets_generation( $template, $type, $templates )
        {
            $block_template = resolve_block_template( $type, $templates, $template );
            if ( ! empty( $block_template ) ) {
                $parsed_content = parse_blocks( $block_template->content );
                if ( is_array( $parsed_content ) && ! empty( $parsed_content ) ) {
                    foreach ( $parsed_content as $content ) {
                        if (  ( 'core/template-part' == $content[ 'blockName' ] ) || ( 'core/template' == $content[ 'blockName' ] ) ) {
                            $post_ids = isset( $content[ 'attrs' ][ 'slug' ] ) ? self::eb_get_post_content_by_post_name( $content[ 'attrs' ][ 'slug' ] ) : [  ];

                            if ( ! empty( $post_ids ) ) {
                                foreach ( $post_ids as $id ) {
                                    $post_id        = (int) $id[ 'ID' ];
                                    $post           = get_post( $post_id );
                                    $parsed_content = parse_blocks( $post->post_content );
                                    $this->eb_write_css_from_content( $post, $post_id, $parsed_content );
                                }
                            }
                        } else {
                            $post_ids = self::eb_get_post_content_by_post_name( $block_template->slug );
                            if ( ! empty( $post_ids ) ) {
                                foreach ( $post_ids as $id ) {
                                    $post_id        = (int) $id[ 'ID' ];
                                    $post           = get_post( $post_id );
                                    $parsed_content = parse_blocks( $post->post_content );
                                    $this->eb_write_css_from_content( $post, $post_id, $parsed_content );
                                }
                            }
                        }
                    }
                }
            }
            return $template;
        }

        /**
         * Write CSS
         */
        public function eb_write_css_from_content( $post, $post_id, $parsed_content )
        {
            $eb_blocks          = [  ];
            $recursive_response = EbStyleHandlerParseCss::eb_block_style_recursive( $parsed_content, $eb_blocks );
            $reusable_Blocks    = ! empty( $recursive_response[ 'reusableBlocks' ] ) ? $recursive_response[ 'reusableBlocks' ] : [  ];
            // remove empty reusable blocks
            $reusable_Blocks = array_filter( $reusable_Blocks, function ( $v ) {
                return ! empty( $v );
            } );
            unset( $recursive_response[ "reusableBlocks" ] );
            $style       = EbStyleHandlerParseCss::blocks_to_style_array( $recursive_response );
            $reusableIds = $reusable_Blocks && is_array( $reusable_Blocks ) ? array_keys( $reusable_Blocks ) : [  ];
            // The site-wide list is loaded on every page, so only templates may set it.
            if ( ! empty( $reusableIds ) && self::is_template_post( $post ) ) {
                update_option( '_eb_reusable_block_ids', $reusableIds );
            }
            update_post_meta( $post_id, '_eb_reusable_block_ids', $reusableIds );
            $this->write_block_css( $style, $post ); //Write CSS file for this page

            if ( ! empty( $reusable_Blocks ) ) {
                foreach ( $reusable_Blocks as $blockId => $block ) {
                    $style = EbStyleHandlerParseCss::blocks_to_style_array( $block );
                    $this->write_reusable_block_css( $style, $blockId );
                }
            }
        }

        /**
         * Save Widget CSS when Widget is saved
         * @return void
         * @since 3.5.3
         */
        public function eb_save_widget_action( $id, $sidebar_id, $request, $creating )
        {
            $parsed_content = isset( $request[ 'instance' ][ 'raw' ][ 'content' ] ) ? parse_blocks( $request[ 'instance' ][ 'raw' ][ 'content' ] ) : [  ];
            if ( is_array( $parsed_content ) && ! empty( $parsed_content ) ) {
                $eb_blocks          = [  ];
                $recursive_response = EbStyleHandlerParseCss::eb_block_style_recursive( $parsed_content, $eb_blocks );
                unset( $recursive_response[ "reusableBlocks" ] );
                $style = EbStyleHandlerParseCss::blocks_to_style_array( $recursive_response );
                //Write CSS file for Widget
                $this->single_file_css_generator( $style, $this->eb_style_dir, $this->prefix . '-widget.min.css' );
            }
        }

        /**
         * Load Dependencies
         */
        private function load_style_handler_dependencies()
        {
            require_once plugin_dir_path( __FILE__ ) . 'includes/class-parse-css.php';
        }

        /**
         * Enqueue frontend css for post if have one
         * @return void
         * @since 1.0.2
         */
        public function enqueue_frontend_assets()
        {
            global $post;

            $deps = apply_filters( 'eb_generated_css_frontend_deps', [  ] );

            // generatepress elements
            if ( in_array( 'gp-premium/gp-premium.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
                $gp_elements = get_posts( [ 'post_type' => 'gp_elements' ] );
                if ( is_array( $gp_elements ) && ! empty( $gp_elements ) ) {
                    foreach ( $gp_elements as $element ) {
                        if ( file_exists( $this->eb_style_dir . $this->prefix . '-' . $element->ID . '.min.css' ) ) {
                            wp_enqueue_style(
                                'eb-block-style-' . $element->ID,
                                $this->eb_style_url . $this->prefix . '-' . $element->ID . '.min.css',
                                $deps,
                                substr( md5( microtime( true ) ), 0, 10 )
                            );
                        }
                    }
                }
            }

            if ( ! empty( $post ) && ! empty( $post->ID ) ) {
                //Page/Post Style Enqueue
                if ( file_exists( $this->eb_style_dir . $this->prefix . '-' . $post->ID . '.min.css' ) ) {
                    wp_enqueue_style(
                        'eb-block-style-' . $post->ID,
                        $this->eb_style_url . $this->prefix . '-' . $post->ID . '.min.css',
                        $deps,
                        substr( md5( microtime( true ) ), 0, 10 )
                    );
                }

                // Reusable block Style Enqueues
                $reusableIds         = get_post_meta( $post->ID, '_eb_reusable_block_ids', true );
                $reusableIds         = ! empty( $reusableIds ) ? $reusableIds : [  ];
                $templateReusableIds = array_filter( (array) get_option( '_eb_reusable_block_ids', [  ] ), [ __CLASS__, 'is_published_reusable_block' ] );
                $reusableIds         = array_unique( array_merge( $reusableIds, $templateReusableIds ) );
                if ( ! empty( $reusableIds ) && is_array( $reusableIds ) ) {
                    foreach ( $reusableIds as $reusableId ) {
                        if ( file_exists( $this->eb_style_dir . 'reusable-blocks/eb-reusable-' . $reusableId . '.min.css' ) ) {
                            wp_enqueue_style( 'eb-reusable-block-style-' . $reusableId, $this->eb_style_url . 'reusable-blocks/eb-reusable-' . $reusableId . '.min.css', $deps, substr( md5( microtime( true ) ), 0, 10 ) );
                        }
                    }
                }
            }

            //Widget Style Enqueue
            if ( file_exists( $this->eb_style_dir . $this->prefix . '-widget.min.css' ) ) {
                wp_enqueue_style( 'eb-widget-style', $this->eb_style_url . $this->prefix . '-widget.min.css', $deps, substr( md5( microtime( true ) ), 0, 10 ) );
            }

            //FSE Style Enqueue
            if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() && file_exists( $this->eb_style_dir . $this->prefix . '-edit-site.min.css' ) ) {
                wp_enqueue_style( 'eb-fullsite-style', $this->eb_style_url . $this->prefix . '-edit-site.min.css', $deps, substr( md5( microtime( true ) ), 0, 10 ) );
            }

            /**
             * Hooks assets for enqueue in frontend
             * @param $path string
             * @param $url string
             * @since 3.0.0
             */
            do_action( 'eb_frontend_assets', $this->eb_style_dir, $this->eb_style_url );
        }

        /**
         * Get post content when page is saved
         */
        public function stylehandler_get_post_content( $post_id, $post, $update )
        {
            $post_type = get_post_type( $post_id );

            //If This page is draft, return
            if ( isset( $post->post_status ) && 'auto-draft' == $post->post_status ) {
                return;
            }

            // Autosave, do nothing
            if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
                return;
            }

            // Return if it's a post revision
            if ( false !== wp_is_post_revision( $post_id ) ) {
                return;
            }

            if ( $post_type === 'wp_template_part' || $post_type === 'wp_template' ) {
                $post           = get_post( $post_id );
                $parsed_content = parse_blocks( $post->post_content );
            } else {
                $parsed_content = parse_blocks( $post->post_content );
            }

            if ( empty( $parsed_content ) ) {
                delete_post_meta( $post_id, '_eb_reusable_block_ids' );
            }

            if ( is_array( $parsed_content ) && ! empty( $parsed_content ) ) {
                $this->eb_write_css_from_content( $post, $post_id, $parsed_content );
            }
        }

        /**
         * Get post content when page is load in frontend
         */
        public function stylehandler_generate_post_content()
        {
            $post_id = get_the_ID();
            if ( $post_id ) {
                $post_type = get_post_type( $post_id );
                $post      = get_post( $post_id );
                //If This page is draft, return
                if ( isset( $post->post_status ) && 'auto-draft' == $post->post_status ) {
                    return;
                }
                // Return if it's a post revision
                if ( false !== wp_is_post_revision( $post_id ) ) {
                    return;
                }

                // CSS is written on save; a page view only fills in missing or stale files.
                if ( $this->is_post_css_current( $post ) ) {
                    return;
                }

                if ( $post_type === 'wp_template_part' || $post_type === 'wp_template' ) {
                    // Todo: have to do something for FSE
                    $post           = get_post( $post_id );
                    $parsed_content = parse_blocks( $post->post_content );
                } else {
                    $parsed_content = parse_blocks( $post->post_content );
                }

                if ( empty( $parsed_content ) ) {
                    delete_post_meta( $post_id, '_eb_reusable_block_ids' );
                }

                if ( is_array( $parsed_content ) && ! empty( $parsed_content ) ) {
                    $eb_blocks          = [  ];
                    $recursive_response = EbStyleHandlerParseCss::eb_block_style_recursive( $parsed_content, $eb_blocks );
                    $reusable_Blocks    = ! empty( $recursive_response[ 'reusableBlocks' ] ) ? $recursive_response[ 'reusableBlocks' ] : [  ];

                    // remove empty reusable blocks
                    $reusable_Blocks = array_filter( $reusable_Blocks, function ( $v ) {
                        return ! empty( $v );
                    } );
                    unset( $recursive_response[ "reusableBlocks" ] );

                    $style       = EbStyleHandlerParseCss::blocks_to_style_array( $recursive_response );
                    $reusableIds = $reusable_Blocks && is_array( $reusable_Blocks ) ? array_keys( $reusable_Blocks ) : [  ];
                    if ( ! empty( $reusableIds ) && self::is_template_post( $post ) ) {
                        update_option( '_eb_reusable_block_ids', $reusableIds );
                    }
                    update_post_meta( $post_id, '_eb_reusable_block_ids', $reusableIds );
                    $this->write_block_css( $style, $post ); //Write CSS file for this page

                    if ( ! empty( $reusable_Blocks ) ) {
                        foreach ( $reusable_Blocks as $blockId => $block ) {
                            $style = EbStyleHandlerParseCss::blocks_to_style_array( $block );
                            $this->write_reusable_block_css( $style, $blockId );
                        }
                    }
                }
            }
        }

        /**
         * Whether a post is a block template or template part.
         *
         * @param WP_Post|null $post Post.
         * @return bool
         */
        private static function is_template_post( $post )
        {
            return isset( $post->post_type ) && in_array( $post->post_type, [ 'wp_template', 'wp_template_part' ], true );
        }

        /**
         * Whether an ID is a published reusable block.
         *
         * @param mixed $id Post ID.
         * @return bool
         */
        public static function is_published_reusable_block( $id )
        {
            $id = absint( $id );
            return $id && 'wp_block' === get_post_type( $id ) && 'publish' === get_post_status( $id );
        }

        /**
         * Whether the post's CSS file is newer than the post and its reusable blocks.
         *
         * @param WP_Post $post Post.
         * @return bool
         */
        private function is_post_css_current( $post )
        {
            if ( ! ( $post instanceof WP_Post ) || self::is_template_post( $post ) ) {
                return false;
            }
            $file = $this->eb_style_dir . $this->prefix . '-' . abs( $post->ID ) . '.min.css';
            if ( ! file_exists( $file ) ) {
                return false;
            }
            $mtime = filemtime( $file );
            $ids   = get_post_meta( $post->ID, '_eb_reusable_block_ids', true );
            $posts = array_merge( [ $post ], array_filter( array_map( 'get_post', array_map( 'absint', (array) $ids ) ) ) );
            foreach ( $posts as $item ) {
                if ( strtotime( $item->post_modified_gmt . ' UTC' ) > $mtime ) {
                    return false;
                }
            }
            return true;
        }

        /**
         * Ajax callback to write css in upload directory
         * @retun void
         * @since 1.0.2
         */
        private function write_block_css( $block_styles, $post )
        {
            //Write CSS for FSE
            if ( isset( $post->post_type ) && ( $post->post_type === "wp_template_part" || $post->post_type === "wp_template" && ! empty( $block_styles ) ) ) {
                $this->single_file_css_generator( $block_styles, $this->eb_style_dir, $this->prefix . '-edit-site.min.css' );
            }
            // Write CSS for Page/Posts
            else {
                $sanitize = ! EbStyleHandlerParseCss::is_trusted_post( $post );
                if ( ! empty( $css = EbStyleHandlerParseCss::build_css( $block_styles, $sanitize ) ) ) {
                    if ( ! file_exists( $this->eb_style_dir ) ) {
                        mkdir( $this->eb_style_dir );
                    }
                    file_put_contents( $this->eb_style_dir . $this->prefix . '-' . abs( $post->ID ) . '.min.css', $css );
                }
            }
        }

        /**
         * Write css for Reusable block
         * @retun void
         * @since 3.4.0
         */
        private function write_reusable_block_css( $block_styles, $id )
        {
            if ( isset( $block_styles ) && is_array( $block_styles ) ) {
                $sanitize = ! EbStyleHandlerParseCss::is_trusted_post( get_post( absint( $id ) ) );
                if ( ! empty( $css = EbStyleHandlerParseCss::build_css( $block_styles, $sanitize ) ) ) {
                    $upload_dir = $this->eb_style_dir . 'reusable-blocks/';
                    if ( ! file_exists( $upload_dir ) ) {
                        mkdir( $upload_dir, 0755, true );
                    }
                    file_put_contents( $upload_dir . '/' . 'eb-reusable-' . abs( $id ) . '.min.css', $css );
                }
            }
        }

        /**
         * Single file css generator
         * @retun void
         * @since 3.5.3
         */
        private function single_file_css_generator( $block_styles, $upload_dir, $filename )
        {
            $editSiteCssPath = $upload_dir . $filename;
            if ( file_exists( $editSiteCssPath ) ) {
                $existingCss = file_get_contents( $editSiteCssPath );
                $pattern     = "~\/\*(.*?)\*\/~";
                preg_match_all( $pattern, $existingCss, $result, PREG_PATTERN_ORDER );
                $allComments  = $result[ 0 ];
                $seperatedIds = [  ];
                foreach ( $allComments as $comment ) {
                    $id = preg_replace( '/[^A-Za-z0-9\-]|Ends|Starts/', '', $comment );

                    if ( strpos( $comment, "Starts" ) ) {
                        $seperatedIds[ $id ][ 'start' ] = $comment;
                    } else if ( strpos( $comment, "Ends" ) ) {
                        $seperatedIds[ $id ][ 'end' ] = $comment;
                    }
                }

                $seperateStyles = [  ];
                foreach ( $seperatedIds as $key => $ids ) {
                    $seperateStyles[  ][ $key ] = isset( $block_styles[ $key ] ) ? $block_styles[ $key ] : [  ];
                }

                self::$_block_styles = array_merge( self::$_block_styles, $block_styles );

                if ( ! empty( $css = EbStyleHandlerParseCss::build_css( self::$_block_styles ) ) ) {
                    if ( ! file_exists( $upload_dir ) ) {
                        mkdir( $upload_dir );
                    }

                    file_put_contents( $editSiteCssPath, $css );
                }
            } else {
                if ( ! empty( $css = EbStyleHandlerParseCss::build_css( $block_styles ) ) ) {
                    if ( ! file_exists( $this->eb_style_dir ) ) {
                        mkdir( $this->eb_style_dir );
                    }

                    file_put_contents( $editSiteCssPath, $css );
                }
            }
        }

        /**
         * Get post id by post_name for template
         */
        public static function eb_get_post_content_by_post_name( $post_name )
        {
            global $wpdb;
            $sql = $wpdb->prepare( "SELECT ID FROM {$wpdb->prefix}posts WHERE post_name = %s", $post_name );
            return $wpdb->get_results( $sql, ARRAY_A );
        }

        /**
         * Generate all assets after custom breakpoint change
         * @since 4.5.0
         * @param string $breakpoints
         * @return void
         */
        public function generate_style_for_breakpoint_change( $breakpoints )
        {
            if ( is_dir( $this->eb_style_dir ) ) {
                AssetGeneration::remove_directory_files( $this->eb_style_dir );
            }
            $breakpoints = json_decode( stripslashes( $breakpoints ), true );
            if ( file_exists( ESSENTIAL_BLOCKS_DIR_PATH . '/dist/style.css' ) ) {
                $css = file_get_contents( ESSENTIAL_BLOCKS_DIR_PATH . '/dist/style.css' );
                // define possible breakpoints
                $all_breakpoints = [
                    '1024' => $breakpoints[ 'tablet' ],
                    '1023' => $breakpoints[ 'tablet' ] - 1,
                    '1025' => $breakpoints[ 'tablet' ] + 1,
                    '767'  => $breakpoints[ 'mobile' ],
                    '768'  => $breakpoints[ 'mobile' ] + 1
                 ];

                // $pattern = '/@media[^{]+\{/';
                // preg_match_all( $pattern, $css, $matches );
                // $modifiedValues = [  ];
                // foreach ( $matches[ 0 ] as $index => $media ) {
                //     $modified = preg_replace_callback( '/:(\d+)px/', function ( $matches ) use ( $all_breakpoints ) {
                //         $newValue = isset( $all_breakpoints[ $matches[ 1 ] ] ) ? $all_breakpoints[ $matches[ 1 ] ] : $matches[ 1 ];
                //         return ":{$newValue}px";
                //     }, $media );
                //     $modifiedValues[  ] = $modified;
                // }
                // $css = str_replace( $matches[ 0 ], $modifiedValues, $css );
                // error_log( $css );

                foreach ( $all_breakpoints as $old => $new ) {
                    $css = preg_replace( "/(@media[^{]+)width:\s*" . preg_quote( $old ) . "px/", "$1width:" . $new . "px", $css, -1, $count );
                }

                if ( ! file_exists( $this->eb_style_dir ) ) {
                    mkdir( $this->eb_style_dir );
                    if ( ! file_exists( $this->eb_style_dir . 'frontend' ) ) {
                        mkdir( $this->eb_style_dir . 'frontend' );
                    }
                    file_put_contents( $this->eb_style_dir . 'frontend/style.css', $css );
                }
            }
        }

        public function reset_breakpoint()
        {
            $frontend_style_dir = $this->eb_style_dir . 'frontend';
            if ( is_dir( $frontend_style_dir ) ) {
                AssetGeneration::remove_directory_files( $frontend_style_dir );
            }
        }
    }

    EbStyleHandler::init();
}
