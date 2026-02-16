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

define('API_DEMO_PRODUCTS_URL', 'https://fakestoreapi.com/products');
define('API_DEMO_PRODUCTS_TRANSIENT', 'api_demo_products_v1');
define('API_DEMO_PRODUCTS_TTL', 15 * MINUTE_IN_SECONDS);
define('API_DEMO_HTML_TTL', 5 * MINUTE_IN_SECONDS);

/**
 * Detectar si la vista actual contiene el shortcode
 */
function api_demo_current_view_has_shortcode() {
  static $has_shortcode = null;

  if (null !== $has_shortcode) {
    return $has_shortcode;
  }

  if (!is_singular()) {
    $has_shortcode = false;
    return $has_shortcode;
  }

  $post = get_post();
  $has_shortcode = $post && has_shortcode((string) $post->post_content, 'api_products');

  return $has_shortcode;
}

/**
 * Version para invalidar cache renderizado
 */
function api_demo_get_cache_version() {
  return (int) get_option('api_demo_cache_version', 1);
}

function api_demo_bump_cache_version() {
  update_option('api_demo_cache_version', api_demo_get_cache_version() + 1, false);
}

/**
 * Consumir API con cache
 */
function api_demo_get_products($force_refresh = false) {
  if (!$force_refresh) {
    $cached_products = get_transient(API_DEMO_PRODUCTS_TRANSIENT);
    if (false !== $cached_products && is_array($cached_products)) {
      return $cached_products;
    }
  }

  $response = wp_safe_remote_get(API_DEMO_PRODUCTS_URL, [
    'timeout' => 8,
    'redirection' => 3,
  ]);

  if (is_wp_error($response)) {
    return [];
  }

  $status_code = (int) wp_remote_retrieve_response_code($response);
  if ($status_code < 200 || $status_code >= 300) {
    return [];
  }

  $products = json_decode(wp_remote_retrieve_body($response), true);
  if (!is_array($products)) {
    return [];
  }

  set_transient(API_DEMO_PRODUCTS_TRANSIENT, $products, API_DEMO_PRODUCTS_TTL);

  return $products;
}

/**
 * Obtener mapa sku => product_id en una sola query
 */
function api_demo_get_wc_ids_by_skus($skus) {
  global $wpdb;

  $skus = array_values(array_unique(array_map('strval', $skus)));
  if (empty($skus)) {
    return [];
  }

  $placeholders = implode(',', array_fill(0, count($skus), '%s'));
  $sql = $wpdb->prepare(
    "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value IN ({$placeholders})",
    $skus
  );

  $rows = $wpdb->get_results($sql, ARRAY_A);
  if (empty($rows)) {
    return [];
  }

  $map = [];
  foreach ($rows as $row) {
    $map[$row['meta_value']] = (int) $row['post_id'];
  }

  return $map;
}

/**
 * Buscar attachment por URL fuente para evitar duplicados
 */
function api_demo_get_image_id_by_source_url($image_url) {
  $query = new WP_Query([
    'post_type' => 'attachment',
    'post_status' => 'inherit',
    'posts_per_page' => 1,
    'fields' => 'ids',
    'meta_key' => '_api_demo_source_image',
    'meta_value' => esc_url_raw($image_url),
  ]);

  if (!empty($query->posts)) {
    return (int) $query->posts[0];
  }

  return 0;
}

/**
 * Adjuntar imagen destacada desde URL externa
 */
function api_demo_attach_product_image($product_id, $image_url) {
  $product_id = absint($product_id);
  $image_url = esc_url_raw($image_url);

  if (!$product_id || empty($image_url)) {
    return new WP_Error('api_demo_invalid_image_data', 'Datos de imagen inválidos');
  }

  if (has_post_thumbnail($product_id)) {
    return get_post_thumbnail_id($product_id);
  }

  $existing_attachment_id = api_demo_get_image_id_by_source_url($image_url);
  if ($existing_attachment_id) {
    set_post_thumbnail($product_id, $existing_attachment_id);
    return $existing_attachment_id;
  }

  require_once(ABSPATH . 'wp-admin/includes/file.php');
  require_once(ABSPATH . 'wp-admin/includes/media.php');
  require_once(ABSPATH . 'wp-admin/includes/image.php');

  $tmp_file = download_url($image_url);
  if (is_wp_error($tmp_file)) {
    return $tmp_file;
  }

  $filename = basename(parse_url($image_url, PHP_URL_PATH));
  if (!$filename) {
    $filename = 'api-demo-image.jpg';
  }

  $file_array = [
    'name' => sanitize_file_name($filename),
    'tmp_name' => $tmp_file,
  ];

  $image_id = media_handle_sideload($file_array, $product_id);

  if (is_wp_error($image_id)) {
    if (file_exists($tmp_file)) {
      wp_delete_file($tmp_file);
    }
    return $image_id;
  }

  update_post_meta($image_id, '_api_demo_source_image', $image_url);
  set_post_thumbnail($product_id, $image_id);

  return $image_id;
}

/**
 * Render de imagen con fallback a URL de API
 */
