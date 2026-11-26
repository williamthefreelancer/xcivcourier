<?php
/**
 * Sitewide breadcrumbs (all views except the front page).
 *
 * @package XCIV_Courier
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Raw page title without WooCommerce endpoint title filters.
 */
function xciv_breadcrumbs_page_title( int $page_id ): string {
	if ( $page_id <= 0 ) {
		return '';
	}

	$title = get_post_field( 'post_title', $page_id );
	return is_string( $title ) ? $title : '';
}

/**
 * Front-page URL for the Home crumb (static front page when set).
 */
function xciv_breadcrumbs_home_url(): string {
	$front_id = (int) get_option( 'page_on_front' );
	if ( get_option( 'show_on_front' ) === 'page' && $front_id > 0 ) {
		$url = get_permalink( $front_id );
		if ( is_string( $url ) && $url !== '' ) {
			return $url;
		}
	}

	return home_url( '/' );
}

/**
 * WooCommerce page crumb helpers.
 *
 * @return array{0:string,1:string}|null Label + URL, or null if unavailable.
 */
function xciv_breadcrumbs_woo_page( string $page ): ?array {
	if ( ! function_exists( 'wc_get_page_id' ) ) {
		return null;
	}

	$page_id = (int) wc_get_page_id( $page );
	if ( $page_id <= 0 ) {
		return null;
	}

	$defaults = array(
		'shop'      => __( 'Shop', 'xciv-courier' ),
		'cart'      => __( 'Cart', 'xciv-courier' ),
		'checkout'  => __( 'Checkout', 'xciv-courier' ),
		'myaccount' => __( 'My account', 'xciv-courier' ),
	);

	$label = xciv_breadcrumbs_page_title( $page_id );
	if ( $label === '' ) {
		$label = $defaults[ $page ] ?? get_the_title( $page_id );
	}

	$url = function_exists( 'wc_get_page_permalink' )
		? (string) wc_get_page_permalink( $page )
		: (string) get_permalink( $page_id );

	if ( $url === '' ) {
		return null;
	}

	return array( $label, $url );
}

/**
 * Whether breadcrumbs should render on the current request.
 */
function xciv_breadcrumbs_should_show(): bool {
	if ( is_admin() || is_front_page() || wp_is_json_request() ) {
		return false;
	}

	return true;
}

/**
 * Build breadcrumb items: each is [ label, url|null ].
 *
 * @return list<array{0:string,1:?string}>
 */
function xciv_breadcrumbs_items(): array {
	$home_label = __( 'Home', 'xciv-courier' );
	$items      = array(
		array( $home_label, xciv_breadcrumbs_home_url() ),
	);

	if ( is_404() ) {
		$items[] = array( __( 'Page not found', 'xciv-courier' ), null );
		return $items;
	}

	if ( is_search() ) {
		/* translators: %s: search query */
		$items[] = array( sprintf( __( 'Search: %s', 'xciv-courier' ), get_search_query() ), null );
		return $items;
	}

	if ( function_exists( 'is_shop' ) && is_shop() ) {
		$shop = xciv_breadcrumbs_woo_page( 'shop' );
		$items[] = array( $shop[0] ?? __( 'Shop', 'xciv-courier' ), null );
		return $items;
	}

	if ( function_exists( 'is_product_category' ) && ( is_product_category() || is_product_tag() || is_product_taxonomy() ) ) {
		$shop = xciv_breadcrumbs_woo_page( 'shop' );
		if ( $shop ) {
			$items[] = $shop;
		}
		$term = get_queried_object();
		if ( $term instanceof WP_Term ) {
			$items[] = array( $term->name, null );
		}
		return $items;
	}

	if ( function_exists( 'is_product' ) && is_product() ) {
		$shop = xciv_breadcrumbs_woo_page( 'shop' );
		if ( $shop ) {
			$items[] = $shop;
		}
		$items[] = array( get_the_title(), null );
		return $items;
	}

	if ( function_exists( 'is_cart' ) && is_cart() ) {
		$shop = xciv_breadcrumbs_woo_page( 'shop' );
		if ( $shop ) {
			$items[] = $shop;
		}
		$cart = xciv_breadcrumbs_woo_page( 'cart' );
		$items[] = array( $cart[0] ?? __( 'Cart', 'xciv-courier' ), null );
		return $items;
	}

	if ( function_exists( 'is_checkout' ) && is_checkout() ) {
		$cart = xciv_breadcrumbs_woo_page( 'cart' );
		if ( $cart ) {
			$items[] = $cart;
		}

		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			$checkout = xciv_breadcrumbs_woo_page( 'checkout' );
			if ( $checkout ) {
				$items[] = $checkout;
			}
			$items[] = array( __( 'Order received', 'xciv-courier' ), null );
			return $items;
		}

		$checkout = xciv_breadcrumbs_woo_page( 'checkout' );
		$items[]  = array( $checkout[0] ?? __( 'Checkout', 'xciv-courier' ), null );
		return $items;
	}

	if ( function_exists( 'is_account_page' ) && is_account_page() ) {
		$account = xciv_breadcrumbs_woo_page( 'myaccount' );
		$account_label = $account[0] ?? __( 'My account', 'xciv-courier' );
		$account_url   = $account[1] ?? xciv_breadcrumbs_home_url();

		$endpoint = function_exists( 'WC' ) && WC()->query ? WC()->query->get_current_endpoint() : '';
		if ( $endpoint === '' ) {
			$items[] = array( $account_label, null );
			return $items;
		}

		$items[] = array( $account_label, $account_url );

		$endpoint_labels = array(
			'orders'          => __( 'Orders', 'xciv-courier' ),
			'view-order'      => __( 'Order', 'xciv-courier' ),
			'downloads'       => __( 'Downloads', 'xciv-courier' ),
			'edit-address'    => __( 'Addresses', 'xciv-courier' ),
			'edit-account'    => __( 'Account details', 'xciv-courier' ),
			'payment-methods' => __( 'Payment methods', 'xciv-courier' ),
			'customer-logout' => __( 'Log out', 'xciv-courier' ),
		);

		$label   = $endpoint_labels[ $endpoint ] ?? ucwords( str_replace( '-', ' ', $endpoint ) );
		$items[] = array( $label, null );
		return $items;
	}

	if ( is_singular() ) {
		$post = get_queried_object();
		if ( $post instanceof WP_Post && $post->post_type === 'page' && $post->post_parent ) {
			$ancestors = array_reverse( get_post_ancestors( $post ) );
			foreach ( $ancestors as $ancestor_id ) {
				$items[] = array( xciv_breadcrumbs_page_title( (int) $ancestor_id ), get_permalink( $ancestor_id ) );
			}
		}
		$current = $post instanceof WP_Post ? xciv_breadcrumbs_page_title( (int) $post->ID ) : get_the_title();
		$items[] = array( $current !== '' ? $current : get_the_title(), null );
		return $items;
	}

	if ( is_home() ) {
		$posts_page = (int) get_option( 'page_for_posts' );
		$label      = $posts_page > 0 ? xciv_breadcrumbs_page_title( $posts_page ) : __( 'Blog', 'xciv-courier' );
		$items[]    = array( $label !== '' ? $label : __( 'Blog', 'xciv-courier' ), null );
		return $items;
	}

	if ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();
		if ( $term instanceof WP_Term ) {
			$items[] = array( $term->name, null );
		}
		return $items;
	}

	if ( is_author() ) {
		$author = get_queried_object();
		$label  = $author instanceof WP_User ? $author->display_name : __( 'Author', 'xciv-courier' );
		$items[] = array( $label, null );
		return $items;
	}

	if ( is_date() ) {
		$items[] = array( get_the_archive_title(), null );
		return $items;
	}

	$title = get_the_title();
	if ( is_string( $title ) && $title !== '' ) {
		$items[] = array( wp_strip_all_tags( $title ), null );
	}

	return $items;
}

