<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Evazar Product Index 1.0
 * هدف: خارج کردن فیلتر/مرتب‌سازی‌های پرتکرار از wp_postmeta و فراهم‌کردن
 * یک ایندکس سریع و قابل توسعه برای 50k+ کالا.
 */
const EVAZAR_PRODUCT_INDEX_DB_VERSION = '1.1.0';

function evazar_product_index_table() {
    global $wpdb;
    return $wpdb->prefix . 'evazar_product_index';
}
function evazar_product_category_index_table() {
    global $wpdb;
    return $wpdb->prefix . 'evazar_product_category_index';
}

function evazar_product_index_ensure_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();
    $products = evazar_product_index_table();
    $categories = evazar_product_category_index_table();
    $sql1 = "CREATE TABLE {$products} (
        product_id bigint(20) unsigned NOT NULL,
        dkp varchar(64) NOT NULL DEFAULT '',
        sku varchar(64) NOT NULL DEFAULT '',
        brand_id bigint(20) unsigned NOT NULL DEFAULT 0,
        price decimal(18,2) NOT NULL DEFAULT 0,
        old_price decimal(18,2) NOT NULL DEFAULT 0,
        discount smallint unsigned NOT NULL DEFAULT 0,
        stock_status tinyint NOT NULL DEFAULT 0,
        availability_source varchar(32) NOT NULL DEFAULT '',
        stock_checked_at bigint(20) unsigned NOT NULL DEFAULT 0,
        views bigint(20) unsigned NOT NULL DEFAULT 0,
        rating decimal(4,2) NOT NULL DEFAULT 0,
        rating_count bigint(20) unsigned NOT NULL DEFAULT 0,
        satisfaction smallint unsigned NOT NULL DEFAULT 0,
        post_date datetime NULL DEFAULT NULL,
        updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        index_version varchar(20) NOT NULL DEFAULT '1.1.0',
        PRIMARY KEY (product_id),
        KEY dkp (dkp),
        KEY sku (sku),
        KEY brand_id (brand_id),
        KEY price (price),
        KEY stock_status (stock_status),
        KEY availability_source (availability_source),
        KEY stock_checked_at (stock_checked_at),
        KEY rating (rating),
        KEY satisfaction (satisfaction),
        KEY post_date (post_date)
    ) {$charset};";
    $sql2 = "CREATE TABLE {$categories} (
        product_id bigint(20) unsigned NOT NULL,
        category_id bigint(20) unsigned NOT NULL,
        PRIMARY KEY (product_id, category_id),
        KEY category_id (category_id),
        KEY product_id (product_id)
    ) {$charset};";
    dbDelta($sql1);
    dbDelta($sql2);
    update_option('evazar_product_index_db_version', EVAZAR_PRODUCT_INDEX_DB_VERSION, false);
}

function evazar_product_index_maybe_upgrade() {
    if (get_option('evazar_product_index_db_version') !== EVAZAR_PRODUCT_INDEX_DB_VERSION) {
        evazar_product_index_ensure_tables();
    }
}

function evazar_product_index_schedule_backfill() {
    $event = wp_get_scheduled_event('evazar_product_index_backfill');
    if (!$event) {
        wp_schedule_event(time() + 120, 'evazar_stock_5min', 'evazar_product_index_backfill');
    }
}
function evazar_product_index_clear_schedule() {
    $timestamp = wp_next_scheduled('evazar_product_index_backfill');
    while ($timestamp) {
        wp_unschedule_event($timestamp, 'evazar_product_index_backfill');
        $timestamp = wp_next_scheduled('evazar_product_index_backfill');
    }
}
add_action('init', function() {
    if (function_exists('evazar_product_index_schedule_backfill')) {
        evazar_product_index_schedule_backfill();
    }
}, 21);

