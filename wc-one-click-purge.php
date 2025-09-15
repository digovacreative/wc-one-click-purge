<?php
/**
 * Plugin Name: WC One-Click Purge (Batched, HPOS-safe, Sticky Nonce)
 * Description: Deletes ALL WooCommerce orders (HPOS-safe) and ALL buyer accounts (customer/subscriber) in short batches with a persistent nonce to avoid "link expired".
 * Version: 1.2.0
 * Author: Digova
 * License: GPL-2.0+
 */
defined('ABSPATH') || exit;

const WC_OCP_OPT = 'wc_ocp_state_v120'; // option storing purge state+nonce

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
  echo '<div class="wrap"><h1>One-Click WC Purge</h1>';
  echo '<p>This will permanently delete <strong>ALL orders</strong> and <strong>ALL buyer accounts</strong> (roles: customer, subscriber). <strong>Products are not touched.</strong> Back up first.</p>';

  if ( isset($_GET['done']) ) {
    echo '<div class="notice notice-success is-dismissible"><p>Purge complete.</p></div>';
  }

  if ( $state ) {
    $orders_done  = (int)($state['orders_done']  ?? 0);
    $orders_total = (int)($state['orders_total'] ?? 0);
    $users_done   = (int)($state['users_done']   ?? 0);
    $users_total  = (int)($state['users_total']  ?? 0);
    $stage        = $state['stage'] ?? 'orders';
    $b            = (int)($state['batch'] ?? 75);

    $pct_orders = $orders_total ? round($orders_done / max(1,$orders_total) * 100) : 100;
    $pct_users  = $users_total  ? round($users_done  / max(1,$users_total)  * 100) : 100;

    echo '<h2>Status</h2><ul>';
    echo '<li>Stage: <code>' . esc_html($stage) . '</code></li>';
    echo '<li>Batch size: ' . esc_html($b) . '</li>';
    echo '<li>Orders: ' . esc_html("$orders_done / $orders_total ($pct_orders%)") . '</li>';
    echo '<li>Users: '  . esc_html("$users_done / $users_total ($pct_users%)") . '</li>';
    echo '</ul>';

    $cont = wc_ocp_next_url($state);
    echo '<p><a class="button button-secondary" href="' . esc_url($cont) . '">Continue</a></p>';
  } else {
    $init = wc_ocp_init_url();
    echo '<p><a class="button button-primary button-large" href="' . esc_url($init) . '">Run Purge</a></p>';
  }

  echo '</div>';
}

/** Build an init URL with a fresh persistent nonce stored in state */
function wc_ocp_init_url() {
  $args = ['action'=>'wc_one_click_purge','init'=>'1'];
  $url  = add_query_arg($args, admin_url('admin-post.php'));
  // Nonce added after state is created in handler; here we start without it.
  return $url;
}

/** Build the "next step" URL carrying the persistent nonce */
function wc_ocp_next_url(array $state) {
  $args = ['action'=>'wc_one_click_purge','step'=>(string)((int)($_GET['step'] ?? 0)+1), '_wpnonce'=>$state['nonce']];
  return add_query_arg($args, admin_url('admin-post.php'));
}

/** Purge engine: one small batch per request; sticky nonce validated each hop */
add_action('admin_post_wc_one_click_purge', function () {
  if ( ! current_user_can('manage_woocommerce') ) wp_die('Permission denied');

  // Keep each request short (avoid 504s)
  if ( function_exists('ignore_user_abort') ) ignore_user_abort(true);
  if ( function_exists('set_time_limit') ) @set_time_limit(20);

  $state = get_option(WC_OCP_OPT);

  /* INIT step: create state and persistent nonce, then redirect to first batch */
  if ( isset($_GET['init']) || ! $state ) {
    if ( ! class_exists('WooCommerce') ) wp_die('WooCommerce not active');

    $B = 75; // batch size: tune to host (50–150)
    $statuses = array_keys( wc_get_order_statuses() );
    $orders = wc_get_orders([
      'type'     => 'shop_order',
      'status'   => $statuses,
      'limit'    => 1,
      'paginate' => true,
      'return'   => 'ids',
    ]);
    $orders_total = is_array($orders) && isset($orders['total']) ? (int)$orders['total'] : 0;

    $counts = count_users();
    $users_total = (int)(
      ($counts['avail_roles']['customer']   ?? 0) +
      ($counts['avail_roles']['subscriber'] ?? 0)
    );

    // Create a persistent nonce and store it server-side
    $nonce = wp_create_nonce('wc_one_click_purge'); // valid ~24h; stored to avoid “expired link”
    $state = [
      'nonce'        => $nonce,
      'stage'        => 'orders',
      'batch'        => $B,
      'orders_done'  => 0,
      'orders_total' => $orders_total,
      'users_done'   => 0,
      'users_total'  => $users_total,
      'started_at'   => time(),
    ];
    update_option(WC_OCP_OPT, $state, false);

    wp_safe_redirect( wc_ocp_next_url($state) ); exit;
  }

  // Validate persistent nonce on every hop (prevents "link expired")
  if ( empty($_GET['_wpnonce']) || ! is_array($state) || ! hash_equals($state['nonce'] ?? '', (string) $_GET['_wpnonce']) ) {
    delete_option(WC_OCP_OPT);
    wp_die('Security check failed. Please restart the purge from Tools → One-Click WC Purge.');
  }

  $B = (int)($state['batch'] ?? 75);
  $stage = $state['stage'] ?? 'orders';

  if ( $stage === 'orders' ) {
    $ids = wc_get_orders([
      'type'   => 'shop_order',
      'status' => array_keys( wc_get_order_statuses() ),
      'limit'  => $B,
      'return' => 'ids',
    ]);
    foreach ( (array)$ids as $id ) {
      if ( $o = wc_get_order($id) ) $o->delete(true); // HPOS-safe, force delete
    }
    $n = count($ids);
    $state['orders_done'] = (int)$state['orders_done'] + $n;
    if ( $n < $B ) { // stage complete
      $state['stage'] = 'users';
    }
    update_option(WC_OCP_OPT, $state, false);
    wp_safe_redirect( wc_ocp_next_url($state) ); exit;
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

    if ( $n < $B ) {
      // Finished: clear state and redirect to screen with success notice
      delete_option(WC_OCP_OPT);
      $done = add_query_arg(['page'=>'wc-one-click-purge','done'=>1], admin_url('tools.php'));
      wp_safe_redirect($done); exit;
    }

    wp_safe_redirect( wc_ocp_next_url($state) ); exit;
  }

  // Fallback/reset
  delete_option(WC_OCP_OPT);
  wp_safe_redirect( add_query_arg(['page'=>'wc-one-click-purge'], admin_url('tools.php')) ); exit;
});
