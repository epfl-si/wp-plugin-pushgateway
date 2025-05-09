<?php
/**
 * Plugin Name: WordPress Pushgateway
 * Description: Push metrics to a Prometheus pushgateway in a wp-cron task
 * Version: 0.1
 * Author: ISAS-FSD <isas-fsd@groupes.epfl.ch>
 * License: Copyright (c) 2025 Ecole Polytechnique Federale de Lausanne, Switzerland
 **/

namespace WP_Pushgateway;

/**
 * wp_pushgateway_cron: the wp-cron hook for the wp-pushgateway plugin
 */
add_action("wp_pushgateway_cron", '\WP_Pushgateway\wp_pushgateway');

register_activation_hook(__FILE__, function() {
  if (! wp_next_scheduled("wp_pushgateway_cron")) {
    wp_schedule_event(time(), 'hourly', "wp_pushgateway_cron");
  }
});


register_deactivation_hook(__FILE__, function() {
  while ($timestamp = wp_next_scheduled("wp_pushgateway_cron")) {
    wp_unschedule_event($timestamp, "wp_pushgateway_cron");
  }
});

function wp_pushgateway () {
  $latest_query = new \WP_Query([
    'post_type'      => ['post', 'page'],
    'post_status'    => 'publish',
    'posts_per_page' => 1,
    'orderby'        => 'date',
    'order'          => 'DESC',
  ]);

  if ($latest_query->have_posts()) {
    $latest_query->the_post();
    _do_post_pushgateway("", "wp_latest_publish_time",  get_the_date('U'));
    wp_reset_postdata();
  }

  if (function_exists("\pll_languages_list")) {
    $languages = \pll_languages_list(['fields' => 'slug']);
    foreach ($languages as $lang) {
      foreach (["page", "post"] as $post_type) {
          $query = new \WP_Query([
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'lang'           => $lang,
            'posts_per_page' => -1,
            'fields'         => 'ids'
          ]);

          _do_post_pushgateway(
            "post_type/$post_type/language/$lang",
            "wp_page_count", $query->post_count);
          wp_reset_postdata();
      }
    }
  }
}

function _do_post_pushgateway ($uri, $metric_name, $metric_value) {
  if ($uri !== "" && (! str_ends_with($uri, "/"))) {
    $uri = "$uri/";
  }
  $body = "$metric_name $metric_value\n";
  $url = apply_filters("wp_pushgateway_base_url", "http://pushgateway:9091") .
    "/job/wp_cron/" . $uri . "wp/" . _wordpress_site_name();
  echo("POST $url\n-----\n" . $body . "------\n");  // XXX
  // TODO: actually perform that POST query with that `$body`.
}

/**
 * Returns the value to set as the `wp` Prometheus label in the pushgateway
 */
function _wordpress_site_name () {
  $sluggy_site_name = preg_replace('#^https?://#', '', strtolower(site_url()));
  $sluggy_site_name = preg_replace('#/#', '_', $sluggy_site_name);

  return apply_filters("wp_pushgateway_wp_label", $sluggy_site_name);
}

// People can then say
//
//  add_filter("wp_pushgateway_wp_label", function() { return $my_prometheus_site_name; });
