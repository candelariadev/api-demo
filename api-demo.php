<?php
/*
Plugin Name: API Demo
Description: Demo consumo de API para productos
Version: 1.0
Author: Candelaria Cabrera
*/

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Consumir API
 */
function api_demo_get_products() {

  $response = wp_remote_get('https://fakestoreapi.com/products');

  if (is_wp_error($response)) {
    return [];
  }

  return json_decode(wp_remote_retrieve_body($response), true);
}

/**
 * Encolar estilos
 */
function api_demo_enqueue_styles() {
  wp_enqueue_style(
    'api-demo-products',
    plugin_dir_url(__FILE__) . 'assets/css/api-products.css',
    [],
    '1.0'
  );
}
add_action('wp_enqueue_scripts', 'api_demo_enqueue_styles');

/**
 * Shortcode [api_products]
 */
function api_demo_products_shortcode($atts) {

  $atts = shortcode_atts([
    'limit' => 8
  ], $atts);

  $products = api_demo_get_products();
  if (!$products) {
    return '<p class="api-products-empty">No hay productos disponibles</p>';
  }

  $products = array_slice($products, 0, (int) $atts['limit']);

  ob_start(); ?>

  <section class="api-products-grid">
    <?php foreach ($products as $product): ?>
      <article class="api-product-card">
        <img
          src="<?php echo esc_url($product['image']); ?>"
          alt="<?php echo esc_attr($product['title']); ?>"
          loading="lazy"
        >

        <h3 class="api-product-title">
          <?php echo esc_html($product['title']); ?>
        </h3>

        <p class="api-product-price">
          $<?php echo esc_html($product['price']); ?>
        </p>
      </article>
    <?php endforeach; ?>
  </section>

  <?php
  return ob_get_clean();
}

add_shortcode('api_products', 'api_demo_products_shortcode');
