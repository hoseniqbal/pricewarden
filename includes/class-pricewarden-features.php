<?php
/**
 * PriceWarden: learns each product's normal price, stops checkouts at a wrong price and warns about big drops.
 *
 * @package PriceWarden
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Features and screens.
 */
class PriceWarden_Features {

	const REF     = '_pricewarden_ref';
	const CHANGED = '_pricewarden_changed';
	const ALLOW   = '_pricewarden_allow_until';
	const SCAN    = 'pricewarden_scan';

	/**
	 * Price drops found in this request, emailed once at the end.
	 *
	 * @var array
	 */
	private static $drops = array();

	/* ------------------------------------------------------------------
	 * Frame contract
	 * ---------------------------------------------------------------- */

	/**
	 * Tabs.
	 *
	 * @return array
	 */
	public static function tabs() {
		return array(
			'overview' => __( 'Overview', 'pricewarden' ),
			'check'    => __( 'Price check', 'pricewarden' ),
			'settings' => __( 'Settings', 'pricewarden' ),
		);
	}

	/**
	 * Tagline.
	 *
	 * @return string
	 */
	public static function tagline() {
		return __( 'Nothing leaves your store at the wrong price.', 'pricewarden' );
	}

	/**
	 * Sections that can be saved.
	 *
	 * @return array
	 */
	public static function sections() {
		return array( 'settings' => array( 'block_zero', 'block_drop', 'drop_percent', 'alert_drops', 'alert_percent', 'auto_accept_days', 'notify_to', 'message', 'retention_days', 'delete_data' ) );
	}

	/**
	 * Extra messages.
	 *
	 * @return array
	 */
	public static function messages() {
		return array(
			'accepted' => array( 'success', __( 'New price accepted. It is now the normal price for this product.', 'pricewarden' ) ),
			'free'     => array( 'success', __( 'This product may now be sold at any price, including free.', 'pricewarden' ) ),
			'sale'     => array( 'success', __( 'This sale price is allowed until the sale ends. The normal price stays the same.', 'pricewarden' ) ),
			'scanned'  => array( 'success', __( 'Price check finished.', 'pricewarden' ) ),
		);
	}

	/**
	 * Pro intro.
	 *
	 * @return string
	 */
	public static function pro_intro() {
		return __( 'Alerts on your phone, a price history for every product and a one-click undo.', 'pricewarden' );
	}

	/**
	 * Pro points.
	 *
	 * @return array
	 */
	public static function pro_points() {
		return array(
			__( 'Alerts on Slack, Discord, Telegram or any webhook', 'pricewarden' ),
			__( 'Price history for every product with who changed it', 'pricewarden' ),
			__( 'Restore the previous price in one click', 'pricewarden' ),
			__( 'Never sell below cost, using WooCommerce Cost of Goods', 'pricewarden' ),
			__( 'Price check export to CSV', 'pricewarden' ),
		);
	}

	/**
	 * CSS needed outside our page?
	 *
	 * @param string $hook Hook.
	 * @return bool
	 */
	public static function needs_css( $hook ) {
		unset( $hook );
		return false;
	}

	/**
	 * Install: nothing beyond the log table.
	 *
	 * @return void
	 */
	public static function install() {}

	/**
	 * Activation: record today's prices as the normal prices.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'pricewarden_backfill', array(), 'pricewarden' );
		}
	}

	/* ------------------------------------------------------------------
	 * Hooks
	 * ---------------------------------------------------------------- */

	/**
	 * Runtime hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'woocommerce_after_product_object_save', array( __CLASS__, 'on_save' ), 20, 1 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_cart' ), 20 );
		add_action( 'pricewarden_backfill', array( __CLASS__, 'backfill' ) );
		add_action( 'shutdown', array( __CLASS__, 'send_drops' ) );
	}

	/**
	 * Admin hooks.
	 *
	 * @return void
	 */
	public static function admin_hooks() {
		add_action( 'admin_post_pricewarden_allow', array( __CLASS__, 'handle_allow' ) );
		add_action( 'admin_post_pricewarden_scan', array( __CLASS__, 'handle_scan' ) );
	}

