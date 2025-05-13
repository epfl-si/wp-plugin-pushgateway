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
add_action("wp_pushgateway", '\WP_Pushgateway\wp_pushgateway');

register_activation_hook(__FILE__, function() {
  if (! wp_next_scheduled("wp_pushgateway")) {
    // In practice, we always take that branch. The “if” clause is there to
    // work around brokenness, e.g. if plugin deactivation failed part-way.
    wp_schedule_event(time(), 'hourly', "wp_pushgateway");
  }
});


register_deactivation_hook(__FILE__, function() {
  while ($timestamp = wp_next_scheduled("wp_pushgateway")) {
    // Likewise, in practice we shouldn't have to go through this
    // “while” loop more than once.
    wp_unschedule_event($timestamp, "wp_pushgateway");
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
    _do_post_pushgateway("wp_latest_publish_time",  get_the_date('U'));
    wp_reset_postdata();
  }

  if (function_exists("\pll_languages_list")) {
    $languages = \pll_languages_list(['fields' => 'slug']);
    $body = "";
    foreach ($languages as $lang) {
      foreach (["page", "post"] as $post_type) {
          $query = new \WP_Query([
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'lang'           => $lang,
            'posts_per_page' => -1,
            'fields'         => 'ids'
          ]);

          $count = $query->post_count;
          $body .= "wp_page_count{post_type=\"$post_type\",language=\"$lang\"} $count\n";
          wp_reset_postdata();
      }
    }

    _do_post_pushgateway($body);
  }
}

function _do_post_pushgateway ($metric_name_or_body, $metric_value=NULL) {
  if ($metric_value === NULL) {
    $body = $metric_name_or_body;
  } else {
    $body = "$metric_name_or_body $metric_value\n";
  }
  $url = apply_filters("wp_pushgateway_base_url", "http://pushgateway:9091") .
    "/metrics/job/wp_cron/wp/" . _wordpress_site_name();
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
