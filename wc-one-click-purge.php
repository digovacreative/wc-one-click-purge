<?php
/**
 * Plugin Name: WC One-Click Purge (Batched, HPOS-safe)
 * Description: Deletes ALL WooCommerce orders (HPOS-safe) and ALL customer/subscriber users in short batches via auto-redirect to avoid timeouts.
 * Version: 1.1.0
 * Author: Digova
 * License: GPL-2.0+
 */
defined('ABSPATH') || exit;

const WC_OCP_OPT = 'wc_ocp_state_v110'; // option key for progress/state

add_action('admin_menu', function () {
  add_management_page(
    'One-Click WC Purge',
    'One-Click WC Purge',
    'manage_woocommerce',
    'wc-one-click-purge',
    'wc_ocp_render_screen'
  );
});

function wc_ocp_render_screen() {
  if ( ! class_exists('WooCommerce') ) {
    echo '<div class="notice notice-error"><p>WooCommerce must be active.</p></div>';
    return;
  }

  $state = get_option(WC_OCP_OPT);
  $b = isset($state['batch']) ? (int)$state['batch'] : 100;
  $orders_done = (int)($state['orders_done'] ?? 0);
  $orders_total = (int)($state['orders_total'] ?? 0);
  $users_done = (int)($state['users_done'] ?? 0);
  $users_total = (int)($state['users_total'] ?? 0);
  $stage = $state['stage'] ?? 'idle';

  $run_url = wp_nonce_url(
    admin_url('admin-post.php?action=wc_one_click_purge&init=1'),
    'wc_one_click_purge'
  );

  echo '<div class="wrap"><h1>One-Click WC Purge</h1>';
  echo '<p>This will permanently delete <strong>ALL orders</strong> and <strong>ALL buyer accounts</strong> (roles: customer, subscriber). Back up first.</p>';

  if ( isset($_GET['done']) ) {
    echo '<div class="notice notice-success is-dismissible"><p>Purge complete.</p></div>';
  }

  if ( $state ) {
    $pct_orders = $orders_total ? round(($orders_done / max(1,$orders_total)) * 100) : 100;
    $pct_users  = $users_total  ? round(($users_done  / max(1,$users_total))  * 100) : 100;
    echo '<h2>Status</h2><ul>';
    echo '<li>Stage: <code>' . esc_html($stage) . '</code></li>';
    echo '<li>Batch size: ' . esc_html($b) . '</li>';
    echo '<li>Orders: ' . esc_html("$orders_done / $orders_total ($pct_orders%)") . '</li>';
    echo '<li>Users: '  . esc_html("$users_done / $users_total ($pct_users%)") . '</li>';
    echo '</ul>';

    // Auto-continue link (in case browser stops redirects)
    $cont_url = wc_ocp_next_url();
    echo '<p><a class="button button-secondary" href="' . esc_url($cont_url) . '">Continue</a></p>';
  } else {
    echo '<p><a class="button button-primary button-large" href="' . esc_url($run_url) . '">Run Purge</a></p>';
  }

  echo '</div>';
}

/** Build the next-step URL for the auto-redirect chain */
function wc_ocp_next_url() {
  $args = [
    'action'  => 'wc_one_click_purge',
    'step'    => (string) ( (int)($_GET['step'] ?? 0) + 1 ),
  ];
  $url = add_query_arg($args, admin_url('admin-post.php'));
  return wp_nonce_url($url, 'wc_one_click_purge');
}

/** The batched purge engine (one small batch per HTTP request) */
add_action('admin_post_wc_one_click_purge', function () {
  if ( ! current_user_can('manage_woocommerce') ) wp_die('Permission denied');
  check_admin_referer('wc_one_click_purge');

  // Keep each request short
  if ( function_exists('ignore_user_abort') ) ignore_user_abort(true);
  if ( function_exists('set_time_limit') ) @set_time_limit(20);

  $B = 100; // tweak if needed: 50–150 works well on Kinsta
  $state = get_option(WC_OCP_OPT);

  // INIT: compute totals once
  if ( isset($_GET['init']) || ! $state ) {
    $statuses = array_keys( wc_get_order_statuses() );
    $orders = wc_get_orders([
      'type'     => 'shop_order',
      'status'   => $statuses,
      'limit'    => 1,
      'paginate' => true,
      'return'   => 'ids',
    ]);
    $orders_total = is_array($orders) && isset($orders['total']) ? (int)$orders['total'] : 0;

    // Customers: count customer + subscriber
    $counts = count_users();
    $users_total = (int)(
      ($counts['avail_roles']['customer']   ?? 0) +
      ($counts['avail_roles']['subscriber'] ?? 0)
    );

    $state = [
      'stage'         => 'orders',
      'batch'         => $B,
      'orders_done'   => 0,
      'orders_total'  => $orders_total,
      'users_done'    => 0,
      'users_total'   => $users_total,
      'started_at'    => time(),
    ];
    update_option(WC_OCP_OPT, $state, false);
    wp_safe_redirect( wc_ocp_next_url() ); exit;
  }

  // Process ONE batch only
  $stage = $state['stage'] ?? 'orders';
  $B     = (int)($state['batch'] ?? 100);

  if ( $stage === 'orders' ) {
    $ids = wc_get_orders([
      'type'   => 'shop_order',
      'status' => array_keys( wc_get_order_statuses() ),
      'limit'  => $B,
      'return' => 'ids',
    ]);
    foreach ( (array)$ids as $id ) {
      if ( $o = wc_get_order($id) ) $o->delete(true);
    }
    $n = count($ids);
    $state['orders_done'] = (int)$state['orders_done'] + $n;
    if ( $n < $B ) { // no more orders
      $state['stage'] = 'users';
    }
    update_option(WC_OCP_OPT, $state, false);
    wp_safe_redirect( wc_ocp_next_url() ); exit;
  }

  if ( $stage === 'users' ) {
    $uids = get_users([
      'role__in' => ['customer','subscriber'],
      'fields'   => 'ID',
      'number'   => $B,
    ]);
    foreach ( (array)$uids as $u ) {
      wp_delete_user($u);
    }
    $n = count($uids);
    $state['users_done'] = (int)$state['users_done'] + $n;
    update_option(WC_OCP_OPT, $state, false);

    if ( $n < $B ) { // finished completely
      delete_option(WC_OCP_OPT);
      $done_url = add_query_arg(
        ['page'=>'wc-one-click-purge','done'=>1],
        admin_url('tools.php')
      );
      wp_safe_redirect( $done_url ); exit;
    }

    wp_safe_redirect( wc_ocp_next_url() ); exit;
  }

  // Fallback
  delete_option(WC_OCP_OPT);
  wp_safe_redirect( add_query_arg(['page'=>'wc-one-click-purge'], admin_url('tools.php')) ); exit;
});