function api_demo_get_product_image_html($wc_product, $api_product, $is_lcp_image = false) {
  if (!$wc_product) {
    return '';
  }

  $image_attrs = [
    'decoding' => 'async',
  ];

  if ($is_lcp_image) {
    $image_attrs['loading'] = 'eager';
    $image_attrs['fetchpriority'] = 'high';
  } else {
    $image_attrs['loading'] = 'lazy';
    $image_attrs['fetchpriority'] = 'low';
  }

  if (has_post_thumbnail($wc_product->get_id())) {
    $thumbnail_id = get_post_thumbnail_id($wc_product->get_id());
    if ($thumbnail_id) {
      $image_attrs['sizes'] = '(max-width: 480px) 150px, 150px';
      return wp_get_attachment_image($thumbnail_id, 'thumbnail', false, $image_attrs);
    }
  }

  if (!empty($api_product['image'])) {
    $attrs = sprintf(
      'loading="%s" decoding="async"%s',
      esc_attr($image_attrs['loading']),
      $is_lcp_image ? ' fetchpriority="high"' : ' fetchpriority="low"'
    );

    return sprintf(
      '<img src="%s" alt="%s" width="150" height="150" %s />',
      esc_url($api_product['image']),
      esc_attr($wc_product->get_name()),
      $attrs
    );
  }

  return wc_placeholder_img('woocommerce_thumbnail');
}

/**
 * Registrar estilos
 */
function api_demo_register_styles() {
  wp_register_style(
    'api-demo-products',
    plugin_dir_url(__FILE__) . 'assets/css/api-products.css',
    [],
    '1.0'
  );
}
add_action('wp_enqueue_scripts', 'api_demo_register_styles');

/**
 * Enqueue condicional en head para evitar estilos tardíos
 */
function api_demo_maybe_enqueue_assets() {
  if (!api_demo_current_view_has_shortcode()) {
    return;
  }

  wp_enqueue_style('api-demo-products');
}
add_action('wp_enqueue_scripts', 'api_demo_maybe_enqueue_assets', 20);

/**
 * Reducir JS bloqueante de WooCommerce en páginas del shortcode
 */
function api_demo_reduce_woocommerce_frontend_js() {
  if (!api_demo_current_view_has_shortcode()) {
    return;
  }

  if (function_exists('is_cart') && is_cart()) {
    return;
  }

  if (function_exists('is_checkout') && is_checkout()) {
    return;
  }

  if (function_exists('is_account_page') && is_account_page()) {
    return;
  }

  wp_dequeue_script('wc-cart-fragments');
  wp_deregister_script('wc-cart-fragments');
}
add_action('wp_enqueue_scripts', 'api_demo_reduce_woocommerce_frontend_js', 100);

/**
 * Reducir CSS no usado de WooCommerce en páginas de shortcode
 */
function api_demo_reduce_woocommerce_frontend_css() {
  if (!api_demo_current_view_has_shortcode()) {
    return;
  }

  if (function_exists('is_cart') && is_cart()) {
    return;
  }

  if (function_exists('is_checkout') && is_checkout()) {
    return;
  }

  if (function_exists('is_account_page') && is_account_page()) {
    return;
  }

  wp_dequeue_style('woocommerce-general');
  wp_dequeue_style('woocommerce-layout');
  wp_dequeue_style('woocommerce-smallscreen');
  wp_dequeue_style('wc-blocks-style');
  wp_dequeue_style('wc-blocks-vendors-style');
}
add_action('wp_enqueue_scripts', 'api_demo_reduce_woocommerce_frontend_css', 101);

/**
 * Reducir JS no esencial en frontend
 */
function api_demo_disable_jquery_migrate($scripts) {
  if (is_admin()) {
    return;
  }

  if (isset($scripts->registered['jquery']) && $scripts->registered['jquery']->deps) {
    $scripts->registered['jquery']->deps = array_diff($scripts->registered['jquery']->deps, ['jquery-migrate']);
  }
}
add_action('wp_default_scripts', 'api_demo_disable_jquery_migrate');

/**
 * Desactivar emojis (reduce una request y JS innecesario)
 */
function api_demo_disable_emojis() {
  remove_action('wp_head', 'print_emoji_detection_script', 7);
  remove_action('wp_print_styles', 'print_emoji_styles');
}
add_action('init', 'api_demo_disable_emojis');

/**
 * Preconnect para Google Fonts en páginas del shortcode
 */
function api_demo_google_fonts_preconnect() {
  if (!api_demo_current_view_has_shortcode()) {
    return;
  }

  echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
  echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
}
add_action('wp_head', 'api_demo_google_fonts_preconnect', 1);

/**
 * Carga no bloqueante para estilos de Google Fonts
 */
function api_demo_async_google_fonts_styles($html, $handle, $href, $media) {
  if (!api_demo_current_view_has_shortcode()) {
    return $html;
  }

  if (false === strpos($href, 'fonts.googleapis.com')) {
    return $html;
  }

  $safe_href = esc_url($href);
  return "<link rel='preload' as='style' href='{$safe_href}'><link rel='stylesheet' href='{$safe_href}' media='print' onload=\"this.media='all'\">";
}
add_filter('style_loader_tag', 'api_demo_async_google_fonts_styles', 10, 4);

