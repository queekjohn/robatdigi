<?php
if (!defined('ABSPATH')) exit;

// ==========================================
// 1. ایجاد جدول صف (Queue Database Table)
// ==========================================
function evazar_queue_create_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'evazar_import_queue';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        url varchar(2048) NOT NULL,
        dkp varchar(50) NOT NULL,
        status varchar(20) DEFAULT 'pending' NOT NULL,
        retries int(11) DEFAULT 0 NOT NULL,
        error_code varchar(50) DEFAULT '' NOT NULL,
        error_log text DEFAULT '' NOT NULL,
        last_error_at datetime DEFAULT NULL,
        claim_token varchar(64) DEFAULT '' NOT NULL,
        claimed_at datetime DEFAULT NULL,
        next_attempt_at datetime DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id),
        KEY status (status),
        KEY dkp (dkp),
        KEY error_code (error_code),
        KEY claim_token (claim_token),
        KEY status_id (status, id),
        KEY status_next_attempt (status, next_attempt_at, id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

function evazar_queue_ensure_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'evazar_import_queue';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        evazar_queue_create_table();
    } else {
        // افزودن خودکار ستون‌های جدید خطایابی ساختاریافته در صورت عدم وجود
        $cols = $wpdb->get_col("DESC `$table_name`", 0);
        if (is_array($cols)) {
            if (!in_array('error_code', $cols)) {
                $wpdb->query("ALTER TABLE `$table_name` ADD COLUMN `error_code` varchar(50) DEFAULT '' NOT NULL AFTER `retries`");
            }
            if (!in_array('last_error_at', $cols)) {
                $wpdb->query("ALTER TABLE `$table_name` ADD COLUMN `last_error_at` datetime DEFAULT NULL AFTER `error_log`");
            }
            if (!in_array('claim_token', $cols)) {
                $wpdb->query("ALTER TABLE `$table_name` ADD COLUMN `claim_token` varchar(64) DEFAULT '' NOT NULL AFTER `last_error_at`");
            }
            if (!in_array('claimed_at', $cols)) {
                $wpdb->query("ALTER TABLE `$table_name` ADD COLUMN `claimed_at` datetime DEFAULT NULL AFTER `claim_token`");
            }
            if (!in_array('next_attempt_at', $cols)) {
                $wpdb->query("ALTER TABLE `$table_name` ADD COLUMN `next_attempt_at` datetime DEFAULT NULL AFTER `claimed_at`");
            }
        }
    }
}
add_action('admin_init', 'evazar_queue_ensure_table');

// ==========================================
// 2. تنظیم زمان‌بندی Cron Job
// ==========================================
add_filter('cron_schedules', 'evazar_cron_add_schedule');
function evazar_cron_add_schedule($schedules) {
    $interval_minutes = intval(get_option('evazar_queue_interval_minutes', 3));
    if ($interval_minutes < 1) {
        $interval_minutes = 1;
    }
    $schedules['evazar_queue_custom_schedule'] = array(
        'interval' => $interval_minutes * 60,
        'display'  => sprintf('هر %d دقیقه (ایوازار)', $interval_minutes)
    );
    return $schedules;
}

function evazar_queue_schedule_cron() {
    if (!wp_next_scheduled('evazar_process_import_queue')) {
        wp_schedule_event(time(), 'evazar_queue_custom_schedule', 'evazar_process_import_queue');
    }
}

function evazar_queue_clear_cron() {
    $timestamp = wp_next_scheduled('evazar_process_import_queue');
    while ($timestamp) {
        wp_unschedule_event($timestamp, 'evazar_process_import_queue');
        $timestamp = wp_next_scheduled('evazar_process_import_queue');
    }
}

function evazar_queue_reschedule_cron() {
    evazar_queue_clear_cron();
    wp_schedule_event(time() + 30, 'evazar_queue_custom_schedule', 'evazar_process_import_queue');
}

function evazar_queue_ensure_cron() {
    if (!wp_next_scheduled('evazar_process_import_queue')) {
        evazar_queue_schedule_cron();
    }
}
add_action('admin_init', 'evazar_queue_ensure_cron');

// ==========================================
// 3. اندپوینت REST API (ثبت در صف از سمت هاروستر)
// ==========================================
add_action('rest_api_init', function () {
    register_rest_route('evazar/v1', '/queue-ingest', array(
        'methods' => 'POST',
        'callback' => 'evazar_rest_queue_ingest',
        'permission_callback' => 'evazar_queue_rest_permission',
    ));

    register_rest_route('evazar/v1', '/existing-dkps', array(
        'methods' => 'GET',
        'callback' => 'evazar_rest_existing_dkps',
        'permission_callback' => 'evazar_queue_rest_permission',
    ));
});


function evazar_queue_rest_permission(WP_REST_Request $request) {
    $token = trim((string) get_option('evazar_cf_worker_token', ''));
    if ($token === '') {
        return new WP_Error('evazar_queue_auth_not_configured', 'توکن داخلی Worker در تنظیمات ایوازار تعریف نشده است.', ['status' => 503]);
    }
    $received = trim((string) $request->get_header('x-evazar-internal-token'));
    if ($received === '' || !hash_equals($token, $received)) {
        return new WP_Error('evazar_queue_forbidden', 'دسترسی غیرمجاز.', ['status' => 403]);
    }
    return true;
}

function evazar_rest_existing_dkps() {
    global $wpdb;
    
    $skus = $wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ('_sku', '_evazar_sku') AND meta_value != ''");
    
    evazar_queue_ensure_table();
    $table_name = $wpdb->prefix . 'evazar_import_queue';
    $queued_dkps = $wpdb->get_col("SELECT dkp FROM {$table_name} WHERE dkp != ''");
    
    $all_dkps = array_unique(array_merge($skus, $queued_dkps));
    
    return new WP_REST_Response(array(
        'success' => true,
        'dkps' => array_values($all_dkps)
    ), 200);
}