	/**
	 * Hourly: nothing.
	 *
	 * @return void
	 */
	public static function hourly() {}

	/**
	 * Daily: accept price changes that stayed for the chosen number of days, refresh the check.
	 *
	 * @return void
	 */
	public static function daily() {
		$days = (int) PriceWarden_Settings::get( 'auto_accept_days' );
		if ( $days > 0 ) {
			$ids = get_posts(
				array(
					'post_type'      => array( 'product', 'product_variation' ),
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => 500,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- daily job, small result.
						array(
							'key'     => self::CHANGED,
							'value'   => time() - $days * DAY_IN_SECONDS,
							'compare' => '<',
							'type'    => 'NUMERIC',
						),
					),
				)
			);
			foreach ( $ids as $id ) {
				$product = wc_get_product( $id );
				if ( $product ) {
					self::accept( $product, 0 );
				}
			}
		}
		self::scan();
	}

	/* ------------------------------------------------------------------
	 * Normal price
	 * ---------------------------------------------------------------- */

	/**
	 * The product's list price (regular, or current price when there is no regular price).
	 *
	 * @param WC_Product $product Product.
	 * @return float
	 */
	public static function list_price( $product ) {
		$regular = $product->get_regular_price( 'edit' );
		return '' !== $regular ? (float) $regular : (float) $product->get_price( 'edit' );
	}

	/**
	 * Normal price, learned on first sight.
	 *
	 * @param WC_Product $product Product.
	 * @return float
	 */
	public static function ref( $product ) {
		$ref = get_post_meta( $product->get_id(), self::REF, true );
		if ( '' === $ref ) {
			$ref = self::list_price( $product );
			if ( $ref > 0 ) {
				update_post_meta( $product->get_id(), self::REF, wc_format_decimal( $ref ) );
			}
		}
		return (float) $ref;
	}

	/**
	 * Accept the current list price as normal.
	 *
	 * @param WC_Product $product Product.
	 * @param int        $user    User who accepted, 0 for automatic.
	 * @return void
	 */
	public static function accept( $product, $user ) {
		$old = get_post_meta( $product->get_id(), self::REF, true );
		$new = self::list_price( $product );
		update_post_meta( $product->get_id(), self::REF, wc_format_decimal( $new ) );
		delete_post_meta( $product->get_id(), self::CHANGED );
		PriceWarden_Log::add(
			'accepted',
			array(
				'object_id' => $product->get_id(),
				'user_id'   => $user,
				'field'     => 'price',
				'old'       => $old,
				'new'       => $new,
				'detail'    => $user ? 'manual' : 'automatic',
			)
		);
	}

	/**
	 * Product name for messages, with the variation.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private static function name( $product ) {
		return wp_strip_all_tags( $product->get_name() );
	}

	/**
	 * After any product or variation save: learn rises, flag drops.
	 *
	 * @param WC_Product $product Product.
	 * @return void
	 */
	public static function on_save( $product ) {
		if ( ! $product instanceof WC_Product || ! $product->get_id() || $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) ) {
			return;
		}
		$id   = $product->get_id();
		$have = get_post_meta( $id, self::REF, true );
		$list = self::list_price( $product );
		if ( '' === $have ) {
			if ( $list > 0 ) {
				update_post_meta( $id, self::REF, wc_format_decimal( $list ) );
			}
			return;
		}
		$ref = (float) $have;
		if ( $list > $ref + 0.0001 ) {
			self::accept( $product, get_current_user_id() );
			$ref = $list;
		} elseif ( $list < $ref - 0.0001 ) {
			if ( ! get_post_meta( $id, self::CHANGED, true ) ) {
				update_post_meta( $id, self::CHANGED, time() );
				PriceWarden_Log::add(
					'price_change',
					array(
						'object_id' => $id,
						'field'     => 'price',
						'old'       => $ref,
						'new'       => $list,
						'detail'    => self::name( $product ),
					)
				);
			}
		} else {
			delete_post_meta( $id, self::CHANGED );
		}
		$sale = $product->get_sale_price( 'edit' );
		if ( '' === $sale && (int) get_post_meta( $id, self::ALLOW, true ) < 4102444800 ) {
			// A sale allowance ends with the sale.
			delete_post_meta( $id, self::ALLOW );
		}
		$now = '' !== $sale ? min( $list, (float) $sale ) : $list;
		$pct = $ref > 0 ? 100 * ( $ref - $now ) / $ref : 0;
		if ( ! PriceWarden_Settings::on( 'alert_drops' ) || $ref <= 0 || ( $now > 0 && $pct < (int) PriceWarden_Settings::get( 'alert_percent' ) ) ) {
			delete_post_meta( $id, '_pricewarden_alerted' );
			return;
		}
		if ( (string) get_post_meta( $id, '_pricewarden_alerted', true ) === (string) wc_format_decimal( $now ) ) {
			return;
		}
		update_post_meta( $id, '_pricewarden_alerted', wc_format_decimal( $now ) );
		PriceWarden_Log::add(
			'price_drop',
			array(
				'object_id' => $id,
				'field'     => 'price',
				'old'       => $ref,
				'new'       => $now,
				'detail'    => self::name( $product ),
			)
		);
		self::$drops[ $id ] = array(
			'name' => self::name( $product ),
			'ref'  => $ref,
			'now'  => $now,
			'by'   => wp_get_current_user()->exists() ? wp_get_current_user()->display_name : __( 'an import or another plugin', 'pricewarden' ),
		);
	}

	/**
	 * Allow link for emails and the check page.
	 *
	 * @param int    $id   Product ID.
	 * @param string $mode accept or free.
	 * @return string
	 */
	public static function allow_url( $id, $mode = 'accept' ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'pricewarden_allow',
					'id'     => (int) $id,
					'mode'   => $mode,
				),
				admin_url( 'admin-post.php' )
			),
			'pricewarden_allow_' . (int) $id
		);
	}

	/**
	 * Email drops found in this request.
	 *
	 * @return void
	 */
	public static function send_drops() {
		if ( empty( self::$drops ) ) {
			return;
		}
		$lines = array();
		foreach ( self::$drops as $id => $d ) {
			/* translators: 1: product, 2: normal price, 3: new price, 4: who */
			$lines[] = sprintf( __( '%1$s: normal price %2$s, now %3$s (changed by %4$s).', 'pricewarden' ), $d['name'], wp_strip_all_tags( wc_price( $d['ref'] ) ), wp_strip_all_tags( wc_price( $d['now'] ) ), $d['by'] )
				. "\n" . __( 'Edit:', 'pricewarden' ) . ' ' . get_edit_post_link( wp_get_post_parent_id( $id ) ? wp_get_post_parent_id( $id ) : $id, 'raw' )
				. "\n" . __( 'The new price is right:', 'pricewarden' ) . ' ' . self::allow_url( $id );
		}
		self::$drops = array();
		$site        = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$body        = __( 'These prices just dropped a lot. Until you confirm them, checkouts are stopped when the price is far below normal.', 'pricewarden' ) . "\n\n" . implode( "\n\n", $lines );
		/* translators: %s: site name */
		self::mail( sprintf( __( 'Price drop on %s: please check', 'pricewarden' ), $site ), $body );
	}

	/**
	 * Send an email to the owner from the store's address.
	 *
	 * @param string $subject Subject.
	 * @param string $body    Plain text.
	 * @return bool
	 */
	public static function mail( $subject, $body ) {
		$to      = PriceWarden_Settings::get( 'notify_to' );
		$to      = '' !== $to ? array_map( 'trim', explode( ',', $to ) ) : array( get_option( 'admin_email' ) );
		$headers = array();
		$from    = get_option( 'woocommerce_email_from_address' );
		if ( is_email( $from ) ) {
			$site      = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			$headers[] = 'From: ' . str_replace( array( "\r", "\n", '<', '>' ), '', (string) get_option( 'woocommerce_email_from_name', $site ) ) . ' <' . $from . '>';
		}
		return wp_mail( $to, $subject, $body, $headers );
	}

	/* ------------------------------------------------------------------
	 * Checkout guard
	 * ---------------------------------------------------------------- */

	/**
	 * Problem with one line, or ''.
	 *
	 * @param WC_Product $product Product.
	 * @param float      $price   Price in the cart.
	 * @return string zero|drop|''
	 */
	public static function problem( $product, $price ) {
		$id = $product->get_id();
		if ( (int) get_post_meta( $id, self::ALLOW, true ) > time() ) {
			return '';
		}
		$ref     = self::ref( $product );
		$problem = '';
		if ( PriceWarden_Settings::on( 'block_zero' ) && $price <= 0 && $ref > 0 ) {
			$problem = 'zero';
		} elseif ( PriceWarden_Settings::on( 'block_drop' ) && $ref > 0 && $price < $ref * ( 1 - (int) PriceWarden_Settings::get( 'drop_percent' ) / 100 ) ) {
			$problem = 'drop';
		}
		/**
		 * Decide whether a cart line is sold at a wrong price. Return '' to allow.
		 *
		 * @param string     $problem zero, drop or ''.
		 * @param WC_Product $product Product.
		 * @param float      $price   Price in the cart.
		 * @param float      $ref     Normal price.
		 */
		return (string) apply_filters( 'pricewarden_line_problem', $problem, $product, $price, $ref );
	}

	/**
	 * Check the cart (classic cart, classic checkout and block checkout all run this).
	 *
	 * @return void
	 */
	public static function check_cart() {
		if ( ! WC()->cart || current_user_can( 'manage_woocommerce' ) && ! apply_filters( 'pricewarden_check_staff', false ) ) {
			return;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			$product = isset( $item['data'] ) ? $item['data'] : null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$price   = (float) $product->get_price();
			$problem = self::problem( $product, $price );
			if ( '' === $problem ) {
				continue;
			}
			wc_add_notice( str_replace( '%s', self::name( $product ), (string) PriceWarden_Settings::get( 'message' ) ), 'error' );
			$key = 'pricewarden_seen_' . $product->get_id();
			if ( ! get_transient( $key ) ) {
				set_transient( $key, 1, HOUR_IN_SECONDS );
				$ref = self::ref( $product );
				PriceWarden_Log::add(
					'blocked',
					array(
						'object_id' => $product->get_id(),
						'user_id'   => 0,
						'field'     => $problem,
						'old'       => $ref,
						'new'       => $price,
						'amount'    => max( 0, $ref - $price ) * (int) $item['quantity'],
						'detail'    => self::name( $product ),
					)
				);
				$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
				self::mail(
					/* translators: %s: site name */
					sprintf( __( 'Checkout stopped on %s: wrong price', 'pricewarden' ), $site ),
					sprintf(
						/* translators: 1: product, 2: price, 3: normal price */
						__( 'A customer tried to buy %1$s at %2$s. Its normal price is %3$s, so PriceWarden stopped the checkout.', 'pricewarden' ),
						self::name( $product ),
						wp_strip_all_tags( wc_price( $price ) ),
						wp_strip_all_tags( wc_price( $ref ) )
					) . "\n\n" . __( 'If this price is right:', 'pricewarden' ) . ' ' . self::allow_url( $product->get_id() )
				);
			}
		}
	}

	/* ------------------------------------------------------------------
	 * Price check
	 * ---------------------------------------------------------------- */

	/**
	 * Fill in normal prices for products that have none, 200 at a time.
	 *
	 * @return void
	 */
	public static function backfill() {
		$ids = get_posts(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'fields'         => 'ids',
				'posts_per_page' => 200,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-time background job.
					array(
						'key'     => self::REF,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		$set = 0;
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product && ! $product->is_type( 'variable' ) && ! $product->is_type( 'grouped' ) ) {
				$price = self::list_price( $product );
				update_post_meta( $id, self::REF, $price > 0 ? wc_format_decimal( $price ) : '0' );
				++$set;
			} else {
				update_post_meta( $id, self::REF, '0' );
			}
		}
		if ( 200 === count( $ids ) && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'pricewarden_backfill', array(), 'pricewarden' );
		} else {
			self::scan();
		}
	}

	/**
	 * Look for price problems in the catalogue.
	 *
	 * @return array
	 */
	public static function scan() {
		$issues = array();
		$page   = 1;
		$seen   = 0;
		do {
			$ids = get_posts(
				array(
					'post_type'      => array( 'product', 'product_variation' ),
					'post_status'    => 'publish',
					'fields'         => 'ids',
					'posts_per_page' => 200,
					'paged'          => $page,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);
			foreach ( $ids as $id ) {
				++$seen;
				$product = wc_get_product( $id );
				if ( ! $product || $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) || $product->is_type( 'external' ) ) {
					continue;
				}
				if ( $product->is_type( 'variation' ) && 'publish' !== get_post_status( $product->get_parent_id() ) ) {
					continue;
				}
				$regular = $product->get_regular_price( 'edit' );
				$sale    = $product->get_sale_price( 'edit' );
				$price   = (float) $product->get_price( 'edit' );
				$ref     = (float) get_post_meta( $id, self::REF, true );
				$allowed = (int) get_post_meta( $id, self::ALLOW, true ) > time();
				$kind    = '';
				if ( '' === $regular && '' === $product->get_price( 'edit' ) ) {
					$kind = 'no_price';
				} elseif ( $price <= 0 && ! $allowed ) {
					$kind = 'zero';
				} elseif ( '' !== $sale && '' !== $regular && (float) $sale >= (float) $regular ) {
					$kind = 'sale_high';
				} elseif ( $ref > 0 && ! $allowed && $price < $ref * ( 1 - (int) PriceWarden_Settings::get( 'drop_percent' ) / 100 ) ) {
					$kind = 'below';
				}
				if ( '' !== $kind ) {
					$issues[] = array(
						'id'   => $id,
						'kind' => $kind,
						'ref'  => $ref,
						'now'  => $price,
					);
				}
				if ( count( $issues ) >= 300 ) {
					break 2;
				}
			}
			++$page;
		} while ( count( $ids ) === 200 && $seen < 20000 );
		$result = array(
			'time'    => time(),
			'checked' => $seen,
			'issues'  => $issues,
		);
		update_option( self::SCAN, $result, false );
		return $result;
	}

	/* ------------------------------------------------------------------
	 * Actions
	 * ---------------------------------------------------------------- */

	/**
	 * Accept a price or allow any price.
	 *
	 * @return void
	 */
	public static function handle_allow() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'pricewarden' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'pricewarden_allow_' . $id );
		$product = wc_get_product( $id );
		if ( ! $product ) {
			wp_die( esc_html__( 'Product not found.', 'pricewarden' ) );
		}
		$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : 'accept';
		if ( 'free' === $mode ) {
			update_post_meta( $id, self::ALLOW, 4102444800 );
			PriceWarden_Log::add(
				'allowed',
				array(
					'object_id' => $id,
					'detail'    => self::name( $product ),
				)
			);
		} else {
			$ref   = self::ref( $product );
			$floor = $ref * ( 1 - (int) PriceWarden_Settings::get( 'drop_percent' ) / 100 );
			$sale  = $product->get_sale_price( 'edit' );
			if ( '' !== $sale && (float) $sale < $floor && self::list_price( $product ) >= $floor ) {
				// A sale on purpose: allow it until the sale ends (or 30 days), keep the normal price.
				$end   = $product->get_date_on_sale_to( 'edit' );
				$until = $end ? $end->getTimestamp() : time() + 30 * DAY_IN_SECONDS;
				update_post_meta( $id, self::ALLOW, $until );
				$mode = 'sale';
				PriceWarden_Log::add(
					'allowed',
					array(
						'object_id' => $id,
						'old'       => $ref,
						'new'       => $sale,
						'detail'    => 'sale until ' . gmdate( 'Y-m-d', $until ),
					)
				);
			} else {
				self::accept( $product, get_current_user_id() );
			}
		}
		self::scan();
		PriceWarden_Admin::back( 'check', in_array( $mode, array( 'free', 'sale' ), true ) ? $mode : 'accepted' );
	}

	/**
	 * Run the price check now.
	 *
	 * @return void
	 */
	public static function handle_scan() {
		PriceWarden_Admin::guard( 'pricewarden_scan' );
		self::scan();
		PriceWarden_Admin::back( 'check', 'scanned' );
	}

	/* ------------------------------------------------------------------
	 * Screens
	 * ---------------------------------------------------------------- */

	/**
	 * Labels for events and issues.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function label( $key ) {
		$labels = array(
			'blocked'      => __( 'Checkout stopped', 'pricewarden' ),
			'price_drop'   => __( 'Big price drop', 'pricewarden' ),
			'price_change' => __( 'Price lowered', 'pricewarden' ),
			'accepted'     => __( 'New price accepted', 'pricewarden' ),
			'allowed'      => __( 'Lower price allowed', 'pricewarden' ),
			'zero'         => __( 'Price is zero', 'pricewarden' ),
			'no_price'     => __( 'No price set', 'pricewarden' ),
			'sale_high'    => __( 'Sale price not lower than regular', 'pricewarden' ),
			'below'        => __( 'Far below normal price', 'pricewarden' ),
			'drop'         => __( 'Far below normal price', 'pricewarden' ),
		);
		return isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
	}

	/**
	 * Watched products.
	 *
	 * @return int
	 */
	private static function watched() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- quick count for the dashboard.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value > 0", self::REF ) );
	}

	/**
	 * Product link (escaped HTML).
	 *
	 * @param int $id Product or variation ID.
	 * @return string
	 */
	private static function product_link( $id ) {
		$product = wc_get_product( $id );
		if ( ! $product ) {
			/* translators: %d: product ID */
			return esc_html( sprintf( __( 'Product %d (deleted)', 'pricewarden' ), $id ) );
		}
		$edit = get_edit_post_link( $product->get_parent_id() ? $product->get_parent_id() : $id );
		return '<a href="' . esc_url( (string) $edit ) . '">' . esc_html( self::name( $product ) ) . '</a>';
	}

	/**
	 * Overview.
	 *
	 * @return void
	 */
	public static function render_overview() {
		$scan    = get_option( self::SCAN, array() );
		$issues  = isset( $scan['issues'] ) ? count( $scan['issues'] ) : 0;
		$blocked = PriceWarden_Log::count( 'blocked', WEEK_IN_SECONDS );
		$on      = PriceWarden_Settings::on( 'block_zero' ) || PriceWarden_Settings::on( 'block_drop' );
		if ( ! $on ) {
			PriceWarden_Admin::banner( 'is-off', __( 'Checkout protection is off', 'pricewarden' ), __( 'Turn it on in Settings so wrong prices cannot be bought.', 'pricewarden' ) );
		} elseif ( $issues ) {
			/* translators: %s: number of products */
			PriceWarden_Admin::banner( 'is-learning', __( 'Some prices need a look', 'pricewarden' ), sprintf( _n( '%s product has a price that looks wrong. Open Price check.', '%s products have a price that looks wrong. Open Price check.', $issues, 'pricewarden' ), number_format_i18n( $issues ) ) );
		} else {
			PriceWarden_Admin::banner( 'is-on', __( 'Your prices look right', 'pricewarden' ), __( 'PriceWarden knows the normal price of every product and stops any checkout at a price far below it.', 'pricewarden' ) );
		}
		PriceWarden_Admin::figures(
			array(
				__( 'Products watched', 'pricewarden' )         => number_format_i18n( self::watched() ),
				__( 'Checkouts stopped, 7 days', 'pricewarden' ) => number_format_i18n( $blocked ),
				__( 'Money saved, 7 days', 'pricewarden' )       => wp_strip_all_tags( wc_price( PriceWarden_Log::sum( 'blocked', WEEK_IN_SECONDS ) ) ),
				__( 'Prices to check', 'pricewarden' )           => number_format_i18n( $issues ),
			)
		);
		$rows = array();
		foreach ( PriceWarden_Log::recent( array( 'types' => array( 'blocked', 'price_drop', 'accepted', 'allowed' ), 'limit' => 20 ) ) as $row ) {
			$user   = $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : null;
			$rows[] = array(
				'when'         => PriceWarden_Admin::ago( $row['created_at'] ),
				'html_what'    => PriceWarden_Admin::tag( 'blocked' === $row['type'] || 'price_drop' === $row['type'] ? 'is-blocked' : 'is-ok', self::label( $row['type'] ) ),
				'html_product' => self::product_link( (int) $row['object_id'] ),
				'prices'       => ( '' !== $row['old_value'] ? wp_strip_all_tags( wc_price( (float) $row['old_value'] ) ) . ' > ' : '' ) . ( '' !== $row['new_value'] ? wp_strip_all_tags( wc_price( (float) $row['new_value'] ) ) : '' ),
				'by'           => $user ? $user->display_name : ( 'blocked' === $row['type'] ? __( 'Customer', 'pricewarden' ) : __( 'Automatic', 'pricewarden' ) ),
			);
		}
		PriceWarden_Admin::panel_open( __( 'Recent activity', 'pricewarden' ) );
		if ( $rows ) {
			PriceWarden_Admin::table(
				array(
					'when'         => __( 'When', 'pricewarden' ),
					'html_what'    => __( 'What', 'pricewarden' ),
					'html_product' => __( 'Product', 'pricewarden' ),
					'prices'       => __( 'Normal > new', 'pricewarden' ),
					'by'           => __( 'By', 'pricewarden' ),
				),
				$rows
			);
		} else {
			PriceWarden_Admin::empty_state( __( 'Nothing yet', 'pricewarden' ), __( 'Stopped checkouts and big price drops show up here.', 'pricewarden' ) );
		}
		PriceWarden_Admin::panel_close();
	}

	/**
	 * Price check.
	 *
	 * @return void
	 */
	public static function render_check() {
		$scan = get_option( self::SCAN, array() );
		$time = isset( $scan['time'] ) ? (int) $scan['time'] : 0;
		PriceWarden_Admin::panel_open(
			__( 'Price check', 'pricewarden' ),
			$time
				/* translators: 1: number of products, 2: time difference */
				? sprintf( __( '%1$s products checked %2$s ago. Runs every day.', 'pricewarden' ), number_format_i18n( (int) $scan['checked'] ), human_time_diff( $time ) )
				: __( 'Not run yet.', 'pricewarden' )
		);
		$rows = array();
		foreach ( isset( $scan['issues'] ) ? array_slice( $scan['issues'], 0, 100 ) : array() as $issue ) {
			$actions = '';
			if ( in_array( $issue['kind'], array( 'below', 'zero' ), true ) ) {
				$actions .= '<a class="button" href="' . esc_url( self::allow_url( $issue['id'] ) ) . '">' . esc_html__( 'Price is right', 'pricewarden' ) . '</a> ';
			}
			if ( 'zero' === $issue['kind'] ) {
				$actions .= '<a class="button" href="' . esc_url( self::allow_url( $issue['id'], 'free' ) ) . '">' . esc_html__( 'Free on purpose', 'pricewarden' ) . '</a>';
			}
			$rows[] = array(
				'html_product' => self::product_link( (int) $issue['id'] ),
				'html_issue'   => PriceWarden_Admin::tag( 'sale_high' === $issue['kind'] ? 'is-would' : 'is-blocked', self::label( $issue['kind'] ) ),
				'normal'       => $issue['ref'] > 0 ? wp_strip_all_tags( wc_price( $issue['ref'] ) ) : '',
				'now'          => wp_strip_all_tags( wc_price( $issue['now'] ) ),
				'html_actions' => $actions,
			);
		}
		if ( $rows ) {
			PriceWarden_Admin::table(
				array(
					'html_product' => __( 'Product', 'pricewarden' ),
					'html_issue'   => __( 'Problem', 'pricewarden' ),
					'normal'       => __( 'Normal price', 'pricewarden' ),
					'now'          => __( 'Price now', 'pricewarden' ),
					'html_actions' => __( 'Action', 'pricewarden' ),
				),
				$rows
			);
		} else {
			PriceWarden_Admin::empty_state( __( 'No price problems found', 'pricewarden' ), __( 'Zero prices, missing prices, sale prices above regular and prices far below normal are listed here.', 'pricewarden' ) );
		}
		PriceWarden_Admin::panel_close();
		echo '<div class="pricewarden-actions">';
		PriceWarden_Admin::button( 'pricewarden_scan', __( 'Check all prices now', 'pricewarden' ), array(), 'pricewarden-btn' );
		echo '</div>';
	}

	/**
	 * Settings.
	 *
	 * @return void
	 */
	public static function render_settings() {
		$a = 'PriceWarden_Admin';
		$a::form_open( 'settings' );
		$a::panel_open( __( 'Checkout protection', 'pricewarden' ) );
		$a::row(
			__( 'Stop wrong prices', 'pricewarden' ),
			__( 'Applies to the cart, the classic checkout and the block checkout. Staff are not stopped.', 'pricewarden' ),
			static function () use ( $a ) {
				$a::switch_field( 'block_zero', __( 'Stop products that cost 0 by mistake', 'pricewarden' ) );
				$a::switch_field( 'block_drop', __( 'Stop products far below their normal price', 'pricewarden' ) );
				/* translators: %1$s: percent */
				$a::sentence( __( 'Far below means more than %1$s%% under the normal price.', 'pricewarden' ), array( 'drop_percent' => __( 'Percent', 'pricewarden' ) ) );
			}
		);
		$a::row(
			__( 'Message to customers', 'pricewarden' ),
			__( 'Use %s for the product name.', 'pricewarden' ),
			static function () use ( $a ) {
				$a::text( 'message', __( 'Message', 'pricewarden' ) );
			}
		);
		$a::panel_close();
		$a::panel_open( __( 'Price drop alerts', 'pricewarden' ) );
		$a::row(
			__( 'Email me', 'pricewarden' ),
			__( 'Sent when a product is saved, imported or bulk edited with a much lower price.', 'pricewarden' ),
			static function () use ( $a ) {
				$a::switch_field( 'alert_drops', __( 'Email me about big price drops', 'pricewarden' ) );
				/* translators: %1$s: percent */
				$a::sentence( __( 'A big drop is %1$s%% or more.', 'pricewarden' ), array( 'alert_percent' => __( 'Percent', 'pricewarden' ) ) );
				$a::text( 'notify_to', __( 'Email addresses', 'pricewarden' ), get_option( 'admin_email' ) );
			}
		);
		$a::row(
			__( 'Normal price', 'pricewarden' ),
			__( 'Price rises are accepted at once. Lower prices become normal when you confirm them, or after the days below.', 'pricewarden' ),
			static function () use ( $a ) {
				/* translators: %1$s: days */
				$a::sentence( __( 'Accept a lower price automatically after %1$s days (0 means never).', 'pricewarden' ), array( 'auto_accept_days' => __( 'Days', 'pricewarden' ) ) );
			}
		);
		$a::panel_close();
		$a::panel_open( __( 'Data', 'pricewarden' ) );
		$a::row(
			__( 'History', 'pricewarden' ),
			'',
			static function () use ( $a ) {
				/* translators: %1$s: days */
				$a::sentence( __( 'Keep the activity log for %1$s days.', 'pricewarden' ), array( 'retention_days' => __( 'Days', 'pricewarden' ) ) );
				$a::switch_field( 'delete_data', __( 'Delete settings and history when the plugin is deleted', 'pricewarden' ) );
			}
		);
		$a::panel_close();
		$a::form_close();
	}
}