/**
 * Defer scripts no crÃ­ticos en la pÃ¡gina del shortcode para mejorar TBT/FCP
 */
function api_demo_defer_non_critical_scripts($tag, $handle, $src) {
  if (is_admin() || !api_demo_current_view_has_shortcode()) {
    return $tag;
  }

  if (false !== strpos($tag, ' defer') || false !== strpos($tag, ' async')) {
    return $tag;
  }

  if (
    false !== strpos($src, 'jquery.min.js') ||
    false !== strpos($src, 'jquery-migrate.min.js') ||
    false !== strpos($src, 'jquery.blockUI.min.js')
  ) {
    return $tag;
  }

  $defer_patterns = [
    'elementor/assets/js/common.min.js',
    'elementor/assets/js/frontend.min.js',
    'elementor/assets/js/frontend-modules.min.js',
    'elementor/assets/js/common-modules.min.js',
    'elementor/assets/js/app-loader.min.js',
    'elementor/assets/js/web-cli.min.js',
    'modern-cart/public/minified/frontend.min.js',
    'modern-cart/public/minified/mobile-cart.min.js',
    'woocommerce/assets/js/frontend/order-attribution.min.js',
  ];

  foreach ($defer_patterns as $pattern) {
    if (false !== strpos($src, $pattern)) {
      return str_replace(' src=', ' defer src=', $tag);
    }
  }

  return $tag;
}
add_filter('script_loader_tag', 'api_demo_defer_non_critical_scripts', 10, 3);

/**
 * Shortcode [api_products]
 */
function api_demo_products_shortcode($atts) {
  $atts = shortcode_atts([
    'limit' => 8,
  ], $atts);

  $cache_key = 'api_demo_html_' . api_demo_get_cache_version() . '_' . md5(wp_json_encode($atts));
  $cached_html = get_transient($cache_key);
  if (false !== $cached_html) {
    return $cached_html;
  }

  $products = api_demo_get_products();
  if (!$products) {
    return '<p class="api-products-empty">No hay productos disponibles</p>';
  }

  $products = array_slice($products, 0, (int) $atts['limit']);

  $api_skus = array_map(static function($product) {
    return (string) $product['id'];
  }, $products);
  $sku_to_wc_id = api_demo_get_wc_ids_by_skus($api_skus);
  $rendered_count = 0;

  ob_start();
  ?>
<section class="api-products-grid">
  <?php foreach ($products as $api_product): ?>
    <?php
      $sku = (string) $api_product['id'];
      if (empty($sku_to_wc_id[$sku])) {
        continue;
      }

      $wc_product = wc_get_product((int) $sku_to_wc_id[$sku]);
      if (!$wc_product) {
        continue;
      }
    ?>
    <article class="api-product-card">
      <a href="<?php echo esc_url($wc_product->get_permalink()); ?>">
        <?php echo api_demo_get_product_image_html($wc_product, $api_product, 0 === $rendered_count); ?>
        <h3 class="api-product-title">
          <?php echo esc_html($wc_product->get_name()); ?>
        </h3>
        <p class="api-product-price">
          <?php echo $wc_product->get_price_html(); ?>
        </p>
      </a>
    </article>
    <?php $rendered_count++; ?>
  <?php endforeach; ?>
</section>
  <?php
  $html = ob_get_clean();
  set_transient($cache_key, $html, API_DEMO_HTML_TTL);

  return $html;
}
add_shortcode('api_products', 'api_demo_products_shortcode');

/**
 * Sync manual por query param
 * Ejemplo: /?sync_api=1 (usuario admin logueado)
 */
add_action('init', function() {
  if (!isset($_GET['sync_api'])) {
    return;
  }

  if (!is_user_logged_in() || !current_user_can('manage_woocommerce')) {
    status_header(403);
    exit('No autorizado');
  }

  if (!class_exists('WC_Product_Simple')) {
    exit('WooCommerce no esta activo');
  }

  $products = api_demo_get_products(true);
  if (!$products) {
    exit('No se pudieron obtener productos');
  }

  foreach ($products as $api_product) {
    $sku = (string) $api_product['id'];
    $existing_id = wc_get_product_id_by_sku($sku);

    if ($existing_id) {
      $product_id = (int) $existing_id;
    } else {
      $product = new WC_Product_Simple();
      $product->set_name($api_product['title']);
      $product->set_regular_price((string) $api_product['price']);
      $product->set_description($api_product['description']);
      $product->set_sku($sku);
      $product->set_status('publish');
      $product_id = $product->save();
    }

    if (!empty($api_product['image'])) {
      $image_result = api_demo_attach_product_image($product_id, $api_product['image']);
      if (is_wp_error($image_result) && defined('WP_DEBUG') && WP_DEBUG) {
        error_log(
          sprintf(
            'API Demo image import error for product %d: %s',
            $product_id,
            $image_result->get_error_message()
          )
        );
      }
    }
  }

  delete_transient(API_DEMO_PRODUCTS_TRANSIENT);
  api_demo_bump_cache_version();

  exit('Productos sincronizados correctamente');
});