function evazar_rest_queue_ingest(WP_REST_Request $request) {
    global $wpdb;
    evazar_queue_ensure_table();
    $table_name = $wpdb->prefix . 'evazar_import_queue';
    
    $params = $request->get_json_params();
    if (empty($params) || empty($params['items']) || !is_array($params['items'])) {
        return new WP_REST_Response(array('success' => false, 'message' => 'دیتا نامعتبر است. ساختار باید دارای items باشد.'), 400);
    }

    $inserted = 0;
    $skipped = 0;
    $items = array_slice($params['items'], 0, 100);

    foreach ($items as $item) {
        $dkp = isset($item['dkp']) ? preg_replace('/[^0-9]/', '', (string) $item['dkp']) : '';
        $url = isset($item['url']) ? esc_url_raw($item['url']) : '';
        if ($dkp === '' || strlen($dkp) > 20) continue;
        if ($url !== '' && !preg_match('~^https?://([^/]+\.)?digikala\.com(/|$)~i', $url)) {
            $url = '';
        }
        
        // چک کردن وجود محصول در دیتابیس وردپرس تا مجدد در صف نرود
        $cache_key = 'evz_queue_chk_' . $dkp;
        $existing_post = wp_cache_get($cache_key, 'evazar_core');
        if ($existing_post === false) {
            $existing_post = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_sku', '_evazar_sku') AND meta_value = %s LIMIT 1",
                $dkp
            ));
            wp_cache_set($cache_key, $existing_post ?: 0, 'evazar_core', 3600);
        }
        
        if ($existing_post) {
            $skipped++;
            continue;
        }

        // چک کردن تکراری نبودن در خود صف
        $q_cache_key = 'evz_in_queue_' . $dkp;
        $in_queue = wp_cache_get($q_cache_key, 'evazar_core');
        if ($in_queue === false) {
            $in_queue = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_name WHERE dkp = %s LIMIT 1",
                $dkp
            ));
            wp_cache_set($q_cache_key, $in_queue ?: 0, 'evazar_core', 600);
        }

        if (!$in_queue) {
            $res = $wpdb->insert(
                $table_name,
                array(
                    'url' => $url,
                    'dkp' => $dkp,
                    'status' => 'pending',
                    'next_attempt_at' => null
                ),
                array('%s', '%s', '%s')
            );
            if ($res !== false) {
                $inserted++;
            } else {
                $skipped++;
            }
        } else {
            $skipped++;
        }
    }

    return new WP_REST_Response(array(
        'success' => true, 
        'inserted' => $inserted,
        'skipped' => $skipped,
        'message' => 'درخواست با موفقیت ثبت شد.'
    ), 200);
}

// ==========================================
// 4. پردازش پس‌زمینه صف (اجرا توسط Cron)
// ==========================================
add_action('evazar_process_import_queue', 'evazar_cron_worker_logic');
function evazar_cron_worker_logic($is_manual = false) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'evazar_import_queue';

    $batch_size = intval(get_option('evazar_queue_batch_size', 5));
    if ($batch_size < 1) $batch_size = 1;
    if ($batch_size > 50) $batch_size = 50;

    // بازیابی آیتم‌های stuck و سپس claim اتمیک برای جلوگیری از پردازش دوباره در Cronهای همزمان.
    $stale_before = date('Y-m-d H:i:s', time() - 20 * MINUTE_IN_SECONDS);
    // آیتم‌های گیرکرده را با افزایش تلاش به صف برمی‌گردانیم؛ پس از ۵ تلاش دیگر خودکار دوباره اجرا نمی‌شوند.
    $stale_items = $wpdb->get_results($wpdb->prepare(
        "SELECT id, retries FROM $table_name WHERE status='processing' AND claimed_at IS NOT NULL AND claimed_at < %s",
        $stale_before
    ));
    foreach ((array) $stale_items as $stale_item) {
        $next_retries = ((int) $stale_item->retries) + 1;
        if ($next_retries >= 5) {
            $wpdb->update($table_name, [
                'status' => 'failed',
                'retries' => $next_retries,
                'error_code' => 'STALE_CLAIM_MAX_RETRIES',
                'error_log' => 'پردازش صف بیش از حد مجاز معطل ماند و به دلیل عدم اطمینان، متوقف شد.',
                'last_error_at' => current_time('mysql'),
                'claim_token' => '',
                'claimed_at' => null,
                'next_attempt_at' => null,
            ], ['id' => (int)$stale_item->id]);
        } else {
            $backoff = min(30, max(1, (int) pow(2, $next_retries)));
            $wpdb->update($table_name, [
                'status' => 'pending',
                'retries' => $next_retries,
                'error_code' => 'STALE_CLAIM',
                'error_log' => 'پردازش قبلی صف به‌موقع پایان نیافت؛ آیتم با تأخیر مجدداً در صف قرار گرفت.',
                'last_error_at' => current_time('mysql'),
                'claim_token' => '',
                'claimed_at' => null,
                'next_attempt_at' => date('Y-m-d H:i:s', time() + ($backoff * 60)),
            ], ['id' => (int)$stale_item->id]);
        }
    }

    $claim_token = wp_generate_uuid4();
    $claimed_at = current_time('mysql');
    $claimed = $wpdb->query($wpdb->prepare(
        "UPDATE $table_name SET status='processing', claim_token=%s, claimed_at=%s WHERE status='pending' AND (next_attempt_at IS NULL OR next_attempt_at <= %s) ORDER BY id ASC LIMIT %d",
        $claim_token, $claimed_at, current_time('mysql'), $batch_size
    ));
    if (!$claimed) {
        return [
            'processed' => 0,
            'success'   => 0,
            'failed'    => 0,
            'message'   => 'هیچ کالایی با وضعیت در انتظار در صف وجود ندارد.'
        ];
    }

    $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE claim_token = %s ORDER BY id ASC", $claim_token));
    if (empty($items)) {
        return [
            'processed' => 0,
            'success'   => 0,
            'failed'    => 0,
            'message'   => 'آیتم‌های صف قابل دریافت نبودند.'
        ];
    }

    // لود کردن importer برای فراخوانی evazar_core_process_import
    if (!function_exists('evazar_core_process_import')) {
        require_once plugin_dir_path(__FILE__) . 'importer.php';
    }

    $success_cnt = 0;
    $failed_cnt  = 0;

    foreach ($items as $item) {
        // استفاده از تابع importer فعلی
        $result = evazar_core_process_import($item->dkp);

        if (is_wp_error($result)) {
            $err_code = $result->get_error_code() ?: 'UNKNOWN';
            $err_msg  = $result->get_error_message();
            $next_retries = ((int)$item->retries) + 1;
            $auto_retry = $next_retries < 5;
            $backoff = min(30, max(1, (int) pow(2, $next_retries)));
            $wpdb->update(
                $table_name, 
                [
                    'status'        => $auto_retry ? 'pending' : 'failed',
                    'error_code'    => sanitize_text_field($err_code),
                    'error_log'     => sanitize_textarea_field($err_msg),
                    'last_error_at' => current_time('mysql'),
                    'retries'       => $next_retries,
                    'claim_token'   => '',
                    'claimed_at'    => null,
                    'next_attempt_at' => $auto_retry ? date('Y-m-d H:i:s', time() + ($backoff * 60)) : null
                ], 
                ['id' => $item->id, 'claim_token' => $claim_token]
            );
            $failed_cnt++;
        } else {
            // موفقیت آمیز
            $wpdb->update(
                $table_name, 
                [
                    'status'        => 'completed',
                    'error_code'    => '',
                    'error_log'     => '',
                    'last_error_at' => null,
                    'claim_token'   => '',
                    'claimed_at'    => null,
                    'next_attempt_at' => null
                ], 
                ['id' => $item->id, 'claim_token' => $claim_token]
            );
            
            // ذخیره sku جهت جلوگیری از تکرار در آینده
            update_post_meta($result, '_sku', $item->dkp);
            $success_cnt++;
        }
        
        // وقفه بین کالاها: در کران‌جاب ۲ ثانیه، در اجرای دستی برای سرعت بالاتر ۲۰۰ میلی‌ثانیه
        if (!$is_manual) {
            sleep(2);
        } else {
            usleep(200000);
        }
    }

    $msg = "تعداد " . count($items) . " کالا از صف پردازش شد (" . $success_cnt . " کالا با موفقیت وارد گردید" . ($failed_cnt > 0 ? " و " . $failed_cnt . " مورد دارای خطا بود" : "") . ").";
    return [
        'processed' => count($items),
        'success'   => $success_cnt,
        'failed'    => $failed_cnt,
        'message'   => $msg
    ];
}