function evazar_product_index_normalize_number($value) {
    $raw = (string)$value;
    $raw = str_replace(['٬', ',', '،', ' '], '', $raw);
    $raw = strtr($raw, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
    return is_numeric($raw) ? (float)$raw : 0.0;
}

function evazar_product_index_availability_source_for_post($post_id) {
    $source = sanitize_key((string)get_post_meta($post_id, '_evazar_availability_source', true));
    $allowed = ['explicit_unavailable','explicit_available','fallback'];
    return in_array($source, $allowed, true) ? $source : '';
}

function evazar_product_index_stock_status_for_post($post_id) {
    $price = evazar_product_index_normalize_number(get_post_meta($post_id, '_evazar_current_price', true));
    $source = evazar_product_index_availability_source_for_post($post_id);
    if ($source === 'explicit_unavailable') return 0;
    return $price > 0 ? 1 : 0;
}

function evazar_product_index_sync($post_id) {
    global $wpdb;
    $post_id = absint($post_id);
    if (!$post_id || get_post_type($post_id) !== 'evazar_product') return false;
    $post = get_post($post_id);
    if (!$post) return false;
    $products = evazar_product_index_table();
    $categories = evazar_product_category_index_table();

    $dkp = get_post_meta($post_id, '_evazar_sku', true) ?: get_post_meta($post_id, '_sku', true);
    if (!$dkp) {
        $url = get_post_meta($post_id, '_evazar_dk_url', true);
        if ($url && preg_match('/dkp-(\d+)/i', $url, $m)) $dkp = $m[1];
    }
    $sku = (string)($dkp ?: get_post_meta($post_id, '_sku', true));
    $brand_id = 0;
    $brand_terms = wp_get_object_terms($post_id, 'evazar_brand', ['fields'=>'ids']);
    if (!is_wp_error($brand_terms) && !empty($brand_terms)) $brand_id = (int)$brand_terms[0];
    $category_ids = wp_get_object_terms($post_id, 'evazar_category', ['fields'=>'ids']);
    if (is_wp_error($category_ids)) $category_ids = [];

    $stock = evazar_product_index_stock_status_for_post($post_id);
    $availability_source = evazar_product_index_availability_source_for_post($post_id);
    $stock_checked = (int)get_post_meta($post_id, '_evazar_last_stock_check', true);
    $data = [
        'product_id'=>(int)$post_id,
        'dkp'=>sanitize_text_field((string)$dkp),
        'sku'=>sanitize_text_field((string)$sku),
        'brand_id'=>$brand_id,
        'price'=>evazar_product_index_normalize_number(get_post_meta($post_id,'_evazar_current_price',true)),
        'old_price'=>evazar_product_index_normalize_number(get_post_meta($post_id,'_evazar_old_price',true)),
        'discount'=>max(0,(int)evazar_product_index_normalize_number(get_post_meta($post_id,'_evazar_discount_percent',true))),
        'stock_status'=>$stock,
        'availability_source'=>$availability_source,
        'stock_checked_at'=>max(0,$stock_checked),
        'views'=>max(0,(int)get_post_meta($post_id,'_evazar_views_count',true)),
        'rating'=>evazar_product_index_normalize_number(get_post_meta($post_id,'_evazar_rating',true)),
        'rating_count'=>max(0,(int)get_post_meta($post_id,'_evazar_rating_count',true)),
        'satisfaction'=>max(0,(int)get_post_meta($post_id,'_evazar_satisfaction',true)),
        'post_date'=>($post->post_date && $post->post_date !== '0000-00-00 00:00:00') ? $post->post_date : null,
        'index_version'=>EVAZAR_PRODUCT_INDEX_DB_VERSION,
    ];
    $wpdb->replace($products, $data, ['%d','%s','%s','%d','%f','%f','%d','%d','%s','%d','%d','%f','%d','%d','%s','%s']);
    $wpdb->delete($categories, ['product_id'=>$post_id], ['%d']);
    if ($category_ids) {
        $values=[];
        $ph=[];
        foreach (array_unique(array_map('intval',$category_ids)) as $cid) { if ($cid>0) { $ph[]='(%d,%d)'; $values[]=$post_id; $values[]=$cid; } }
        if ($ph) $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$categories} (product_id, category_id) VALUES ".implode(',', $ph), ...$values));
    }
    return true;
}

add_action('set_object_terms', function($object_id, $terms, $tt_ids, $taxonomy) {
    if (get_post_type($object_id) === 'evazar_product' && in_array($taxonomy, ['evazar_category','evazar_brand'], true)) {
        evazar_product_index_sync((int)$object_id);
    }
}, 20, 4);
add_action('save_post_evazar_product', function($post_id, $post, $update) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;
    evazar_product_index_sync((int)$post_id);
}, 20, 3);

function evazar_product_index_backfill_batch($limit = 250) {
    global $wpdb;
    evazar_product_index_ensure_tables();
    $products = evazar_product_index_table();
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$products} i ON i.product_id=p.ID AND i.index_version=%s WHERE p.post_type='evazar_product' AND p.post_status='publish' AND i.product_id IS NULL ORDER BY p.ID ASC LIMIT %d",
        EVAZAR_PRODUCT_INDEX_DB_VERSION, max(1,min(500,(int)$limit))
    ));
    foreach ((array)$ids as $id) evazar_product_index_sync((int)$id);
    return count((array)$ids);
}
add_action('evazar_product_index_backfill', function() { evazar_product_index_backfill_batch(250); });

function evazar_product_index_exists() {
    global $wpdb;
    static $exists = null;
    if ($exists !== null) return $exists;
    $table = evazar_product_index_table();
    $exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table);
    return $exists;
}