/**
 * Render breadcrumb HTML.
 */
function xciv_breadcrumbs_render(): string {
	if ( ! xciv_breadcrumbs_should_show() ) {
		return '';
	}

	$items = xciv_breadcrumbs_items();
	if ( count( $items ) < 2 ) {
		return '';
	}

	ob_start();
	?>
	<nav class="xciv-breadcrumbs" aria-label="<?php esc_attr_e( 'Breadcrumb', 'xciv-courier' ); ?>">
		<div class="xciv-breadcrumbs__inner">
			<ol class="xciv-breadcrumbs__list">
				<?php foreach ( $items as $index => $item ) : ?>
					<?php
					$label = (string) $item[0];
					$url   = $item[1];
					$last  = $index === count( $items ) - 1;
					?>
					<li class="xciv-breadcrumbs__item<?php echo $last ? ' is-current' : ''; ?>">
						<?php if ( ! $last && is_string( $url ) && $url !== '' ) : ?>
							<a class="xciv-breadcrumbs__link" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
						<?php else : ?>
							<span class="xciv-breadcrumbs__current"<?php echo $last ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>
	</nav>
	<?php
	return (string) ob_get_clean();
}

/**
 * Append breadcrumbs after the site header template part.
 *
 * @param mixed $block_content Block HTML.
 * @param mixed $block         Parsed block.
 */
function xciv_breadcrumbs_after_header( $block_content, $block = array() ): string {
	$block_content = is_string( $block_content ) ? $block_content : '';
	$block         = is_array( $block ) ? $block : array();

	if ( ( $block['blockName'] ?? '' ) !== 'core/template-part' ) {
		return $block_content;
	}

	if ( (string) ( $block['attrs']['slug'] ?? '' ) !== 'header' ) {
		return $block_content;
	}

	// No breadcrumbs on the front page (marketing home).
	if ( is_front_page() || $block_content === '' ) {
		return $block_content;
	}

	return $block_content . xciv_breadcrumbs_render();
}
add_filter( 'render_block', 'xciv_breadcrumbs_after_header', 20, 2 );

/**
 * Hide WooCommerce / block breadcrumbs so we only show one trail.
 *
 * @param mixed $block_content Block HTML.
 * @param mixed $block         Parsed block.
 */
function xciv_breadcrumbs_suppress_woo_blocks( $block_content, $block = array() ): string {
	$block_content = is_string( $block_content ) ? $block_content : '';
	$block         = is_array( $block ) ? $block : array();
	$name          = (string) ( $block['blockName'] ?? '' );
	if ( $name === 'woocommerce/breadcrumbs' ) {
		return '';
	}
	return $block_content;
}
add_filter( 'render_block', 'xciv_breadcrumbs_suppress_woo_blocks', 10, 2 );

/**
 * Remove classic WooCommerce breadcrumb hook when present.
 */
function xciv_breadcrumbs_remove_woo_classic(): void {
	remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );
}
add_action( 'init', 'xciv_breadcrumbs_remove_woo_classic', 20 );
