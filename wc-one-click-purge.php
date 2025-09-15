<?php
/**
 * Plugin Name: WC One-Click Purge
 * Description: One button to delete ALL WooCommerce orders (HPOS-safe) and ALL customer/subscriber users in batches.
 * Version: 1.0.0
 * Author: Digova
 * License: GPL-2.0+
 */
defined('ABSPATH') || exit;

add_action('admin_menu', function () {
  add_management_page('One-Click WC Purge', 'One-Click WC Purge', 'manage_woocommerce', 'wc-one-click-purge', function () {
    if ( ! class_exists('WooCommerce') ) {
      echo '<div class="notice notice-error"><p>WooCommerce must be active.</p></div>';
      return;
    }
    $url = wp_nonce_url( admin_url('admin-post.php?action=wc_one_click_purge'), 'wc_one_click_purge' );
    echo '<div class="wrap"><h1>One-Click WC Purge</h1>';
    echo '<p>This will delete <strong>ALL orders</strong> and <strong>ALL customer/subscriber users</strong>. Back up first.</p>';
    echo '<a href="'.esc_url($url).'" class="button button-primary button-large">Run Purge</a></div>';
  });
});

add_action('admin_post_wc_one_click_purge', function () {
  if ( ! current_user_can('manage_woocommerce') ) wp_die('Permission denied');
  check_admin_referer('wc_one_click_purge');
  if ( function_exists('ignore_user_abort') ) ignore_user_abort(true);
  if ( function_exists('set_time_limit') ) @set_time_limit(0);

  $B = 200;

  // 1) Orders (HPOS-safe)
  do {
    $ids = wc_get_orders([
      'type'   => 'shop_order',
      'status' => array_keys( wc_get_order_statuses() ),
      'limit'  => $B,
      'return' => 'ids',
    ]);
    foreach ( $ids as $id ) {
      if ( $o = wc_get_order($id) ) $o->delete(true);
    }
    $n_orders = count($ids);
  } while ( $n_orders > 0 );

  // 2) Customers + subscribers
  do {
    $uids = get_users([ 'role__in' => ['customer','subscriber'], 'fields' => 'ID', 'number' => $B ]);
    foreach ( $uids as $u ) wp_delete_user($u);
    $n_users = count($uids);
  } while ( $n_users > 0 );

  wp_safe_redirect( add_query_arg('wc_purge_done','1', wp_get_referer() ?: admin_url('tools.php?page=wc-one-click-purge') ) );
  exit;
});

add_action('admin_notices', function () {
  if ( isset($_GET['page'], $_GET['wc_purge_done']) && $_GET['page'] === 'wc-one-click-purge' ) {
    echo '<div class="notice notice-success is-dismissible"><p>Purge complete: all orders and customer/subscriber users removed.</p></div>';
  }
});