// ==========================================
// ==========================================
// 5. اجرای مرکزی عملیات مدیریتی صف (همگام با AJAX و POST)
// ==========================================
function evazar_queue_run_admin_action($action_name) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'evazar_import_queue';

    switch ($action_name) {
        case 'manual_process':
            $res = evazar_cron_worker_logic(true);
            return [
                'success' => true,
                'message' => $res['message'] ?? 'پردازش دستی صف با موفقیت انجام شد.'
            ];

        case 'sync_existing_items':
            if (!function_exists('evazar_sync_product_categories')) {
                require_once plugin_dir_path(__FILE__) . 'importer.php';
            }
            $posts = get_posts([
                'post_type'      => 'evazar_product',
                'posts_per_page' => 200,
                'post_status'    => 'any'
            ]);
            $synced_cats = 0;
            $synced_brands = 0;
            $synced_shorts = 0;
            foreach ($posts as $p) {
                $crumbs = get_post_meta($p->ID, '_evazar_breadcrumbs', true);
                $cat_name = get_post_meta($p->ID, '_evazar_category', true);
                $dkp = get_post_meta($p->ID, '_evazar_sku', true);
                if (empty($dkp)) $dkp = get_post_meta($p->ID, '_sku', true);

                // اگر بردکرامب یا دسته برای کالای قبلی خالی بود، اطلاعات را تازه کنیم
                if ((empty($crumbs) || $crumbs === '[]' || empty($cat_name)) && !empty($dkp)) {
                    $fresh = evazar_fetch_raw_product_data($dkp);
                    if ($fresh) {
                        if (!empty($fresh['breadcrumbs'])) {
                            $crumbs = json_encode($fresh['breadcrumbs'], JSON_UNESCAPED_UNICODE);
                            update_post_meta($p->ID, '_evazar_breadcrumbs', $crumbs);
                        }
                        if (!empty($fresh['category'])) {
                            $cat_name = $fresh['category'];
                            update_post_meta($p->ID, '_evazar_category', $cat_name);
                        }
                    }
                }

                if (!empty($crumbs) || !empty($cat_name)) {
                    $res = evazar_sync_product_categories($p->ID, $crumbs, $cat_name);
                    if ($res) {
                        $synced_cats++;
                    }
                }

                $brand_fa = get_post_meta($p->ID, '_evazar_brand', true);
                $brand_en = get_post_meta($p->ID, '_evazar_brand_en', true);
                if (!empty($brand_fa)) {
                    evazar_sync_product_brand($p->ID, $brand_fa, $brand_en);
                    $synced_brands++;
                }

                // ترمیم ساختار آرایه گالری تصاویر جهت جلوگیری از شکستن کوئری استرینگ‌ها
                $raw_gal = get_post_meta($p->ID, '_evazar_gallery', true);
                if (!empty($raw_gal) && is_string($raw_gal) && strpos($raw_gal, '[') !== 0) {
                    $cleaned_gal = preg_split('/,(?=https?:\/\/)/i', $raw_gal);
                    $cleaned_gal = array_values(array_filter(array_map('trim', $cleaned_gal)));
                    update_post_meta($p->ID, '_evazar_gallery', json_encode($cleaned_gal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }

                // همگام‌سازی متادیتاهای رنک‌مث برای کالاهای قدیمی (در صورت خالی بودن)
                $existing_rm_desc = get_post_meta($p->ID, 'rank_math_description', true);
                if (empty($existing_rm_desc)) {
                    $p_title = get_the_title($p->ID);
                    $site_name = get_bloginfo('name') ?: 'ایوازار';
                    $auto_desc = "مشخصات فنی، قیمت و خرید اینترنتی " . mb_substr($p_title, 0, 75) . " در " . $site_name . " با تضمین اصالت و بهترین قیمت روز.";
                    update_post_meta($p->ID, 'rank_math_description', $auto_desc);
                }
                $existing_rm_kw = get_post_meta($p->ID, 'rank_math_focus_keyword', true);
                if (empty($existing_rm_kw)) {
                    $p_title = get_the_title($p->ID);
                    $words = preg_split('/\s+/', trim($p_title));
                    $auto_kw = implode(' ', array_slice($words, 0, 4));
                    update_post_meta($p->ID, 'rank_math_focus_keyword', $auto_kw);
                }
                if (!get_post_meta($p->ID, 'rank_math_robots', true)) {
                    update_post_meta($p->ID, 'rank_math_robots', ['index']);
                }

                // پاکسازی کامل توضیحات کوتاه در صورت وجود
                $existing_short = get_post_field('post_excerpt', $p->ID);
                if (!empty($existing_short)) {
                    wp_update_post([
                        'ID' => $p->ID,
                        'post_excerpt' => '',
                    ]);
                }
                delete_post_meta($p->ID, '_evazar_short_desc');
            }
            return [
                'success' => true,
                'message' => "همگام‌سازی با موفقیت انجام شد: {$synced_cats} کالا به دسته‌بندی‌های سلسله‌مراتبی متصل شدند، {$synced_brands} برند همگام‌سازی گردید و متادیتاها به‌روز شدند."
            ];

        case 'generate_terms_seo_content':
            if (!function_exists('evazar_ai_ensure_term_seo_content')) {
                require_once plugin_dir_path(__FILE__) . 'importer.php';
            }
            $taxonomies = ['evazar_category', 'evazar_brand'];
            $generated = 0;
            foreach ($taxonomies as $taxonomy) {
                $terms = get_terms([
                    'taxonomy'   => $taxonomy,
                    'hide_empty' => false,
                ]);
                if (!empty($terms) && !is_wp_error($terms)) {
                    foreach ($terms as $term) {
                        if (empty(trim(strip_tags($term->description)))) {
                            $type = ($taxonomy === 'evazar_brand') ? 'brand' : 'category';
                            evazar_ai_ensure_term_seo_content($term->term_id, $taxonomy, $term->name, $type);
                            $generated++;
                            // جهت جلوگیری از تایم‌اوت در هر نوبت حداکثر ۴ ترم با هوش مصنوعی تولید می‌شود
                            if ($generated >= 4) {
                                break 2;
                            }
                        }
                    }
                }
            }
            $msg = $generated > 0 
                ? "عملیات تولید محتوای سئو انجام شد: برای {$generated} دسته و برند فاقد محتوا، مقاله راهنمای خرید سئو و متادیتاهای رنک‌مث با موفقیت ایجاد گردید."
                : "تمامی دسته‌ها و برندهای موجود دارای محتوای سئو هستند و نیازی به تولید مجدد نبود.";
            return [
                'success' => true,
                'message' => $msg
            ];

        case 'cleanup_downloaded_images':
            $cleaned = 0;
            $products = get_posts([
                'post_type'      => 'evazar_product',
                'posts_per_page' => -1,
                'post_status'    => 'any',
                'fields'         => 'ids'
            ]);
            foreach ($products as $pid) {
                $thumb_id = get_post_thumbnail_id($pid);
                if ($thumb_id) {
                    delete_post_thumbnail($pid);
                    wp_delete_attachment($thumb_id, true);
                    $cleaned++;
                }
            }
            return [
                'success' => true,
                'message' => "عملیات پاکسازی با موفقیت انجام شد: {$cleaned} فایل تصویر و بندانگشتی از پوشه uploads هاست حذف شدند. تصاویر کالاها هم‌اکنون به صورت مستقیم و با حداکثر سرعت از CDN دیجی‌کالا لود می‌شوند."
            ];

        case 'retry_failed_items':
            $retried = $wpdb->query("UPDATE $table_name SET status = 'pending', retries = 0, next_attempt_at = NULL, claim_token = '', claimed_at = NULL WHERE status = 'failed'");
            return [
                'success' => true,
                'message' => "تعداد " . intval($retried) . " کالای دارای خطا برای تلاش مجدد به صف فعال بازگردانده شدند."
            ];

        case 'clear_queue':
            $wpdb->query("TRUNCATE TABLE $table_name");
            return [
                'success' => true,
                'message' => 'تمام آیتم‌های صف با موفقیت پاک‌سازی شدند.'
            ];

        case 'clear_all_short_descriptions':
        case 'rewrite_old_short_descriptions':
            $all_p = get_posts([
                'post_type'      => 'evazar_product',
                'posts_per_page' => -1,
                'post_status'    => 'any',
                'fields'         => 'ids'
            ]);

            $cleared_count = 0;
            foreach ($all_p as $pid) {
                $cur = get_post_field('post_excerpt', $pid);
                $cur_meta = get_post_meta($pid, '_evazar_short_desc', true);
                if (!empty($cur) || !empty($cur_meta)) {
                    wp_update_post([
                        'ID'           => $pid,
                        'post_excerpt' => ''
                    ]);
                    delete_post_meta($pid, '_evazar_short_desc');
                    $cleared_count++;
                }
            }

            return [
                'success' => true,
                'message' => "توضیحات کوتاه برای تمام کالاهای سایت به طور کامل حذف شد (تعداد {$cleared_count} کالا پاکسازی گردید)."
            ];

        case 'requeue_all_for_ai_rewrite':
            // ۱. پاکسازی آنی توضیحات کوتاه و نقاط ضعف از تمام کالاهای سایت
            $all_products_to_clean = get_posts([
                'post_type'      => 'evazar_product',
                'posts_per_page' => -1,
                'post_status'    => 'any',
                'fields'         => 'ids'
            ]);
            foreach ($all_products_to_clean as $pid) {
                wp_update_post([
                    'ID'           => $pid,
                    'post_excerpt' => ''
                ]);
                delete_post_meta($pid, '_evazar_short_desc');
                update_post_meta($pid, '_evazar_cons', '');
            }

            // ۲. بازگردانی تمام ردیف‌های انجام‌شده به وضعیت در انتظار
            $updated = $wpdb->query("UPDATE $table_name SET status = 'pending', retries = 0 WHERE status = 'completed'");

            // ۳. بررسی و ثبت کالاهایی که احیاناً در صف وجود ندارند
            $existing_dkps = $wpdb->get_col("SELECT dkp FROM $table_name");
            $existing_map = array_flip($existing_dkps ?: []);

            $all_products = $wpdb->get_results("
                SELECT post_id, meta_value as dkp 
                FROM {$wpdb->postmeta} 
                WHERE meta_key = '_evazar_sku' AND meta_value != ''
            ");

            $inserted = 0;
            if (!empty($all_products)) {
                foreach ($all_products as $p) {
                    $dkp = preg_replace('/[^0-9]/', '', (string)$p->dkp);
                    if (!empty($dkp) && !isset($existing_map[$dkp])) {
                        $wpdb->insert($table_name, [
                            'url'     => "https://www.digikala.com/product/dkp-{$dkp}/",
                            'dkp'     => $dkp,
                            'status'  => 'pending',
                            'retries' => 0
                        ]);
                        $existing_map[$dkp] = true;
                        $inserted++;
                    }
                }
            }

            $total_count = intval($updated) + $inserted;
            return [
                'success' => true,
                'message' => "تعداد {$total_count} کالا با موفقیت به صف بازگردانده شدند تا با قوانین جدید (حذف کامل توضیحات کوتاه، حذف نقاط ضعف و اصل تناسب) بازنویسی شوند."
            ];

        default:
            return [
                'success' => false,
                'message' => 'عملیات درخواستی نامعتبر است.'
            ];
    }
}

// اندپوینت پردازش ایجکس (AJAX Handler) برای عملیات صف بدون فریز شدن مرورگر
add_action('wp_ajax_evazar_queue_ajax_action', 'evazar_queue_ajax_action_callback');
function evazar_queue_ajax_action_callback() {
    check_ajax_referer('evazar_queue_action_nonce', 'security');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'دسترسی غیرمجاز است.']);
    }

    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }
    @ini_set('memory_limit', '512M');

    $action_type = isset($_POST['action_type']) ? sanitize_key($_POST['action_type']) : '';
    $res = evazar_queue_run_admin_action($action_type);

    global $wpdb;
    $table_name = $wpdb->prefix . 'evazar_import_queue';
    $stats = [
        'total'      => intval($wpdb->get_var("SELECT COUNT(*) FROM $table_name")),
        'pending'    => intval($wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'pending'")),
        'processing' => intval($wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'processing'")),
        'completed'  => intval($wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'completed'")),
        'failed'     => intval($wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'failed'")),
    ];

    if ($res['success']) {
        wp_send_json_success([
            'message' => $res['message'],
            'stats'   => $stats
        ]);
    } else {
        wp_send_json_error([
            'message' => $res['message'],
            'stats'   => $stats
        ]);
    }
}

// ==========================================
// 6. صفحه ادمین برای مشاهده وضعیت صف
// ==========================================
add_action('admin_menu', 'evazar_queue_admin_page');
function evazar_queue_admin_page() {
    add_submenu_page(
        'edit.php?post_type=evazar_product',
        'وضعیت صف واردات',
        'صف واردات',
        'manage_options',
        'evazar-queue',
        'evazar_queue_admin_page_html'
    );
}

function evazar_queue_admin_page_html() {
    global $wpdb;
    evazar_queue_ensure_table();
    $table_name = $wpdb->prefix . 'evazar_import_queue';

    // پردازش درخواست‌های سنتی فرم (POST Fallback)
    $post_action = '';
    if (isset($_POST['evazar_manual_process'])) $post_action = 'manual_process';
    elseif (isset($_POST['evazar_sync_existing_items'])) $post_action = 'sync_existing_items';
    elseif (isset($_POST['evazar_generate_terms_seo_content'])) $post_action = 'generate_terms_seo_content';
    elseif (isset($_POST['evazar_cleanup_downloaded_images'])) $post_action = 'cleanup_downloaded_images';
    elseif (isset($_POST['evazar_retry_failed_items'])) $post_action = 'retry_failed_items';
    elseif (isset($_POST['evazar_clear_queue'])) $post_action = 'clear_queue';
    elseif (isset($_POST['evazar_rewrite_old_short_descriptions'])) $post_action = 'rewrite_old_short_descriptions';

    if (!empty($post_action) && check_admin_referer('evazar_queue_action_nonce')) {
        $result = evazar_queue_run_admin_action($post_action);
        $notice_cls = $result['success'] ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . $notice_cls . ' is-dismissible"><p><strong>' . esc_html($result['message']) . '</strong></p></div>';
    }

    // ذخیره تنظیمات صف
    if (isset($_POST['evazar_save_queue_settings']) && check_admin_referer('evazar_queue_settings_nonce')) {
        $interval = max(1, min(60, intval($_POST['evazar_queue_interval_minutes'])));
        $batch = max(1, min(50, intval($_POST['evazar_queue_batch_size'])));
        
        $old_interval = intval(get_option('evazar_queue_interval_minutes', 3));
        
        update_option('evazar_queue_interval_minutes', $interval);
        update_option('evazar_queue_batch_size', $batch);

        if ($old_interval !== $interval) {
            evazar_queue_reschedule_cron();
        }

        echo '<div class="notice notice-success is-dismissible"><p><strong>تنظیمات صف با موفقیت ذخیره و زمان‌بندی کران‌جاب به‌روزرسانی شد.</strong></p></div>';
    }

    $current_interval = intval(get_option('evazar_queue_interval_minutes', 3));
    $current_batch = intval(get_option('evazar_queue_batch_size', 5));

    $total_all = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    $pending = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'pending'");
    $processing = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'processing'");
    $completed = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'completed'");
    $failed = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'failed'");

    // فیلتر وضعیت انتخابی کاربر
    $current_filter = isset($_GET['q_status']) ? sanitize_text_field($_GET['q_status']) : '';
    $allowed_statuses = ['pending', 'processing', 'completed', 'failed'];
    if (in_array($current_filter, $allowed_statuses, true)) {
        $where_clause = $wpdb->prepare("WHERE status = %s", $current_filter);
    } else {
        $current_filter = '';
        $where_clause = '';
    }

    $recent_items = $wpdb->get_results("SELECT * FROM $table_name $where_clause ORDER BY id DESC LIMIT 50");

    echo '<div class="wrap" style="direction: rtl;">';
    echo '<h1 style="font-weight: 700; margin-bottom: 20px;">داشبورد و وضعیت صف واردات ایوازار</h1>';
    
    // کارت‌های وضعیت
    echo '<div style="display: flex; gap: 16px; margin-bottom: 25px; flex-wrap: wrap;">';
    echo '<div style="background: #fff; padding: 18px 24px; border-radius: 10px; border-right: 5px solid #f59e0b; box-shadow: 0 2px 6px rgba(0,0,0,0.06); min-width: 170px;"><strong>در انتظار:</strong> <div style="font-size: 26px; font-weight: bold; color: #b45309; margin-top: 5px;">' . intval($pending) . '</div></div>';
    echo '<div style="background: #fff; padding: 18px 24px; border-radius: 10px; border-right: 5px solid #3b82f6; box-shadow: 0 2px 6px rgba(0,0,0,0.06); min-width: 170px;"><strong>در حال پردازش:</strong> <div style="font-size: 26px; font-weight: bold; color: #1d4ed8; margin-top: 5px;">' . intval($processing) . '</div></div>';
    echo '<div style="background: #fff; padding: 18px 24px; border-radius: 10px; border-right: 5px solid #10b981; box-shadow: 0 2px 6px rgba(0,0,0,0.06); min-width: 170px;"><strong>تکمیل شده:</strong> <div style="font-size: 26px; font-weight: bold; color: #047857; margin-top: 5px;">' . intval($completed) . '</div></div>';
    echo '<div style="background: #fff; padding: 18px 24px; border-radius: 10px; border-right: 5px solid #ef4444; box-shadow: 0 2px 6px rgba(0,0,0,0.06); min-width: 170px;"><strong>خطا خورده:</strong> <div style="font-size: 26px; font-weight: bold; color: #b91c1c; margin-top: 5px;">' . intval($failed) . '</div></div>';
    echo '</div>';

    // پنل تنظیمات زمان‌بندی و بچ
    echo '<div style="background: #fff; padding: 18px 24px; border-radius: 10px; box-shadow: 0 1px 4px rgba(0,0,0,0.06); margin-bottom: 25px;">';
    echo '<h3 style="margin-top: 0; margin-bottom: 16px; font-size: 15px; font-weight: 700; color: #1e293b;">⚙️ تنظیمات زمان‌بندی و سرعت پردازش صف</h3>';
    echo '<form method="post" style="display: flex; gap: 20px; align-items: flex-start; flex-wrap: wrap;">';
    wp_nonce_field('evazar_queue_settings_nonce');
    echo '<div style="display: flex; flex-direction: column;">';
    echo '<label for="evazar_queue_interval_minutes" style="font-weight: 600; margin-bottom: 6px; font-size: 13px; color: #334155;">فاصله زمانی اجرای کران‌جاب (دقیقه):</label>';
    echo '<input type="number" id="evazar_queue_interval_minutes" name="evazar_queue_interval_minutes" value="' . esc_attr($current_interval) . '" min="1" max="60" style="width: 140px; height: 38px; padding: 0 10px; border-radius: 6px; border: 1px solid #cbd5e1; box-sizing: border-box;">';
    echo '<p style="margin: 5px 0 0; font-size: 11px; color: #64748b;">پیش‌فرض: ۳ دقیقه (بین ۱ تا ۶۰ دقیقه)</p>';
    echo '</div>';
    echo '<div style="display: flex; flex-direction: column;">';
    echo '<label for="evazar_queue_batch_size" style="font-weight: 600; margin-bottom: 6px; font-size: 13px; color: #334155;">تعداد کالای پردازشی در هر بار (بچ):</label>';
    echo '<input type="number" id="evazar_queue_batch_size" name="evazar_queue_batch_size" value="' . esc_attr($current_batch) . '" min="1" max="50" style="width: 140px; height: 38px; padding: 0 10px; border-radius: 6px; border: 1px solid #cbd5e1; box-sizing: border-box;">';
    echo '<p style="margin: 5px 0 0; font-size: 11px; color: #64748b;">پیش‌فرض: ۵ کالا (بین ۱ تا ۵۰ کالا)</p>';
    echo '</div>';
    echo '<div style="display: flex; flex-direction: column;">';
    echo '<span style="font-size: 13px; line-height: 1.4; margin-bottom: 6px; visibility: hidden; user-select: none;">&nbsp;</span>';
    echo '<button type="submit" name="evazar_save_queue_settings" class="button button-primary" style="height: 38px; line-height: 36px; padding: 0 24px; font-size: 13px; font-weight: 600; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; box-shadow: 0 1px 3px rgba(37,99,235,0.25);">💾 ذخیره تنظیمات</button>';
    echo '</div>';
    echo '</form>';
    echo '</div>';

    // نوار کنترل و دکمه‌های عملیاتی
    echo '<div style="background: #fff; padding: 15px 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 25px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px;">';
    echo '<form method="post" id="evazar-queue-actions-form" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">';
    wp_nonce_field('evazar_queue_action_nonce');
    echo '<button type="submit" name="evazar_manual_process" class="button button-primary evazar-ajax-action-btn" data-action="manual_process" data-title="⚡ اجرای دستی صف واردات" data-desc="سیستم در حال دریافت اطلاعات از دیجی‌کالا و پردازش کالاهاست. لطفاً چند لحظه صبر کنید..." style="padding: 4px 18px; height: auto; font-size: 14px;">⚡ اجرای دستی صف (هم‌اکنون ' . esc_html($current_batch) . ' کالا)</button>';
    if ($failed > 0) {
        echo '<button type="submit" name="evazar_retry_failed_items" class="button evazar-ajax-action-btn" data-action="retry_failed_items" data-title="🔄 تلاش مجدد برای کالاهای خطاخورده" data-desc="در حال تغییر وضعیت کالاهای دارای خطا به صف در انتظار..." data-confirm="آیا مایلید تمام کالاهای خطا خورده مجدداً در وضعیت در انتظار قرار گیرند؟" style="color: #b91c1c; border-color: #f87171; background: #fef2f2; font-weight: 600;">🔄 تلاش مجدد برای کالاهای خطاخورده (' . intval($failed) . ')</button>';
    }
    echo '<button type="submit" name="evazar_sync_existing_items" class="button evazar-ajax-action-btn" data-action="sync_existing_items" data-title="🔄 همگام‌سازی دسته‌ها، برندها و مشخصات" data-desc="در حال اتصال کالاها به دسته‌بندی‌های سلسله‌مراتبی، همگام‌سازی برندها، ساختار گالری و متادیتاها..." style="color: #0284c7; border-color: #38bdf8;">🔄 همگام‌سازی دسته‌ها و برندها</button>';
    echo '<button type="submit" name="evazar_requeue_all_for_ai_rewrite" class="button evazar-ajax-action-btn" data-action="requeue_all_for_ai_rewrite" data-title="🔄 بازگردانی تمام کالاها به صف برای بازنویسی" data-desc="در حال بررسی تمام کالاهای سایت و بازگردانی آن‌ها به صف جهت بازنویسی با قوانین جدید هوش مصنوعی..." data-confirm="آیا مایلید تمام کالاهای سایت به صف واردات بازگردند تا با قوانین جدید (حذف نقاط ضعف و اصل تناسب) بازنویسی شوند؟" style="color: #4338ca; border-color: #a5b4fc; background: #eef2ff; font-weight: 600;">🔄 بازگردانی تمام کالاها به صف برای بازنویسی کامل</button>';
    echo '<button type="submit" name="evazar_generate_terms_seo_content" class="button evazar-ajax-action-btn" data-action="generate_terms_seo_content" data-title="🤖 تولید محتوای سئو با هوش مصنوعی" data-desc="در حال تحلیل دسته‌ها و برندهای فاقد محتوا و نگارش مقالات تخصصی راهنمای خرید سئو..." style="color: #15803d; border-color: #86efac;">🤖 تولید محتوای سئو برای دسته‌ها و برندهای خالی</button>';
    echo '<button type="submit" name="evazar_clear_all_short_descriptions" class="button evazar-ajax-action-btn" data-action="clear_all_short_descriptions" data-title="🗑 پاک‌سازی کامل توضیحات کوتاه تمام کالاها" data-desc="در حال پاکسازی و حذف کامل توضیحات کوتاه از تمام کالاهای سایت..." data-confirm="آیا مطمئن هستید که می‌خواهید توضیحات کوتاه از تمام کالاهای سایت به طور کامل حذف شود؟" style="color: #6d28d9; border-color: #c4b5fd; background: #f5f3ff; font-weight: 600;">🗑 پاک‌سازی کامل توضیحات کوتاه تمام کالاها</button>';
    echo '<button type="submit" name="evazar_cleanup_downloaded_images" class="button evazar-ajax-action-btn" data-action="cleanup_downloaded_images" data-title="🧹 آزادسازی فضای هاست و پاکسازی تصاویر" data-desc="در حال پاکسازی فایل‌های ذخیره شده در هاست و اتصال مستقیم تصاویر به CDN دیجی‌کالا..." data-confirm="آیا از حذف تصاویر ذخیره شده در هاست و بازگشت کامل به CDN مطمئن هستید؟ (هیچ عکسی از کالا پاک نمی‌شود، فقط فایل‌های هاست حذف شده و عکس‌ها مستقیم از CDN خوانده می‌شوند)" style="color: #ea580c; border-color: #fdba74;">🧹 پاکسازی تصاویر ذخیره‌شده از هاست</button>';
    echo '<button type="submit" name="evazar_clear_queue" class="button evazar-ajax-action-btn" data-action="clear_queue" data-title="🗑 پاک‌سازی کامل صف" data-desc="در حال تخلیه تمام ردیف‌های صف واردات..." data-confirm="آیا از پاک‌سازی کامل صف مطمئن هستید؟" style="color: #b91c1c; border-color: #fca5a5;">🗑 پاک‌سازی صف</button>';
    echo '<a href="' . esc_url(admin_url('edit.php?post_type=evazar_product&page=evazar-importer#evazar-import-logs')) . '" class="button" style="color: #2563eb; border-color: #93c5fd; background: #eff6ff; font-weight: 600;">📋 مشاهده ۵۰ لاگ اخیر واردات</a>';
    echo '</form>';
    echo '<span style="color: #64748b; font-size: 13px;">وضعیت کران‌جاب خودکار: هر <strong>' . esc_html($current_interval) . '</strong> دقیقه، <strong>' . esc_html($current_batch) . '</strong> کالا به صورت خودکار در پس‌زمینه پردازش می‌شوند.</span>';
    echo '</div>';

    // فیلتر تب‌های وضعیت
    $base_page_url = admin_url('edit.php?post_type=evazar_product&page=evazar-queue');
    echo '<div style="margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">';
    echo '<ul class="subsubsub" style="margin: 0; float: none;">';
    echo '<li><a href="' . esc_url($base_page_url) . '" class="' . (empty($current_filter) ? 'current' : '') . '">همه <span class="count">(' . intval($total_all) . ')</span></a> | </li>';
    echo '<li><a href="' . esc_url(add_query_arg('q_status', 'pending', $base_page_url)) . '" class="' . ($current_filter === 'pending' ? 'current' : '') . '">در انتظار <span class="count">(' . intval($pending) . ')</span></a> | </li>';
    echo '<li><a href="' . esc_url(add_query_arg('q_status', 'processing', $base_page_url)) . '" class="' . ($current_filter === 'processing' ? 'current' : '') . '">در حال پردازش <span class="count">(' . intval($processing) . ')</span></a> | </li>';
    echo '<li><a href="' . esc_url(add_query_arg('q_status', 'completed', $base_page_url)) . '" class="' . ($current_filter === 'completed' ? 'current' : '') . '">تکمیل شده <span class="count">(' . intval($completed) . ')</span></a> | </li>';
    echo '<li><a href="' . esc_url(add_query_arg('q_status', 'failed', $base_page_url)) . '" class="' . ($current_filter === 'failed' ? 'current' : '') . '">دارای خطا <span class="count">(' . intval($failed) . ')</span></a></li>';
    echo '</ul>';
    echo '<span style="font-size: 13px; color: #64748b;">نمایش آخرین ۵۰ ردیف</span>';
    echo '</div>';

    // جدول آیتم‌های صف
    echo '<table class="wp-list-table widefat fixed striped" style="border-radius: 8px; overflow: hidden;">';
    echo '<thead><tr>';
    echo '<th style="width: 65px;">شناسه</th>';
    echo '<th style="width: 120px;">کد کالا (DKP)</th>';
    echo '<th style="width: 30%;">آدرس دیجی‌کالا</th>';
    echo '<th style="width: 110px;">وضعیت</th>';
    echo '<th style="width: 140px;">تاریخ ثبت</th>';
    echo '<th>گزارش خطا و وضعیت هوشمند</th>';
    echo '</tr></thead>';
    echo '<tbody>';

    if (empty($recent_items)) {
        echo '<tr><td colspan="6" style="text-align: center; padding: 25px; color: #94a3b8;">هیچ موردی مطابق فیلتر انتخابی در صف یافت نشد.</td></tr>';
    } else {
        foreach ($recent_items as $item) {
            $badge_style = 'padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: bold; display: inline-block;';
            switch ($item->status) {
                case 'completed':
                    $badge = '<span style="' . $badge_style . ' background: #d1fae5; color: #065f46;">تکمیل شده</span>';
                    break;
                case 'processing':
                    $badge = '<span style="' . $badge_style . ' background: #dbeafe; color: #1e40af;">در حال پردازش</span>';
                    break;
                case 'failed':
                    $badge = '<span style="' . $badge_style . ' background: #fee2e2; color: #991b1b;">خطا خورده</span>';
                    break;
                default:
                    $badge = '<span style="' . $badge_style . ' background: #fef3c7; color: #92400e;">در انتظار</span>';
                    break;
            }

            // بج ساختاریافته کد خطا
            $error_badge = '';
            if (!empty($item->error_code)) {
                $code = $item->error_code;
                $err_style = 'display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 600; margin-left: 8px; margin-bottom: 4px;';
                if ($code === 'DK_BLOCKED_403') {
                    $error_badge = '<span style="' . $err_style . ' background: #fee2e2; color: #991b1b;">🛡️ مسدودی فایروال (۴۰۳)</span>';
                } elseif ($code === 'DK_RATE_LIMIT_429') {
                    $error_badge = '<span style="' . $err_style . ' background: #ffedd5; color: #9a3412;">⏳ محدودیت نرخ (۴۲۹)</span>';
                } elseif ($code === 'DK_NOT_FOUND_404') {
                    $error_badge = '<span style="' . $err_style . ' background: #f3e8ff; color: #6b21a8;">❌ کالای ناموجود (۴۰۴)</span>';
                } elseif ($code === 'SCHEMA_MISMATCH') {
                    $error_badge = '<span style="' . $err_style . ' background: #e0f2fe; color: #0369a1;">⚠️ تغییر ساختار داده API</span>';
                } elseif ($code === 'API_CONN_ERROR') {
                    $error_badge = '<span style="' . $err_style . ' background: #f1f5f9; color: #475569;">🌐 خطای اتصال شبکه</span>';
                } else {
                    $error_badge = '<span style="' . $err_style . ' background: #f1f5f9; color: #334155; font-family: monospace;">⚠️ ' . esc_html($code) . '</span>';
                }
            }

            $time_err = !empty($item->last_error_at) ? '<div style="font-size: 11px; color: #94a3b8; margin-top: 3px; direction: ltr; text-align: right;">آخرین رخداد: ' . esc_html($item->last_error_at) . '</div>' : '';

            echo '<tr>';
            echo '<td>' . esc_html($item->id) . '</td>';
            echo '<td><strong>' . esc_html($item->dkp) . '</strong></td>';
            echo '<td><a href="' . esc_url($item->url) . '" target="_blank" style="direction: ltr; display: inline-block; word-break: break-all;">' . esc_html($item->url) . '</a></td>';
            echo '<td>' . $badge . '</td>';
            echo '<td style="direction: ltr; text-align: right;">' . esc_html($item->created_at) . '</td>';
            echo '<td>';
            if ($error_badge) {
                echo $error_badge;
            }
            if (!empty($item->error_log)) {
                echo '<div style="color: #475569; font-size: 12px; line-height: 1.6;">' . esc_html($item->error_log) . '</div>';
            } elseif (empty($error_badge)) {
                echo '<span style="color: #94a3b8;">-</span>';
            }
            echo $time_err;
            echo '</td>';
            echo '</tr>';
        }
    }

    echo '</tbody></table>';
    echo '</div>';

    // مودال پردازش ایجکس و دیالوگ زنده پیشرفت
    ?>
    <div id="evazar-queue-modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.68); backdrop-filter:blur(5px); z-index:999999; align-items:center; justify-content:center;">
        <div style="background:#fff; border-radius:16px; max-width:500px; width:92%; padding:32px 26px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); text-align:center; font-family:inherit; direction:rtl; position:relative;">
            <div id="evazar-modal-icon-container" style="margin-bottom:18px;">
                <div class="evazar-spinner-circle" style="width:52px; height:52px; border:4px solid #f1f5f9; border-top-color:#ef394e; border-radius:50%; margin:0 auto; animation:evazarSpin 0.85s linear infinite;"></div>
            </div>
            <h3 id="evazar-modal-title" style="margin:0 0 12px; font-size:18px; font-weight:700; color:#1e293b;">در حال پردازش...</h3>
            <p id="evazar-modal-desc" style="margin:0 0 22px; font-size:13.5px; line-height:1.75; color:#64748b;">سیستم در حال برقراری ارتباط با سرور است. لطفاً منتظر بمانید...</p>
            <div id="evazar-modal-progress" style="width:100%; height:6px; background:#f1f5f9; border-radius:99px; overflow:hidden; margin-bottom:22px; position:relative;">
                <div class="evazar-bar-anim" style="width:50%; height:100%; background:linear-gradient(90deg, #ef394e, #f97316); border-radius:99px;"></div>
            </div>
            <div id="evazar-modal-actions" style="display:none; gap:12px; justify-content:center; flex-wrap:wrap;">
                <button type="button" id="evazar-modal-close-btn" class="button button-primary" style="padding:6px 24px; font-size:13px; font-weight:600; border-radius:6px;">متوجه شدم و بستن</button>
                <button type="button" id="evazar-modal-reload-btn" class="button" style="padding:6px 20px; font-size:13px; border-radius:6px;">به‌روزرسانی فوری جدول</button>
            </div>
        </div>
    </div>

    <style>
    @keyframes evazarSpin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    @keyframes evazarPulseBar { 0% { transform: translateX(120%); } 100% { transform: translateX(-120%); } }
    .evazar-bar-anim { animation: evazarPulseBar 1.6s ease-in-out infinite; }
    .evazar-ajax-action-btn:disabled { opacity: 0.6; cursor: not-allowed; }
    </style>

    <script>
    jQuery(document).ready(function($) {
        var overlay = $('#evazar-queue-modal-overlay');
        var iconContainer = $('#evazar-modal-icon-container');
        var titleEl = $('#evazar-modal-title');
        var descEl = $('#evazar-modal-desc');
        var progEl = $('#evazar-modal-progress');
        var actionsEl = $('#evazar-modal-actions');
        var closeBtn = $('#evazar-modal-close-btn');
        var reloadBtn = $('#evazar-modal-reload-btn');

        var spinnerHtml = '<div class="evazar-spinner-circle" style="width:52px; height:52px; border:4px solid #f1f5f9; border-top-color:#ef394e; border-radius:50%; margin:0 auto; animation:evazarSpin 0.85s linear infinite;"></div>';
        var successHtml = '<div style="width:56px; height:56px; border-radius:50%; background:#dcfce7; color:#16a34a; display:flex; align-items:center; justify-content:center; margin:0 auto; font-size:30px; font-weight:bold;">✓</div>';
        var errorHtml = '<div style="width:56px; height:56px; border-radius:50%; background:#fee2e2; color:#dc2626; display:flex; align-items:center; justify-content:center; margin:0 auto; font-size:30px; font-weight:bold;">✕</div>';

        $('.evazar-ajax-action-btn').on('click', function(e) {
            var btn = $(this);
            var action = btn.data('action');
            var confirmMsg = btn.data('confirm');
            var title = btn.data('title') || 'در حال اجرای عملیات...';
            var desc = btn.data('desc') || 'سیستم در حال برقراری ارتباط با سرور و پردازش داده‌هاست. لطفاً صفحه را نبندید...';

            if (confirmMsg && !window.confirm(confirmMsg)) {
                e.preventDefault();
                return false;
            }

            e.preventDefault();

            // باز کردن مودال در وضعیت در حال پردازش
            iconContainer.html(spinnerHtml);
            titleEl.text(title);
            descEl.text(desc);
            progEl.show();
            actionsEl.hide();
            overlay.css('display', 'flex');

            btn.prop('disabled', true);

            // ارسال درخواست ایجکس
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                dataType: 'json',
                timeout: 300000,
                data: {
                    action: 'evazar_queue_ajax_action',
                    action_type: action,
                    security: $('#evazar-queue-actions-form input[name="_wpnonce"]').val()
                },
                success: function(resp) {
                    btn.prop('disabled', false);
                    progEl.hide();
                    actionsEl.css('display', 'flex');

                    if (resp && resp.success) {
                        iconContainer.html(successHtml);
                        titleEl.text('عملیات با موفقیت انجام شد');
                        descEl.text(resp.data && resp.data.message ? resp.data.message : 'عملیات با موفقیت تکمیل شد.');

                        if (resp.data && resp.data.stats) {
                            var st = resp.data.stats;
                            $('a:contains("همه") .count').text('(' + st.total + ')');
                            $('a:contains("در انتظار") .count').text('(' + st.pending + ')');
                            $('a:contains("در حال پردازش") .count').text('(' + st.processing + ')');
                            $('a:contains("تکمیل شده") .count').text('(' + st.completed + ')');
                            $('a:contains("دارای خطا") .count').text('(' + st.failed + ')');
                        }

                        // ریلود خودکار پس از ۳.۵ ثانیه جهت مشاهده جدول به‌روز شده
                        setTimeout(function() {
                            window.location.reload();
                        }, 3500);
                    } else {
                        iconContainer.html(errorHtml);
                        titleEl.text('خطا در پردازش');
                        var err = (resp && resp.data && resp.data.message) ? resp.data.message : 'خطای نامشخصی در پردازش رخ داد.';
                        descEl.text(err);
                    }
                },
                error: function(xhr, status, error) {
                    btn.prop('disabled', false);
                    progEl.hide();
                    actionsEl.css('display', 'flex');
                    iconContainer.html(errorHtml);
                    titleEl.text('خطای ارتباط با سرور');
                    if (status === 'timeout') {
                        descEl.text('زمان پاسخگویی به پایان رسید، اما ممکن است فرآیند در پس‌زمینه سرور در حال تکمیل باشد. صفحه را پس از دقایقی بررسی کنید.');
                    } else {
                        descEl.text('پاسخی از سرور دریافت نشد (' + (error || status) + '). لطفاً مجدداً بررسی فرمایید.');
                    }
                }
            });
        });

        closeBtn.on('click', function() {
            overlay.hide();
            window.location.reload();
        });

        reloadBtn.on('click', function() {
            window.location.reload();
        });
    });
    </script>
    <?php
}
