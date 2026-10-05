<?php

/**
 * Plugin Name: Evazar Core (هسته اختصاصی ایوازار)
 * Description: سیستم بومی مدیریت کالاها، دیپ‌لینک‌های افیلیو دیجی‌کالا و یکپارچه‌سازی با سئو بدون نیاز به ووکامرس.
 * Version: 2.8.66
 * Author: Moblak / Antigravity
 * Text Domain: evazar-core
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// ==========================================
// 1. ثبت پست‌تایپ اختصاصی کالاها (Custom Post Type)
// ==========================================
require_once plugin_dir_path(__FILE__) . 'product-index.php';
require_once plugin_dir_path(__FILE__) . 'importer.php';
require_once plugin_dir_path(__FILE__) . 'queue-manager.php';

register_activation_hook(__FILE__, 'evazar_core_activate');
function evazar_core_activate()
{
    evazar_queue_create_table();
    evazar_queue_schedule_cron();
    evazar_stock_sync_schedule();
    evazar_product_index_ensure_tables();
    evazar_product_index_schedule_backfill();
    delete_option('evazar_cat_tree_repaired_v3');
    delete_option('evazar_core_sitemap_flushed_v4');
    flush_rewrite_rules(false);
}

register_deactivation_hook(__FILE__, 'evazar_core_deactivate');
function evazar_core_deactivate()
{
    evazar_queue_clear_cron();
    evazar_stock_sync_clear_schedule();
    evazar_product_index_clear_schedule();
}

add_action('init', 'evazar_register_product_cpt');
add_action('init', 'evazar_product_index_maybe_upgrade', 5);
function evazar_register_product_cpt()
{
    $labels = array(
        'name'                  => 'کالاها',
        'singular_name'         => 'کالا',
        'menu_name'             => 'کالاهای ایوازار',
        'add_new'               => 'افزودن کالای جدید',
        'add_new_item'          => 'افزودن کالای جدید',
        'edit_item'             => 'ویرایش کالا',
        'new_item'              => 'کالای جدید',
        'view_item'             => 'نمایش کالا',
        'search_items'          => 'جستجوی کالاها',
        'not_found'             => 'کالایی یافت نشد',
        'not_found_in_trash'    => 'کالایی در زباله‌دان یافت نشد',
        'all_items'             => 'همه کالاها'
    );

    $args = array(
        'labels'             => $labels,
        'public'             => true,
        'publicly_queryable' => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'query_var'          => true,
        'rewrite'            => array('slug' => 'product'),
        'capability_type'    => 'post',
        'has_archive'        => true,
        'hierarchical'       => false,
        'menu_position'      => 5,
        'menu_icon'          => 'dashicons-cart', // آیکون فروشگاهی
        'supports'           => array('title', 'editor', 'thumbnail', 'excerpt'),
        'show_in_rest'       => true, // پشتیبانی از گوتنبرگ
    );

    register_post_type('evazar_product', $args);

    // ثبت دسته‌بندی اختصاصی (Taxonomy)
    register_taxonomy(
        'evazar_category',
        'evazar_product',
        array(
            'labels' => array(
                'name'              => 'دسته‌بندی‌ها',
                'singular_name'     => 'دسته‌بندی',
                'menu_name'         => 'دسته‌بندی کالاها',
                'search_items'      => 'جستجوی دسته‌بندی‌ها',
                'all_items'         => 'همه دسته‌بندی‌ها',
                'parent_item'       => 'دسته والد',
                'parent_item_colon' => 'دسته والد:',
                'edit_item'         => 'ویرایش دسته‌بندی',
                'update_item'       => 'به‌روزرسانی دسته‌بندی',
                'add_new_item'      => 'افزودن دسته جدید',
                'new_item_name'     => 'نام دسته جدید',
            ),
            'rewrite'           => array('slug' => 'product-category', 'with_front' => false),
            'hierarchical'      => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
        )
    );

    // ثبت برند اختصاصی کالاها (Taxonomy)
    register_taxonomy(
        'evazar_brand',
        'evazar_product',
        array(
            'labels' => array(
                'name'              => 'برندها',
                'singular_name'     => 'برند',
                'menu_name'         => 'برندها',
                'search_items'      => 'جستجوی برندها',
                'all_items'         => 'همه برندها',
                'edit_item'         => 'ویرایش برند',
                'update_item'       => 'به‌روزرسانی برند',
                'add_new_item'      => 'افزودن برند جدید',
                'new_item_name'     => 'نام برند جدید',
            ),
            'rewrite'           => array('slug' => 'brand', 'with_front' => false),
            'hierarchical'      => false,
            'show_admin_column' => true,
            'show_in_rest'      => true,
        )
    );
}

add_action('init', 'evazar_ensure_brand_rewrites', 99);
function evazar_ensure_brand_rewrites()
{
    if (get_option('evazar_brand_rewrite_v2') !== '1') {
        flush_rewrite_rules(false);
        update_option('evazar_brand_rewrite_v2', '1');
    }
}

// قوانین ری‌رایت اختصاصی برای نقشه‌های سایت و دسترسی‌های جایگزین فروشگاه
add_action('init', 'evazar_core_register_sitemap_rewrites', 1);
function evazar_core_register_sitemap_rewrites() {
    add_rewrite_rule('^sitemap_index\.xml$', 'index.php?sitemap=1', 'top');
    add_rewrite_rule('^sitemap\.xml$', 'index.php?sitemap=1', 'top');
    add_rewrite_rule('^([^/]+?)-sitemap([0-9]+)?\.xml$', 'index.php?sitemap=$matches[1]&sitemap_n=$matches[2]', 'top');
    add_rewrite_rule('^([a-zA-Z0-9_-]+)-sitemap\.xsl$', 'index.php?xsl=$matches[1]', 'top');
    add_rewrite_rule('^main-sitemap\.xsl$', 'index.php?xsl=main', 'top');
    add_rewrite_rule('^products/?$', 'index.php?post_type=evazar_product', 'top');
    add_rewrite_rule('^shop/?$', 'index.php?post_type=evazar_product', 'top');
    add_rewrite_rule('^store/?$', 'index.php?post_type=evazar_product', 'top');
    add_rewrite_rule('^kala/?$', 'index.php?post_type=evazar_product', 'top');

    if (get_option('evazar_core_sitemap_flushed_v4') !== '1') {
        flush_rewrite_rules(false);
        update_option('evazar_core_sitemap_flushed_v4', '1');
    }
}

add_filter('query_vars', function($vars) {
    $vars[] = 'sitemap';
    $vars[] = 'sitemap_n';
    $vars[] = 'xsl';
    return $vars;
});

// رهگیری مستقیم در صورت عدم اجرای ری‌رایت
add_action('parse_request', 'evazar_core_sitemap_parse_request_handler', 1);
function evazar_core_sitemap_parse_request_handler($wp) {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $path = trim(parse_url($uri, PHP_URL_PATH), '/');

    if ($path === 'sitemap_index.xml' || $path === 'sitemap.xml') {
        $wp->query_vars['sitemap'] = '1';
        return;
    }

    if (preg_match('#^([a-zA-Z0-9_-]+?)-sitemap([0-9]+)?\.xml$#i', $path, $matches)) {
        $wp->query_vars['sitemap'] = $matches[1];
        if (!empty($matches[2])) {
            $wp->query_vars['sitemap_n'] = $matches[2];
        }
        return;
    }

    if ($path === 'main-sitemap.xsl' || preg_match('#^([a-zA-Z0-9_-]+?)-sitemap\.xsl$#i', $path, $matches)) {
        $wp->query_vars['xsl'] = !empty($matches[1]) ? $matches[1] : 'main';
        return;
    }

    if ($path === 'products' || $path === 'shop' || $path === 'store' || $path === 'kala') {
        $wp->query_vars['post_type'] = 'evazar_product';
        return;
    }
}

// شمولیت قطعی کالاهای ایوازار، دسته‌ها و برندها در نقشه سایت رنک‌مث
add_filter('rank_math/sitemap/post_types', function($post_types) {
    if (!isset($post_types['evazar_product'])) {
        $post_types['evazar_product'] = 'evazar_product';
    }
    return $post_types;
});
add_filter('rank_math/sitemap/taxonomies', function($taxonomies) {
    if (!isset($taxonomies['evazar_category'])) {
        $taxonomies['evazar_category'] = 'evazar_category';
    }
    if (!isset($taxonomies['evazar_brand'])) {
        $taxonomies['evazar_brand'] = 'evazar_brand';
    }
    return $taxonomies;
});

// همگام‌سازی و ترمیم خودکار ساختار درختی دسته‌بندی‌ها در دیتابیس
add_action('admin_init', 'evazar_auto_repair_category_tree');
function evazar_auto_repair_category_tree()
{
    if (get_option('evazar_cat_tree_repaired_v3') === '1') {
        return;
    }

    $prods = get_posts([
        'post_type'      => 'evazar_product',
        'posts_per_page' => 100,
        'post_status'    => 'any',
        'meta_query'     => [
            [
                'key'     => '_evazar_breadcrumbs',
                'compare' => 'EXISTS'
            ]
        ]
    ]);

    if (!empty($prods) && function_exists('evazar_sync_product_categories')) {
        foreach ($prods as $p) {
            $bc = get_post_meta($p->ID, '_evazar_breadcrumbs', true);
            $cat = get_post_meta($p->ID, '_evazar_category', true);
            $brand = get_post_meta($p->ID, '_evazar_brand', true);
            if (!empty($bc)) {
                evazar_sync_product_categories($p->ID, $bc, $cat, $brand);
            }
        }
    }

    update_option('evazar_cat_tree_repaired_v3', '1');
}

// ==========================================
// 2. تنظیمات افزونه (لینک عمومی افیلیو)
// ==========================================
add_action('admin_menu', 'evazar_core_add_admin_menu');
function evazar_core_add_admin_menu()
{
    add_submenu_page(
        'edit.php?post_type=evazar_product',
        'تنظیمات افیلیت ایوازار',
        'تنظیمات افیلیت',
        'manage_options',
        'evazar-affiliate-settings',
        'evazar_core_settings_page'
    );
}

add_action('admin_init', 'evazar_core_settings_init');
function evazar_core_settings_init()
{
    register_setting('evazar_core_settings_group', 'evazar_cf_worker_url', ['sanitize_callback' => 'esc_url_raw']);
    register_setting('evazar_core_settings_group', 'evazar_cf_worker_token', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('evazar_core_settings_group', 'evazar_image_cdn_domain', ['sanitize_callback' => 'esc_url_raw']);
    add_settings_section('evazar_core_worker_section', 'ورکر کلودفلر و شبکه توزیع تصاویر (Cloudflare Worker & Image CDN)', null, 'evazar-affiliate-settings');
    add_settings_field('evazar_cf_worker_url', 'آدرس ورکر کلودفلر (Worker URL)', 'evazar_cf_worker_url_render', 'evazar-affiliate-settings', 'evazar_core_worker_section');
    add_settings_field('evazar_cf_worker_token', 'توکن داخلی ارتباط با Worker', 'evazar_cf_worker_token_render', 'evazar-affiliate-settings', 'evazar_core_worker_section');
    add_settings_field('evazar_image_cdn_domain', 'دامنه توزیع و کش تصاویر (Image CDN Subdomain)', 'evazar_image_cdn_domain_render', 'evazar-affiliate-settings', 'evazar_core_worker_section');

    // Affiliate Settings
    register_setting('evazar_core_settings_group', 'evazar_base_affiliate_link');
    add_settings_section('evazar_core_main_section', 'تنظیمات لینک عمومی دیجی‌کالا (افیلیو)', null, 'evazar-affiliate-settings');
    add_settings_field('evazar_base_affiliate_link', 'لینک عمومی افیلیو (حاوی {redirect_to})', 'evazar_base_affiliate_link_render', 'evazar-affiliate-settings', 'evazar_core_main_section');
    // تنظیمات UX صفحه انتقال افیلیت فقط از پنل «تنظیمات قالب ایوازار» مدیریت می‌شود.

    // AI Settings (Fully Decoupled Dual Engine)
    register_setting('evazar_core_settings_group', 'evazar_ai_enabled');
    register_setting('evazar_core_settings_group', 'evazar_ai_taxonomy_enabled');
    register_setting('evazar_core_settings_group', 'evazar_ai_taxonomy_exclude_misc');
    register_setting('evazar_core_settings_group', 'evazar_ai_taxonomy_exclude_keywords');
    register_setting('evazar_core_settings_group', 'evazar_ai_engine_mode');
    register_setting('evazar_core_settings_group', 'evazar_openai_api_key');
    register_setting('evazar_core_settings_group', 'evazar_openai_model');
    register_setting('evazar_core_settings_group', 'evazar_openai_api_url');
    register_setting('evazar_core_settings_group', 'evazar_gemini_api_key');
    register_setting('evazar_core_settings_group', 'evazar_gemini_model');
    register_setting('evazar_core_settings_group', 'evazar_gemini_api_url');
    register_setting('evazar_core_settings_group', 'evazar_ai_provider');
    register_setting('evazar_core_settings_group', 'evazar_ai_api_key');
    register_setting('evazar_core_settings_group', 'evazar_ai_api_url');
    register_setting('evazar_core_settings_group', 'evazar_ai_model');
    register_setting('evazar_core_settings_group', 'evazar_ai_prompt');
    register_setting('evazar_core_settings_group', 'evazar_ai_product_prompt_v4');
    register_setting('evazar_core_settings_group', 'evazar_ai_product_prompt_v5');
    register_setting('evazar_core_settings_group', 'evazar_ai_product_prompt_v6');
    register_setting('evazar_core_settings_group', 'evazar_ai_product_prompt_v7');
    register_setting('evazar_core_settings_group', 'evazar_ai_category_prompt_v4');
    register_setting('evazar_core_settings_group', 'evazar_ai_brand_prompt_v4');
    register_setting('evazar_core_settings_group', 'evazar_ai_timeout');

    add_settings_section('evazar_core_ai_section', 'هوش مصنوعی (AI) - تولید خودکار محتوای کالا، دسته و برند', null, 'evazar-affiliate-settings');
    add_settings_field('evazar_ai_enabled', 'وضعیت سیستم AI کالاها', 'evazar_ai_enabled_render', 'evazar-affiliate-settings', 'evazar_core_ai_section');
    add_settings_field('evazar_ai_taxonomy_enabled', 'تولید محتوا برای دسته و برند', 'evazar_ai_taxonomy_enabled_render', 'evazar-affiliate-settings', 'evazar_core_ai_section');
    add_settings_field('evazar_ai_taxonomy_exclude_misc', 'استثنای دسته‌های متفرقه از تولید محتوا', 'evazar_ai_taxonomy_exclude_misc_render', 'evazar-affiliate-settings', 'evazar_core_ai_section');
    add_settings_field('evazar_ai_engine_mode', 'موتور و استراتژی پردازش هوش مصنوعی', 'evazar_ai_engine_mode_render', 'evazar-affiliate-settings', 'evazar_core_ai_section');
    add_settings_field('evazar_openai_settings_box', 'پیکربندی اختصاصی OpenAI (ChatGPT)', 'evazar_openai_settings_render', 'evazar-affiliate-settings', 'evazar_core_ai_section');
    add_settings_field('evazar_gemini_settings_box', 'پیکربندی اختصاصی Google Gemini', 'evazar_gemini_settings_render', 'evazar-affiliate-settings', 'evazar_core_ai_section');
    add_settings_field('evazar_ai_timeout', 'مهلت زمانی پاسخ هوش مصنوعی (ثانیه)', 'evazar_ai_timeout_render', 'evazar-affiliate-settings', 'evazar_core_ai_section');
    add_settings_field('evazar_ai_prompt', 'لحن و پرامپت بازنویسی توضیحات کالاها', 'evazar_ai_prompt_render', 'evazar-affiliate-settings', 'evazar_core_ai_section');
    add_settings_field('evazar_ai_category_prompt', 'پرامپت راهنمای خرید دسته‌بندی‌ها', 'evazar_ai_category_prompt_render', 'evazar-affiliate-settings', 'evazar_core_ai_section');
    add_settings_field('evazar_ai_brand_prompt', 'پرامپت معرفی و راهنمای خرید برندها', 'evazar_ai_brand_prompt_render', 'evazar-affiliate-settings', 'evazar_core_ai_section');

    // تنظیمات متاتگ‌ها و سئوی رنک‌مث کالاها
    register_setting('evazar_core_settings_group', 'evazar_product_title_template', [
        'sanitize_callback' => 'sanitize_text_field'
    ]);
    register_setting('evazar_core_settings_group', 'evazar_product_desc_template', [
        'sanitize_callback' => 'sanitize_textarea_field'
    ]);

    add_settings_section('evazar_core_seo_section', 'الگوهای سئو و عناوین متای کالا در گوگل (Rank Math & Meta Title Patterns)', null, 'evazar-affiliate-settings');
    add_settings_field('evazar_product_title_template', 'الگوی عنوان متای کالاها (Meta Title)', 'evazar_product_title_template_render', 'evazar-affiliate-settings', 'evazar_core_seo_section');
    add_settings_field('evazar_product_desc_template', 'الگوی توضیحات متای پیش‌فرض کالا (Meta Description)', 'evazar_product_desc_template_render', 'evazar-affiliate-settings', 'evazar_core_seo_section');
}

function evazar_cf_worker_url_render()
{
    $url = get_option('evazar_cf_worker_url', '');
    echo '<input type="url" name="evazar_cf_worker_url" value="' . esc_attr($url) . '" style="width: 100%; max-width: 600px; direction: ltr;" placeholder="https://evazar-digikala-worker.yourname.workers.dev">';
    echo '<p class="description">آدرس ورکر مستقر در کلودفلر (کد <code>digikala-worker.js</code>). دریافت قیمت/موجودی و واردات از طریق همین مسیر انجام می‌شود؛ TTL در نسخه جدید کوتاه و قابل کنترل است.</p>';
}

function evazar_cf_worker_token_render()
{
    $token = get_option('evazar_cf_worker_token', '');
    echo '<input type="password" name="evazar_cf_worker_token" value="' . esc_attr($token) . '" style="width: 100%; max-width: 600px; direction: ltr;" autocomplete="new-password" placeholder="یک توکن تصادفی و طولانی">';
    echo '<p class="description">این توکن باید دقیقاً در Secret ورکر با نام <code>EVAZAR_INTERNAL_TOKEN</code> قرار گیرد. برای استعلام محصول و مسیرهای داخلی استفاده می‌شود و نباید در کد فرانت‌اند قرار گیرد.</p>';
}

function evazar_image_cdn_domain_render()
{
    $domain = get_option('evazar_image_cdn_domain', 'https://img.evazar.ir');
    echo '<input type="url" name="evazar_image_cdn_domain" value="' . esc_attr($domain) . '" style="width: 100%; max-width: 600px; direction: ltr;" placeholder="https://img.evazar.ir">';
    echo '<p class="description">آدرس ساب‌دامنه اختصاصی تصاویر کلودفلر (پیش‌فرض: <code>https://img.evazar.ir</code>). با اتصال این ساب‌دامنه به ورکر، تصاویر با کش لبه‌ای ۱ ساله کلودفلر و برند ایوازار لود می‌شوند.</p>';
}

function evazar_base_affiliate_link_render()
{
    $link = get_option('evazar_base_affiliate_link', '');
    echo '<input type="text" name="evazar_base_affiliate_link" value="' . esc_attr($link) . '" style="width: 100%; max-width: 600px; direction: ltr;" placeholder="https://publisher.affilio.ir/click?utm_data=...&landing={redirect_to}">';
    echo '<p class="description">سیستم در صفحه کالا، لینک دیجی‌کالا را انکود کرده و جایگزین <code>{redirect_to}</code> می‌کند.</p>';
}


function evazar_ai_enabled_render()
{
    $val = get_option('evazar_ai_enabled', '');
    echo '<label><input type="checkbox" name="evazar_ai_enabled" value="yes" ' . checked($val, 'yes', false) . '> فعال‌سازی تولید و بازنویسی توضیحات کالا با هوش مصنوعی در هنگام واردات</label>';
}

function evazar_ai_taxonomy_enabled_render()
{
    $val = get_option('evazar_ai_taxonomy_enabled', 'yes');
    echo '<label><input type="checkbox" name="evazar_ai_taxonomy_enabled" value="yes" ' . checked($val, 'yes', false) . '> تولید خودکار مقاله راهنمای خرید سئو شده برای دسته‌بندی‌ها و برندهای جدید (فقط یک‌بار هنگام ساخت)</label>';
    echo '<p class="description">برای جلوگیری از اتلاف توکن، این مقاله فقط یک‌بار هنگام ساخته شدن دسته/برند تولید می‌شود و برای کالاهای بعدی تکرار نمی‌شود.</p>';
}

function evazar_ai_taxonomy_exclude_misc_render()
{
    $val = get_option('evazar_ai_taxonomy_exclude_misc', 'yes');
    $keywords = get_option('evazar_ai_taxonomy_exclude_keywords', 'متفرقه, miscellaneous, عمومی');
    echo '<label><input type="checkbox" name="evazar_ai_taxonomy_exclude_misc" value="yes" ' . checked($val, 'yes', false) . '> <strong>عدم تولید محتوای هوش مصنوعی برای دسته‌بندی‌ها یا برندهای متفرقه و بدون برند</strong></label>';
    echo '<p class="description" style="margin-top: 8px;">کلمات کلیدی استثنا (با کاما انگلیسی <code>,</code> جدا کنید):</p>';
    echo '<input type="text" name="evazar_ai_taxonomy_exclude_keywords" value="' . esc_attr($keywords) . '" style="width: 100%; max-width: 500px;" placeholder="متفرقه, miscellaneous, عمومی">';
    echo '<p class="description">اگر عنوان یا نامک (اسلاگ) دسته/برند شامل این کلمات باشد (مانند «کیف و کاور گوشی متفرقه»)، سیستم هوش مصنوعی به هیچ وجه برای آن محتوا تولید نکرده و توکنی مصرف نمی‌شود.</p>';
}

function evazar_ai_engine_mode_render()
{
    $val = get_option('evazar_ai_engine_mode', '');
    if (empty($val)) {
        $legacy = get_option('evazar_ai_provider', 'openai');
        $val = ($legacy === 'gemini') ? 'gemini_fallback_openai' : 'openai_fallback_gemini';
    }
    echo '<select name="evazar_ai_engine_mode" style="min-width: 380px; font-weight: bold; font-size: 13px;">';
    echo '<option value="openai_only" ' . selected($val, 'openai_only', false) . '>۱. صرفاً OpenAI (اختصاصی و کاملاً ایزوله بدون دخالت جیمنای)</option>';
    echo '<option value="gemini_only" ' . selected($val, 'gemini_only', false) . '>۲. صرفاً Google Gemini (مستقل، اقتصادی و ارزان)</option>';
    echo '<option value="gemini_fallback_openai" ' . selected($val, 'gemini_fallback_openai', false) . '>۳. دوگانه هوشمند (توصیه‌شده): اولویت Gemini؛ در صورت سقف سهمیه (429) سوییچ به OpenAI</option>';
    echo '<option value="openai_fallback_gemini" ' . selected($val, 'openai_fallback_gemini', false) . '>۴. دوگانه هوشمند: اولویت OpenAI؛ در صورت اتمام شارژ سوییچ به Gemini</option>';
    echo '</select>';
    echo '<p class="description" style="margin-top: 6px;">انتخاب استراتژی پردازش. در حالت‌های دوگانه، اگر یکی از سرویس‌ها به سقف سهمیه (429 Rate Limit) برخورد کند، سیستم بدون توقف فرآیند واردات، فوراً با موتور دوم محتوا را بازنویسی می‌کند.</p>';
}

function evazar_openai_settings_render()
{
    $key_val = get_option('evazar_openai_api_key', '');
    if (empty(trim($key_val))) {
        $legacy = get_option('evazar_ai_api_key', '');
        if (function_exists('evazar_extract_api_keys_by_prefix')) {
            $extracted = evazar_extract_api_keys_by_prefix($legacy, 'openai');
            if (!empty($extracted)) {
                $key_val = implode("\n", $extracted);
            }
        }
    }

    $model_val = get_option('evazar_openai_model', '');
    if (empty($model_val) || strpos($model_val, 'gemini') !== false) {
        $legacy_model = get_option('evazar_ai_model', '');
        $model_val = (!empty($legacy_model) && strpos($legacy_model, 'gemini') === false) ? $legacy_model : 'gpt-4o-mini';
    }

    $url_val = get_option('evazar_openai_api_url', '');
    if (empty($url_val)) {
        $legacy_url = get_option('evazar_ai_api_url', '');
        if (!empty($legacy_url) && strpos($legacy_url, 'googleapis') === false) {
            $url_val = $legacy_url;
        }
    }

    $models = [
        'gpt-4o-mini' => 'GPT-4o Mini (سریع‌ترین، بسیار باکیفیت و با حداقل هزینه توکن - استاندارد جهانی)',
        'gpt-4o'      => 'GPT-4o (بالاترین کیفیت تولید متن و استدلال)',
        'gpt-6-luna'  => 'GPT-6 Luna (سریع و اقتصادی ویژه کارهای پرحجم - در صورت فعال بودن در اکانت)',
        'gpt-6.1-sol' => 'GPT-6.1 Sol (مدل تعادلی جدید)',
        'gpt-3.5-turbo' => 'GPT-3.5 Turbo (مدل سبک کلاسیک)',
    ];

    echo '<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-right: 4px solid #10a37f; border-radius: 8px; padding: 16px; max-width: 750px;">';
    echo '<h4 style="margin: 0 0 12px 0; color: #0f172a; display: flex; align-items: center; gap: 8px;"><span style="background: #10a37f; color: #fff; padding: 2px 8px; border-radius: 4px; font-size: 11px;">OpenAI Engine</span> کلیدها و پیکربندی اختصاصی OpenAI</h4>';
    
    echo '<p style="margin: 0 0 6px 0; font-weight: 600; font-size: 13px;">کلیدهای API اختصاصی OpenAI (هر خط یک کلید با پیشوند <code>sk-</code>):</p>';
    echo '<textarea name="evazar_openai_api_key" style="width: 100%; height: 90px; direction: ltr; font-family: monospace; font-size: 12px;" placeholder="sk-proj-...&#10;sk-...">' . esc_textarea($key_val) . '</textarea>';
    echo '<p class="description" style="margin-top: 4px;">سیستم به صورت خودکار بین کلیدهای OpenAI چرخش (Rotation) انجام می‌دهد.</p>';

    echo '<div style="margin-top: 14px;">';
    echo '<label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px;">مدل هوش مصنوعی OpenAI:</label>';
    echo '<select name="evazar_openai_model" style="min-width: 320px; direction: ltr; font-weight: 500;">';
    foreach ($models as $k => $v) {
        echo '<option value="' . esc_attr($k) . '" ' . selected($model_val, $k, false) . '>' . esc_html($v) . '</option>';
    }
    echo '</select>';
    echo '<p class="description" style="margin-top: 4px;">پیشنهاد قطعی: <code>GPT-4o Mini</code> با هزینه بسیار ناچیز و سرعت فوق‌العاده بالا.</p>';
    echo '</div>';

    echo '<div style="margin-top: 14px;">';
    echo '<label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px;">اندپوینت اختصاصی / پراکسی OpenAI (اختیاری):</label>';
    echo '<input type="url" name="evazar_openai_api_url" value="' . esc_attr($url_val) . '" style="width: 100%; direction: ltr; font-family: monospace;" placeholder="پیش‌فرض: پروکسی ورکر کلودفلر ایوازار">';
    echo '<p class="description" style="margin-top: 4px;">در حالت پیش‌فرض درخواست‌ها از ورکر کلودفلر ایوازار عبور می‌کنند تا تحریم آی‌پی ایران و مسدودسازی کامل دور زده شود.</p>';
    echo '</div>';

    echo '</div>';
}

function evazar_gemini_settings_render()
{
    $key_val = get_option('evazar_gemini_api_key', '');
    if (empty(trim($key_val))) {
        $legacy = get_option('evazar_ai_api_key', '');
        if (function_exists('evazar_extract_api_keys_by_prefix')) {
            $extracted = evazar_extract_api_keys_by_prefix($legacy, 'gemini');
            if (!empty($extracted)) {
                $key_val = implode("\n", $extracted);
            }
        }
    }

    $model_val = get_option('evazar_gemini_model', '');
    if (empty($model_val) || strpos($model_val, 'gemini') === false) {
        $legacy_model = get_option('evazar_ai_model', '');
        $model_val = (!empty($legacy_model) && strpos($legacy_model, 'gemini') !== false) ? $legacy_model : 'gemini-3.5-flash-lite';
    }

    $url_val = get_option('evazar_gemini_api_url', '');

    $models = [
        'gemini-3.5-flash-lite' => 'Google Gemini 3.5 Flash Lite (سریع‌ترین مدل، سهمیه روزانه بالا و اقتصادی)',
        'gemini-2.5-flash'      => 'Google Gemini 2.5 Flash (استدلال متنی عالی)',
        'gemini-2.0-flash'      => 'Google Gemini 2.0 Flash',
        'gemini-1.5-flash'      => 'Google Gemini 1.5 Flash',
    ];

    echo '<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-right: 4px solid #1a73e8; border-radius: 8px; padding: 16px; max-width: 750px;">';
    echo '<h4 style="margin: 0 0 12px 0; color: #0f172a; display: flex; align-items: center; gap: 8px;"><span style="background: #1a73e8; color: #fff; padding: 2px 8px; border-radius: 4px; font-size: 11px;">Google Gemini</span> کلیدها و پیکربندی اختصاصی Google Gemini</h4>';
    
    echo '<p style="margin: 0 0 6px 0; font-weight: 600; font-size: 13px;">کلیدهای API گوگل جیمنای (هر خط یک کلید با پیشوند <code>AIza</code> یا <code>AQ.</code>):</p>';
    echo '<textarea name="evazar_gemini_api_key" style="width: 100%; height: 90px; direction: ltr; font-family: monospace; font-size: 12px;" placeholder="AIzaSy...&#10;AQ....">' . esc_textarea($key_val) . '</textarea>';
    echo '<p class="description" style="margin-top: 4px;">امکان وارد کردن چندین کلید جیمنای جهت چرخش خودکار کلیدها در صورت مواجهه با خطای سهمیه (429).</p>';

    echo '<div style="margin-top: 14px;">';
    echo '<label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px;">مدل Google Gemini:</label>';
    echo '<select name="evazar_gemini_model" style="min-width: 320px; direction: ltr; font-weight: 500;">';
    foreach ($models as $k => $v) {
        echo '<option value="' . esc_attr($k) . '" ' . selected($model_val, $k, false) . '>' . esc_html($v) . '</option>';
    }
    echo '</select>';
    echo '<p class="description" style="margin-top: 4px;">پیشنهاد پیش‌فرض: <code>Gemini 3.5 Flash Lite</code> سازگار با پراکسی ورکر کلودفلر.</p>';
    echo '</div>';

    echo '<div style="margin-top: 14px;">';
    echo '<label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px;">اندپوینت اختصاصی / پراکسی Gemini (اختیاری):</label>';
    echo '<input type="url" name="evazar_gemini_api_url" value="' . esc_attr($url_val) . '" style="width: 100%; direction: ltr; font-family: monospace;" placeholder="پیش‌فرض: اتصال هوشمند بدون تحریم از طریق ورکر کلودفلر ایوازار">';
    echo '<p class="description" style="margin-top: 4px;">در حالت عادی خالی بگذارید تا از پروکسی ورکر استفاده شود.</p>';
    echo '</div>';

    echo '</div>';
}

function evazar_ai_timeout_render()
{
    $val = intval(get_option('evazar_ai_timeout', 60));
    if ($val < 30) $val = 60;
    echo '<input type="number" name="evazar_ai_timeout" value="' . esc_attr($val) . '" min="30" max="180" step="5" style="width: 100px; text-align: center;">';
    echo '<p class="description" style="margin-top: 4px;">حداکثر مهلت زمان انتظار برای دریافت پاسخ هوش مصنوعی (پیش‌فرض: ۶۰ ثانیه). در کالاهای با مشخصات فنی مفصل مانند لپ‌تاپ یا موبایل، مقادیر کمتر از ۴۵ ثانیه می‌تواند موجب خطای cURL error 28 شود.</p>';
}

function evazar_ai_provider_render() { evazar_ai_engine_mode_render(); }
function evazar_ai_api_key_render() {}
function evazar_ai_api_url_render() {}
function evazar_ai_model_render() {}

function evazar_get_default_ai_product_prompt()
{
    return "شما یک کپی‌رایتر ارشد تجارت الکترونیک، نویسنده تخصصی نقد و بررسی محصولات و استراتژیست ارشد سئو هستید.\n"
         . "وظیفه شما بازنویسی، تحلیل و خلق یک متن نقد و بررسی تخصصی، کاملاً روان، خواندنی و چندپاراگرافی برای صفحه محصول در فروشگاه اینترنتی بر پایه داده‌ها و مشخصات ورودی است.\n\n"
         . "قوانین و استانداردهای کپی‌رایتینگ تخصصی (متن پویا، پیوسته و بدون تیترهای کلیشه‌ای):\n\n"
         . "۱. عدم درج هرگونه عنوان، سرفصل یا برچسب (Zero Headers / No Titles):\n"
         . "- اکیداً هیچ‌گونه عنوان، تیتر، سرفصل، علامت هدر مارک‌داون (مانند ###) یا کلمات برچسبی ابتدای خط (مانند «معرفی:»، «طراحی:»، «عملکرد:»، «منبع تغذیه:»، «جمع‌بندی:») ننویسید.\n"
         . "- متن باید یک مقاله یکدست، ارگانیک، شیک و پیوسته باشد که موضوعات به صورت کاملاً طبیعی، روان و با ادبیاتی گیرا در قالب چند بند (پاراگراف) مستقل و به هم پیوسته از پی هم بیایند.\n\n"
         . "۲. ساختار چندپاراگرافی پویا متناسب با پتانسیل واقعی کالا (اصل تناسب و پویایی کامل):\n"
         . "- تعداد و حجم پاراگراف‌ها باید کاملاً پویا و متناسب با عمق و پتانسیل اطلاعات کالا شکل بگیرد:\n"
         . "  * برای کالای ساده یا کم‌مشخصات: ۲ تا ۳ پاراگراف روان، کاربردی و منسجم بنویس.\n"
         . "  * برای کالای غنی، فنی یا پرمشخصات: ۳ تا ۵ پاراگراف عمیق، تحلیلی و جامع بنویس.\n"
         . "- پاراگراف‌ها با دابل اینتر (\\n\\n) از هم تفکیک شوند تا چیدمان سایت کاملاً منظم و خوانا باشد.\n"
         . "- جریان طبیعی محتوا: بند اول از معرفی هویت و کاربرد اصلی کالا شروع شود، بندهای میانی ابعاد، ارگونومی، عملکرد فنی، متریال و کارایی واقعی را کالبدشکافی کنند و بند پایانی به ارزیابی نهایی ارزش خرید و توصیه کاربری اختصاص یابد.\n\n"
         . "۳. وفاداری کامل به مشخصات واقعی و عدم تخیل (Zero Hallucination):\n"
         . "- تمام اعداد، ابعاد، مدل، جنس، ظرفیت، مدت زمان شارژ/شارژدهی، رنگ، اقلام همراه و ویژگی‌های فنی را دقیقاً بر پایه داده‌های ورودی حفظ کن.\n"
         . "- از اختراع مشخصات ناموجود اکیداً خودداری کن؛ اما مزایا و کارکرد مشخصات واقعی را برای خریدار تبیین نما.\n"
         . "- نام مارکت‌پلیس‌های دیگر (مانند دیجی‌کالا و...)، نام فروشندگان، شرایط ارسال متفرقه یا قیمت‌های قید شده در متن مبدا را کاملاً حذف کن.\n\n"
         . "۴. هنر کپی‌رایتینگ و تبدیل ویژگی به منفعت ملموس:\n"
         . "- برای هر مشخصه فنی، کاربرد ملموس آن در زندگی خریدار را بیان کن (مشاور خرید آگاه، بی‌طرف و حرفه‌ای).\n"
         . "- از جملات بازاریابی کلیشه‌ای و پوچ (مانند «اگر به دنبال بهترین هستید»، «انتخابی بی‌نظیر») اکیداً خودداری کن.\n"
         . "- متن باید کاملاً روان با رعایت دقیق نیم‌فاصله‌ها و نگارش استاندارد و اصیل زبان فارسی باشد.\n\n"
         . "۵. یکدستی ارقام و نگارش ابعاد (صرفاً برای ابعاد فیزیکی، نه نام مدل‌ها):\n"
         . "- در نوشتن ابعاد و اندازه‌های فیزیکی کالا (مانند طول، عرض، ارتفاع، ضخامت) اکیداً از حرف انگلیسی x استفاده نکن؛ بلکه منحصراً از علامت ضربدر استاندارد (×) استفاده کن و تمام ارقام آن را به صورت یکدست فارسی (مانند ۱۶۰ × ۱۱۰ × ۶۵) بنویس.\n"
         . "- استثنای بسیار حیاتی: حرف انگلیسی X در نام مدل‌ها، پارت‌نامبرها، کد کالاها یا قطعات سخت‌افزاری (مانند Vivobook 16 X1605 یا iPhone X یا پردازنده‌ها و کارت‌های گرافیک) جزئی از نام کالا است و اکیداً نباید به علامت ضربدر (×) تبدیل شود.\n"
         . "- از ترکیب ارقام انگلیسی و فارسی در یک عدد (مانند ۳0000 یا ۱۶0) شدیداً پرهیز کن و تمام اعداد متنی را به صورت ارقام فارسی یکدست بنویس.";
}

function evazar_ai_prompt_render()
{
    $default_prompt = evazar_get_default_ai_product_prompt();
    $val = get_option('evazar_ai_product_prompt_v7', '');
    
    // انتقال خودکار از نسخه‌های قبلی یا جایگزینی در صورت وجود محدودیت یا تیترهای ثابت در دیتابیس
    if (empty($val)) {
        $old_v6 = get_option('evazar_ai_product_prompt_v6', '');
        if (empty($old_v6) || strpos($old_v6, '۲۶۰') !== false || strpos($old_v6, '260') !== false || strpos($old_v6, '۱۰۰') !== false || strpos($old_v6, '100') !== false || strpos($old_v6, 'متنی بسیار فشرده') !== false || strpos($old_v6, 'سرفصل‌های موضوعی') !== false || strpos($old_v6, '###') !== false) {
            $val = $default_prompt;
        } else {
            $val = $old_v6;
        }
        update_option('evazar_ai_product_prompt_v7', $val);
    }

    $js_default_prompt = json_encode($default_prompt, JSON_UNESCAPED_UNICODE);

    echo '<div style="max-width: 820px;">';
    echo '<textarea id="evazar_ai_product_prompt_input" name="evazar_ai_product_prompt_v7" style="width: 100%; height: 280px; font-size: 13px; line-height: 1.6; padding: 10px; border-radius: 6px; border: 1px solid #cbd5e1;">' . esc_textarea($val) . '</textarea>';
    echo '<div style="margin-top: 8px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">';
    echo '<button type="button" class="button" onclick="if(confirm(\'آیا از بازنشانی پرامپت به نسخه پویا، چندپاراگرافی و بدون تیترهای کلیشه‌ای مطمئن هستید؟\')){ document.getElementById(\'evazar_ai_product_prompt_input\').value = ' . $js_default_prompt . '; }">🔄 بازنشانی به پرامپت پیش‌فرض جدید (پویا و بدون تیترهای کلیشه‌ای)</button>';
    echo '<span style="color: #059669; font-size: 12px; font-weight: 500;">✓ تولید نقد و بررسی پویا، چندپاراگرافی و بدون عناوین تکراری فعال است</span>';
    echo '</div>';
    echo '<p class="description" style="margin-top: 6px;">این متن به عنوان دستورالعمل پایه بازنویسی کالا به هوش مصنوعی داده می‌شود. در صورت نیاز می‌توانید با دکمه بالا آن را به آخرین نسخه استاندارد بازنشانی کنید.</p>';
    echo '</div>';
}

function evazar_ai_category_prompt_render()
{
    $default_prompt = "شما یک کارشناس سئو و راهنمای خرید برای فروشگاه اینترنتی «{site_name}» هستید.\n"
                    . "نام این دسته‌بندی محصولات «{term_name}» است و اطلاعات پایه آن در بازار بدین شرح است:\n{source_content}\n\n"
                    . "دستورالعمل نگارش (اصل تناسب و عدم تخیل):\n"
                    . "۱. طول و عمق متن خروجی را دقیقاً متناسب با متن ورودی تنظیم کنید. به هیچ عنوان داستان‌سرایی و حدس نزنید و برای طولانی کردن متن به آن آب نبندید.\n"
                    . "۲. برای دسته‌های جانبی یا با اطلاعات کم، بدون کش دادن اضافه، متنی کاربردی، مفید و متناسب با داده‌های موجود بنویسید.\n"
                    . "۳. برای دسته‌های اصلی و پرمحصول، معیارهای مهم خرید و ویژگی‌های شاخص را با تیترهای H2 و H3 به شکل شکیل مرتب کنید.\n"
                    . "۴. به هیچ وجه نام «دیجی‌کالا» یا هیچ مارکت‌پلیس دیگری ذکر نشود؛ مرجع بررسی و خرید «{site_name}» است.\n"
                    . "خروجی را در قالب کدهای HTML تمیز و استاندارد (با تگ‌های <h2>، <h3>، <p>، <ul>، <li>) بدون تگ‌های اضافی html و body برگردانید.";

    $val = get_option('evazar_ai_category_prompt_v4', '');
    if (empty($val)) $val = $default_prompt;
    echo '<textarea name="evazar_ai_category_prompt_v4" style="width: 100%; max-width: 800px; height: 210px;">' . esc_textarea($val) . '</textarea>';
    echo '<p class="description">متغیرهای پویا: <code>{term_name}</code> (نام دسته‌بندی)، <code>{source_content}</code> (متن استخراج شده از دیجی‌کالا)، <code>{site_name}</code> (نام سایت).</p>';
}

function evazar_ai_brand_prompt_render()
{
    $default_prompt = "شما یک ویراستار ارشد و کارشناس سئو برای فروشگاه اینترنتی «{site_name}» هستید.\n"
                    . "نام این برند «{term_name}» است و داده‌های واقعی آن در بازار به این شرح است:\n{source_content}\n\n"
                    . "دستورالعمل نگارش (اصل تناسب و عدم تخیل):\n"
                    . "۱. طول و عمق متن خروجی باید کاملاً متناسب با حجم اطلاعات موجود در متن منبع باشد. به هیچ وجه برای افزایش تعداد کلمات به متن آب نبندید و هیچ داستان، تاریخچه، جوایز یا محصولاتی اختراع نکنید.\n"
                    . "۲. اگر اطلاعات پایه کوتاه است، صرفاً به صورت روان، کاربردی و در اندازه همان اطلاعات موجود بازنویسی کنید.\n"
                    . "۳. اگر اطلاعات پایه جامع است، آن را با تیترهای جذاب H2 و H3، نکات کلیدی و دسته‌بندی منظم ساختاردهی کنید.\n"
                    . "۴. اکیداً این برند را با برندهای مشابه در صنایع دیگر اشتباه نگیرید.\n"
                    . "۵. به هیچ عنوان نام دیجی‌کالا ذکر نشود؛ مرجع بررسی و خرید «{site_name}» است.\n"
                    . "خروجی را در قالب کدهای HTML استاندارد و شکیل (با تگ‌های <h2>، <h3>، <p>، <ul>، <li>) بدون تگ‌های اضافی html و body برگردانید.";

    $val = get_option('evazar_ai_brand_prompt_v4', '');
    if (empty($val)) $val = $default_prompt;
    echo '<textarea name="evazar_ai_brand_prompt_v4" style="width: 100%; max-width: 800px; height: 210px;">' . esc_textarea($val) . '</textarea>';
    echo '<p class="description">متغیرهای پویا: <code>{term_name}</code> (نام برند)، <code>{source_content}</code> (متن استخراج شده از دیجی‌کالا)، <code>{site_name}</code> (نام سایت).</p>';
}

function evazar_product_title_template_render()
{
    $default = 'قیمت و خرید {title} | {site_name}';
    $val = get_option('evazar_product_title_template', $default);
    echo '<input type="text" name="evazar_product_title_template" value="' . esc_attr($val) . '" style="width: 100%; max-width: 650px;" placeholder="' . esc_attr($default) . '">';
    echo '<p class="description" style="margin-top: 8px; line-height: 1.8;">';
    echo '<strong>متغیرهای مجاز (با کلیک کپی می‌شوند):</strong><br>';
    echo '<code style="background:#e0f2fe; color:#0369a1; padding:2px 6px; border-radius:4px; cursor:pointer;" onclick="navigator.clipboard.writeText(\'{title}\'); alert(\'کپی شد: {title}\');">{title}</code> (نام کامل کالا) ، ';
    echo '<code style="background:#e0f2fe; color:#0369a1; padding:2px 6px; border-radius:4px; cursor:pointer;" onclick="navigator.clipboard.writeText(\'{site_name}\'); alert(\'کپی شد: {site_name}\');">{site_name}</code> (نام سایت: ایوازار) ، ';
    echo '<code style="background:#e0f2fe; color:#0369a1; padding:2px 6px; border-radius:4px; cursor:pointer;" onclick="navigator.clipboard.writeText(\'{brand}\'); alert(\'کپی شد: {brand}\');">{brand}</code> (برند کالا) ، ';
    echo '<code style="background:#e0f2fe; color:#0369a1; padding:2px 6px; border-radius:4px; cursor:pointer;" onclick="navigator.clipboard.writeText(\'{category}\'); alert(\'کپی شد: {category}\');">{category}</code> (دسته‌بندی) ، ';
    echo '<code style="background:#e0f2fe; color:#0369a1; padding:2px 6px; border-radius:4px; cursor:pointer;" onclick="navigator.clipboard.writeText(\'{price}\'); alert(\'کپی شد: {price}\');">{price}</code> (قیمت فعلی کالا به تومان)';
    echo '</p>';
    echo '<p class="description" style="color: #64748b; font-size: 12px; line-height: 1.7; background: #f8fafc; border: 1px dashed #cbd5e1; padding: 10px; border-radius: 6px;">';
    echo '<strong>الگوهای پیشنهادی پرکاربرد:</strong><br>';
    echo '• <code>قیمت و خرید {title} | {site_name}</code> (مشابه دیجی‌کالا و بیشترین نرخ کلیک خرید کاربران)<br>';
    echo '• <code>{title} - بررسی، مشخصات و قیمت روز | {site_name}</code> (مدل مرجع بررسی تخصصی کالا)<br>';
    echo '• <code>خرید {title} با تضمین اصالت و بهترین قیمت | {site_name}</code>';
    echo '</p>';
}

function evazar_product_desc_template_render()
{
    $default = 'قیمت و خرید آنلاین {title} با بهترین قیمت روز و مشخصات در {site_name}. نقد و بررسی و ضمانت اصالت کالا.';
    $val = get_option('evazar_product_desc_template', $default);
    echo '<textarea name="evazar_product_desc_template" style="width: 100%; max-width: 650px; height: 60px;" placeholder="' . esc_attr($default) . '">' . esc_textarea($val) . '</textarea>';
    echo '<p class="description">این متن زمانی که هوش مصنوعی توضیحات متای یونیک تولید نکرده باشد یا فیلد متا خالی باشد به کار می‌رود. متغیرهای <code>{title}</code>، <code>{site_name}</code>، <code>{brand}</code>، <code>{category}</code> و <code>{price}</code> پشتیبانی می‌شوند.</p>';
}

function evazar_core_settings_page()
{
?>
    <div class="wrap" style="direction: rtl;">
        <h1>تنظیمات هسته افیلیت ایوازار</h1>
        <form action="options.php" method="post">
            <?php
            settings_fields('evazar_core_settings_group');
            do_settings_sections('evazar-affiliate-settings');
            submit_button('ذخیره تغییرات');
            ?>
        </form>
        
        <?php do_action('evazar_core_settings_page_bottom'); ?>
    </div>
<?php
}

// ==========================================
// 3. افزودن متاباکس‌های اختصاصی به صفحه کالا
// ==========================================
add_action('add_meta_boxes', 'evazar_core_add_product_metaboxes');
function evazar_core_add_product_metaboxes()
{
    add_meta_box(
        'evazar_product_data',
        'اطلاعات اختصاصی کالا (قیمت، افیلیت و بررسی)',
        'evazar_core_product_metabox_html',
        'evazar_product', // متصل فقط به پست‌تایپ اختصاصی ما
        'normal',
        'high'
    );
}

function evazar_core_product_metabox_html($post)
{
    // قیمت‌ها
    $old_price = get_post_meta($post->ID, '_evazar_old_price', true);
    $current_price = get_post_meta($post->ID, '_evazar_current_price', true);
    $discount_percent = get_post_meta($post->ID, '_evazar_discount_percent', true);

    // لینک و دیتا
    $dk_url = get_post_meta($post->ID, '_evazar_dk_url', true);
    $rating = get_post_meta($post->ID, '_evazar_rating', true);
    $rating_count = get_post_meta($post->ID, '_evazar_rating_count', true);
    $satisfaction = get_post_meta($post->ID, '_evazar_satisfaction', true);
    $en_title = get_post_meta($post->ID, '_evazar_en_title', true);

    // داده‌های سئو رنک‌مث (Rank Math SEO)
    $rm_title = get_post_meta($post->ID, 'rank_math_title', true);
    $rm_desc  = get_post_meta($post->ID, 'rank_math_description', true);
    $rm_kw    = get_post_meta($post->ID, 'rank_math_focus_keyword', true);

    // محتوا
    $pros = get_post_meta($post->ID, '_evazar_pros', true);
    $cons = get_post_meta($post->ID, '_evazar_cons', true);
    $features = get_post_meta($post->ID, '_evazar_key_features', true);

    // تصاویر کالا
    $main_img = get_post_meta($post->ID, '_evazar_main_image', true);
    $gallery_raw = get_post_meta($post->ID, '_evazar_gallery', true);
    $gallery = !empty($gallery_raw) ? (is_array($gallery_raw) ? $gallery_raw : explode(',', $gallery_raw)) : [];

    wp_nonce_field('evazar_product_save_nonce', 'evazar_nonce');
?>
    <style>
        .evazar-mb-row {
            margin-bottom: 15px;
        }

        .evazar-mb-row label {
            display: block;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .evazar-mb-row input[type="text"],
        .evazar-mb-row textarea {
            width: 100%;
            max-width: 100%;
        }

        .evazar-mb-row textarea {
            height: 80px;
        }

        .evazar-mb-desc {
            font-size: 12px;
            color: #666;
            margin-top: 4px;
        }

        .evazar-grid-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
        }

        .evazar-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
    </style>

    <div class="evazar-mb-row" style="background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
        <label style="color: #0f172a; margin-bottom: 8px; display: flex; align-items: center; justify-content: space-between;">
            <span>📸 تصاویر کالا (معماری ذخیره‌سازی صفر هاست - Zero Host Storage)</span>
            <span style="font-size: 11px; background: #dcfce7; color: #166534; padding: 2px 8px; border-radius: 12px; font-weight: 600;">⚡ بدون مصرف فضای هاست</span>
        </label>
        <p class="evazar-mb-desc" style="margin-bottom: 10px; color: #475569;">تصاویر به صورت مستقیم از شبکه توزیع محتوای دیجی‌کالا (CDN) لود می‌شوند و هیچ فایل تصویری روی هاست شما ذخیره نمی‌شود.</p>
        <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 10px;">
            <?php if (!empty($main_img)): ?>
                <div style="text-align: center;">
                    <img src="<?php echo esc_url($main_img); ?>" style="width: 80px; height: 80px; object-fit: contain; border-radius: 8px; border: 2px solid #ef394e; background: #fff;" loading="lazy">
                    <div style="font-size: 10px; color: #ef394e; font-weight: bold; margin-top: 3px;">تصویر اصلی</div>
                </div>
            <?php endif; ?>
            <?php foreach ($gallery as $g_img):
                $g_img = trim($g_img);
                if (empty($g_img) || $g_img === $main_img) continue;
            ?>
                <div style="text-align: center;">
                    <img src="<?php echo esc_url($g_img); ?>" style="width: 70px; height: 70px; object-fit: contain; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff;" loading="lazy">
                    <div style="font-size: 10px; color: #64748b; margin-top: 3px;">گالری</div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($main_img) && empty($gallery)): ?>
                <span style="color: #94a3b8; font-size: 13px;">تصویری برای این کالا ثبت نشده است.</span>
            <?php endif; ?>
        </div>
        <div>
            <label style="font-size: 12px; color: #475569;">آدرس مستقیم CDN تصویر اصلی:</label>
            <input type="text" name="evazar_main_image" value="<?php echo esc_attr($main_img); ?>" placeholder="https://dkstatics-public.digikala.com/..." dir="ltr" style="font-size: 11px;">
        </div>
    </div>

    <div class="evazar-mb-row">
        <label>لینک ساده کالا در دیجی‌کالا (منبع کالا):</label>
        <input type="text" name="evazar_dk_url" value="<?php echo esc_attr($dk_url); ?>" placeholder="https://www.digikala.com/product/dkp-12345/" dir="ltr">
    </div>

    <div class="evazar-mb-row">
        <label>عنوان انگلیسی کالا (زیرتیتر):</label>
        <input type="text" name="evazar_en_title" value="<?php echo esc_attr($en_title); ?>" placeholder="Apple iPhone 13 Pro Max" dir="ltr">
    </div>

    <div class="evazar-grid-3 evazar-mb-row">
        <div>
            <label>قیمت اصلی (تومان - اختیاری):</label>
            <input type="text" name="evazar_old_price" value="<?php echo esc_attr($old_price); ?>" placeholder="مثلا: 10,000,000">
        </div>
        <div>
            <label>قیمت نهایی با تخفیف (تومان):</label>
            <input type="text" name="evazar_current_price" value="<?php echo esc_attr($current_price); ?>" placeholder="مثلا: 8,500,000">
        </div>
        <div>
            <label>بج تخفیف (درصد یا متن):</label>
            <input type="text" name="evazar_discount_percent" value="<?php echo esc_attr($discount_percent); ?>" placeholder="مثلا: ۱۵٪ تخفیف">
        </div>
    </div>

    <div class="evazar-grid-3 evazar-mb-row" style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
        <div>
            <label>امتیاز کالا (مثلا ۴.۴):</label>
            <input type="text" name="evazar_rating" value="<?php echo esc_attr($rating); ?>" placeholder="4.4">
        </div>
        <div>
            <label>تعداد امتیازدهندگان / خریداران:</label>
            <input type="number" name="evazar_rating_count" value="<?php echo esc_attr($rating_count); ?>" placeholder="74">
        </div>
        <div>
            <label>درصد رضایت (مثلا ۹۴٪):</label>
            <input type="text" name="evazar_satisfaction" value="<?php echo esc_attr($satisfaction); ?>" placeholder="۹۴٪">
        </div>
    </div>

    <div class="evazar-mb-row">
        <label>ویژگی‌های کلیدی (هر ویژگی در یک خط):</label>
        <textarea name="evazar_key_features"><?php echo esc_textarea($features); ?></textarea>
    </div>

    <div class="evazar-mb-row">
        <label>ویژگی‌ها و نقاط قوت کلیدی کالا (Highlights & Pros) - هر مورد یک خط:</label>
        <textarea name="evazar_pros" style="height: 100px;"><?php echo esc_textarea($pros); ?></textarea>
        <input type="hidden" name="evazar_cons" value="">
    </div>

    <!-- بخش اختصاصی سئو و رنک‌مث -->
    <div class="evazar-mb-row" style="background: #f0fdf4; padding: 16px; border-radius: 8px; border: 1px solid #bbf7d0; margin-top: 15px;">
        <label style="color: #166534; margin-bottom: 8px; display: flex; align-items: center; justify-content: space-between;">
            <span>🚀 تنظیمات سئو و متادیتاهای رنک‌مث (Rank Math Integration)</span>
            <span style="font-size: 11px; background: #22c55e; color: #fff; padding: 2px 8px; border-radius: 12px; font-weight: 600;">همگام با Rank Math</span>
        </label>
        <p class="evazar-mb-desc" style="margin-bottom: 12px; color: #15803d; font-size: 12px;">
            این فیلدها مستقیماً با افزونه Rank Math همگام هستند. در صورت خالی بودن، به طور خودکار از هوش مصنوعی یا فرمول طلایی سئو استفاده می‌شود.
        </p>

        <div style="margin-bottom: 12px;">
            <label style="font-size: 12px; color: #334155;">عنوان اختصاصی سئو در گوگل (Meta Title):</label>
            <input type="text" name="rank_math_title" value="<?php echo esc_attr($rm_title); ?>" placeholder="مثلاً: قیمت و خرید <?php echo esc_attr(get_the_title($post->ID)); ?> | <?php echo esc_attr(get_bloginfo('name') ?: 'ایوازار'); ?>">
            <div class="evazar-mb-desc">در صورت خالی بودن، به صورت خودکار از فرمول بهینه رنک‌مث استفاده خواهد شد.</div>
        </div>

        <div style="margin-bottom: 12px;">
            <label style="font-size: 12px; color: #334155;">توضیحات متای سئو (Meta Description - حداکثر ۱۶۰ کاراکتر):</label>
            <textarea name="rank_math_description" style="height: 65px;" placeholder="توضیحات کوتاه و ترغیب‌کننده سئو برای نمایش در صفحه جستجوی گوگل..."><?php echo esc_textarea($rm_desc); ?></textarea>
            <div class="evazar-mb-desc">این متن توسط هوش مصنوعی یا به صورت هوشمند پر می‌شود و چراغ سئوی رنک‌مث را سبز می‌کند.</div>
        </div>

        <div>
            <label style="font-size: 12px; color: #334155;">کلمه کلیدی کانونی (Focus Keyword):</label>
            <input type="text" name="rank_math_focus_keyword" value="<?php echo esc_attr($rm_kw); ?>" placeholder="مثلاً: خرید گوشی سامسونگ A55">
        </div>
    </div>
<?php
}

// ذخیره داده‌های متاباکس
add_action('save_post_evazar_product', 'evazar_core_save_product_metaboxes');
function evazar_core_save_product_metaboxes($post_id)
{
    if (!isset($_POST['evazar_nonce']) || !wp_verify_nonce($_POST['evazar_nonce'], 'evazar_product_save_nonce')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $fields = [
        'evazar_dk_url',
        'evazar_en_title',
        'evazar_old_price',
        'evazar_current_price',
        'evazar_discount_percent',
        'evazar_rating',
        'evazar_rating_count',
        'evazar_satisfaction',
        'evazar_key_features',
        'evazar_pros',
        'evazar_cons',
        'evazar_main_image'
    ];

    foreach ($fields as $field) {
        if (isset($_POST[$field])) {
            if (in_array($field, ['evazar_key_features', 'evazar_pros', 'evazar_cons'])) {
                $value = sanitize_textarea_field($_POST[$field]);
            } else {
                $value = sanitize_text_field($_POST[$field]);
            }
            update_post_meta($post_id, '_' . $field, $value);
        }
    }

    // ذخیره فیلدهای بومی رنک‌مث (Rank Math Native Meta Keys)
    if (isset($_POST['rank_math_title'])) {
        update_post_meta($post_id, 'rank_math_title', sanitize_text_field($_POST['rank_math_title']));
    }
    if (isset($_POST['rank_math_description'])) {
        update_post_meta($post_id, 'rank_math_description', sanitize_textarea_field($_POST['rank_math_description']));
    }
    if (isset($_POST['rank_math_focus_keyword'])) {
        update_post_meta($post_id, 'rank_math_focus_keyword', sanitize_text_field($_POST['rank_math_focus_keyword']));
    }
}


// ==========================================
// 4. صفحه انتقال افیلیت + رهگیری کلیک
// ==========================================
add_action('init', 'evazar_register_affiliate_redirect_route', 5);
function evazar_register_affiliate_redirect_route()
{
    add_rewrite_rule('^go/([0-9]+)/?$', 'index.php?evazar_aff_go=$matches[1]', 'top');
    if (get_option('evazar_affiliate_redirect_rewrite_v1') !== '1') {
        flush_rewrite_rules(false);
        update_option('evazar_affiliate_redirect_rewrite_v1', '1', false);
    }
}

add_filter('query_vars', function ($vars) {
    $vars[] = 'evazar_aff_go';
    return $vars;
});

function evazar_affiliate_click_table_name()
{
    global $wpdb;
    return $wpdb->prefix . 'evazar_affiliate_clicks';
}

function evazar_affiliate_click_table_install()
{
    global $wpdb;
    $table = evazar_affiliate_click_table_name();
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        product_id bigint(20) unsigned NOT NULL,
        seller varchar(100) NOT NULL DEFAULT 'دیجی‌کالا',
        click_date date NOT NULL,
        clicks bigint(20) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY product_seller_day (product_id, seller, click_date),
        KEY click_date (click_date),
        KEY product_id (product_id)
    ) {$charset};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

function evazar_affiliate_click_table_maybe_install()
{
    if (get_option('evazar_affiliate_click_table_v1') === '1') return;
    evazar_affiliate_click_table_install();
    update_option('evazar_affiliate_click_table_v1', '1', false);
}
add_action('init', 'evazar_affiliate_click_table_maybe_install', 6);

function evazar_record_affiliate_click($post_id)
{
    $post_id = absint($post_id);
    if (!$post_id || get_post_type($post_id) !== 'evazar_product') return;

    global $wpdb;
    $table = evazar_affiliate_click_table_name();
    $seller = get_post_meta($post_id, '_evazar_seller_name', true) ?: 'دیجی‌کالا';
    $seller = mb_substr(sanitize_text_field($seller), 0, 100);
    $date = current_time('Y-m-d');

    // یک ردیف برای هر محصول/فروشنده/روز؛ مناسب برای ترافیک بالا و بدون ذخیره IP.
    $sql = $wpdb->prepare(
        "INSERT INTO {$table} (product_id, seller, click_date, clicks) VALUES (%d, %s, %s, 1)
         ON DUPLICATE KEY UPDATE clicks = clicks + 1",
        $post_id, $seller, $date
    );
    $wpdb->query($sql);
}

function evazar_affiliate_redirect_countdown_enabled()
{
    return get_option('evazar_affiliate_redirect_countdown_enabled', '1') === '1';
}

function evazar_affiliate_redirect_delay()
{
    $delay = (int) get_option('evazar_affiliate_redirect_delay', 2);
    return max(1, min(5, $delay));
}

function evazar_affiliate_redirect_font_config()
{
    $font = get_option('evazar_opt_font', 'vazirmatn');
    switch ($font) {
        case 'yekanbakh':
            return [
                'family' => "'YekanBakh', sans-serif",
                'file'   => '/assets/fonts/yekanbakh/YekanBakhFaNum-Regular.woff2',
            ];
        case 'system':
            return [
                'family' => "system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Tahoma,Arial,sans-serif",
                'file'   => '',
            ];
        case 'vazirmatn':
        default:
            return [
                'family' => "'Vazirmatn', sans-serif",
                'file'   => '/assets/fonts/vazirmatn/VazirmatnVF.woff2',
            ];
    }
}

function evazar_affiliate_redirect_output($post_id, $target_url)
{
    $delay = evazar_affiliate_redirect_delay();
    $title = get_option('evazar_affiliate_redirect_title', 'در حال انتقال به دیجی‌کالا');
    $description = get_option('evazar_affiliate_redirect_description', 'لینک خرید شما آماده است؛ تا چند لحظه دیگر به سایت فروشنده منتقل می‌شوید.');
    $button = get_option('evazar_affiliate_redirect_button', 'انتقال فوری');
    $seller = get_post_meta($post_id, '_evazar_seller_name', true) ?: 'دیجی‌کالا';
    $seller = sanitize_text_field($seller);
    $font = evazar_affiliate_redirect_font_config();
    $theme_version = wp_get_theme()->get('Version');
    $theme_uri = get_stylesheet_directory_uri();
    $font_file_url = !empty($font['file']) ? get_theme_file_uri(ltrim($font['file'], '/')) : '';
    $fonts_css_url = get_theme_file_uri('assets/css/fonts.css');
    $primary = get_option('evazar_opt_primary_color', '#ef394e');
    if (!is_string($primary) || !preg_match('/^#[0-9a-fA-F]{6}$/', $primary)) $primary = '#ef394e';

    $target = esc_url($target_url);
    $title_esc = esc_html($title);
    $description_esc = esc_html($description);
    $button_esc = esc_html($button);
    $seller_esc = esc_html($seller);
    $delay_ms = $delay * 1000;
    $redirect_json = wp_json_encode($target_url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $delay_json = wp_json_encode($delay_ms);
    $font_family_json = wp_json_encode($font['family']);
    $persian_digits = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $delay_fa = strtr((string) $delay, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);

    status_header(200);
    nocache_headers();
    header('X-Robots-Tag: noindex, nofollow, noarchive', true);
    header('Referrer-Policy: strict-origin-when-cross-origin', true);
    header('Content-Security-Policy: default-src \'none\'; style-src \'self\' \'unsafe-inline\'; script-src \'unsafe-inline\'; font-src \'self\' data:; img-src \'self\' data:; base-uri \'none\'; frame-ancestors \'none\';', true);

    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
    echo '<meta name="color-scheme" content="light">';
    echo '<meta http-equiv="refresh" content="' . esc_attr($delay) . ';url=' . esc_attr($target) . '">';
    echo '<title>' . $title_esc . ' | ایوازار</title>';
    if (!empty($font['file'])) {
        echo '<link rel="preload" href="' . esc_url($theme_uri . $font['file']) . '?ver=' . rawurlencode($theme_version) . '" as="font" type="font/woff2" crossorigin="anonymous">';
    }
    echo '<link rel="stylesheet" href="' . esc_url($fonts_css_url) . '?ver=' . rawurlencode($theme_version) . '">';

    $redirect_css = ':root{--evz-primary:' . $primary . ';--evz-font:' . $font['family'] . ';--evz-duration:' . esc_attr($delay_ms) . 'ms}'
        . '*{box-sizing:border-box}'
        . 'html{direction:rtl;text-align:right}'
        . 'body{direction:rtl;text-align:right;unicode-bidi:plaintext}'
        . 'html,body{margin:0;min-width:0;min-height:100%;width:100%;font-family:var(--evz-font),sans-serif;background:#f8fafc;color:#0f172a}'
        . 'body{min-height:100svh;display:flex;align-items:center;justify-content:center;padding:clamp(16px,4vw,32px);overflow-x:hidden}'
        . '.evz-redirect{width:min(100%,560px);background:#fff;border:1px solid #e2e8f0;border-radius:clamp(16px,4vw,24px);box-shadow:0 18px 45px rgba(15,23,42,.08);padding:clamp(22px,5vw,34px) clamp(18px,5vw,30px);text-align:center;overflow:hidden}'
        . '.evz-logo{width:clamp(52px,14vw,62px);height:clamp(52px,14vw,62px);margin:0 auto 16px;border-radius:18px;background:#fff1f2;color:var(--evz-primary);display:flex;align-items:center;justify-content:center}'
        . '.evz-logo svg{width:clamp(27px,8vw,32px);height:clamp(27px,8vw,32px)}'
        . '.evz-title{font-size:clamp(18px,4.8vw,24px);line-height:1.6;font-weight:800;margin:0 0 10px;overflow-wrap:anywhere}'
        . '.evz-desc{font-size:clamp(13px,3.6vw,15px);line-height:2;color:#64748b;margin:0 auto 16px;max-width:440px;overflow-wrap:anywhere}'
        . '.evz-seller{display:flex;align-items:center;justify-content:center;flex-wrap:wrap;gap:7px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:999px;padding:8px 12px;font-size:clamp(11px,3.1vw,12px);color:#475569;margin:0 auto 20px;max-width:100%;overflow-wrap:anywhere}'
        . '.evz-count-wrap{display:flex;align-items:center;justify-content:center;min-height:clamp(56px,15vw,72px)}'
        . '.evz-count{font-size:clamp(46px,13vw,66px);line-height:1;font-weight:900;color:var(--evz-primary);margin:2px 0 14px}'
        . '.evz-progress{height:7px;background:#f1f5f9;border-radius:999px;overflow:hidden;margin:0 0 20px;direction:rtl;position:relative;transform:translateZ(0)}'
        . '.evz-progress>i{display:block;position:absolute;inset-block:0;inset-inline-start:0;width:0;height:100%;background:var(--evz-primary);animation:evzGrow var(--evz-duration) linear forwards}'
        . '.evz-btn{display:flex;align-items:center;justify-content:center;width:100%;min-height:46px;padding:12px 18px;background:var(--evz-primary);color:#fff;text-decoration:none;border:0;border-radius:12px;font-size:clamp(13px,3.8vw,15px);font-weight:800;line-height:1.4;touch-action:manipulation;transition:transform .15s ease,filter .15s ease}'
        . '.evz-btn:hover{filter:brightness(.95);transform:translateY(-1px)}.evz-btn:focus-visible{outline:3px solid rgba(2,132,199,.25);outline-offset:3px}'
        . '.evz-note{font-size:clamp(10px,2.9vw,12px);line-height:1.8;color:#94a3b8;margin-top:13px;overflow-wrap:anywhere}'
        . '@keyframes evzGrow{from{width:0}to{width:100%}}'
        . '@media(max-width:380px){body{padding:10px}.evz-redirect{padding:20px 14px;border-radius:16px}.evz-seller{border-radius:14px}.evz-btn{min-height:48px}}'
        . '@media(prefers-reduced-motion:reduce){.evz-progress>i{animation:none;width:100%}.evz-btn{transition:none}}';
    echo '<style>' . $redirect_css . '</style></head><body>';
    echo '<main class="evz-redirect" aria-labelledby="evz-title">';
    echo '<div class="evz-logo" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3l7 4v5c0 4.4-3 8.5-7 9-4-.5-7-4.6-7-9V7l7-4z"/><path d="M9.5 12l1.8 1.8L15 10"/></svg></div>';
    echo '<h1 class="evz-title" id="evz-title">' . $title_esc . '</h1>';
    echo '<p class="evz-desc">' . $description_esc . '</p>';
    echo '<div class="evz-seller"><span>فروشنده:</span><strong>' . $seller_esc . '</strong></div>';
    echo '<div class="evz-count-wrap"><div class="evz-count" id="evz-count" dir="rtl" aria-label="ثانیه باقی‌مانده" aria-live="polite" aria-atomic="true">' . esc_html($delay_fa) . '</div></div>';
    echo '<div class="evz-progress" aria-hidden="true"><i></i></div>';
    echo '<a class="evz-btn" id="evz-now" href="' . $target . '">' . $button_esc . '</a>';
    echo '<div class="evz-note">شما برای تکمیل خرید به سایت فروشنده منتقل می‌شوید.</div>';
    echo '</main>';
    echo '<script>(function(){var t=' . $delay_json . ',u=' . $redirect_json . ',c=document.getElementById("evz-count"),a=document.getElementById("evz-now");function toFa(v){return String(v).replace(/[0-9]/g,function(d){return "۰۱۲۳۴۵۶۷۸۹"[Number(d)]});}if(a)a.addEventListener("click",function(){window.location.replace(u)});var end=Date.now()+t;function tick(){var left=Math.max(0,end-Date.now()),sec=Math.ceil(left/1000);if(c)c.textContent=toFa(sec);if(left<=0){window.location.replace(u);return}setTimeout(tick,100)}tick();})();</script>';
    echo '</body></html>';
    exit;
}

add_action('template_redirect', 'evazar_handle_affiliate_redirect', 1);
function evazar_handle_affiliate_redirect()
{
    $post_id = absint(get_query_var('evazar_aff_go'));
    if (!$post_id) return;
    if (get_post_type($post_id) !== 'evazar_product' || get_post_status($post_id) !== 'publish') {
        status_header(404);
        nocache_headers();
        wp_safe_redirect(home_url('/'), 302);
        exit;
    }

    $target = evazar_get_final_affiliate_link($post_id);
    if (empty($target)) {
        $target = get_post_meta($post_id, '_evazar_dk_url', true);
    }
    if (empty($target)) {
        $target = home_url('/');
    }

    evazar_record_affiliate_click($post_id);
    if (!evazar_affiliate_redirect_countdown_enabled()) {
        $redirect_target = esc_url_raw($target);
        $parsed = wp_parse_url($redirect_target);
        if (!empty($parsed['scheme']) && in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
            wp_redirect($redirect_target, 302, 'Evazar Affiliate');
            exit;
        }
    }
    evazar_affiliate_redirect_output($post_id, $target);
}

function evazar_get_affiliate_redirect_url($post_id = null)
{
    if (!$post_id) $post_id = get_the_ID();
    $post_id = absint($post_id);
    if (!$post_id || get_post_type($post_id) !== 'evazar_product') return '';
    return home_url('/go/' . $post_id . '/');
}

// ==========================================
// 4. تابع کمکی تولید لینک نهایی افیلیت
// ==========================================
function evazar_get_final_affiliate_link($post_id = null)
{
    if (!$post_id) $post_id = get_the_ID();
    $base_link = get_option('evazar_base_affiliate_link', '');
    $dk_url = get_post_meta($post_id, '_evazar_dk_url', true);

    if (empty($dk_url)) $dk_url = 'https://www.digikala.com/';
    if (empty($base_link) || strpos($base_link, '{redirect_to}') === false) return $dk_url;

    return str_replace('{redirect_to}', urlencode($dk_url), $base_link);
}

/**
 * تبدیل آدرس تصویر به CDN کلودفلر و ساخت نسخه اندازه‌گذاری‌شده بدون ذخیره فایل روی هاست.
 */
if (!function_exists('evazar_cdn_image_url')) {
    function evazar_cdn_image_url($url, $size = null)
    {
        if (empty($url) || !is_string($url)) return '';

        $original_url = $url;
        $cdn_host = rtrim(trim(get_option('evazar_image_cdn_domain', 'https://img.evazar.ir')), '/');
        $is_digikala_image = stripos($url, 'dkstatics-public.digikala.com') !== false;
        $is_evazar_cdn = ($cdn_host !== '' && stripos($url, $cdn_host) === 0);

        if ($is_digikala_image && $cdn_host !== '') {
            $url = str_replace(
                ['https://dkstatics-public.digikala.com', 'http://dkstatics-public.digikala.com'],
                $cdn_host,
                $url
            );
            $is_evazar_cdn = true;
        }

        $size = is_numeric($size) ? absint($size) : 0;
        if ($size > 0 && $size <= 2000 && $is_evazar_cdn) {
            $parts = wp_parse_url($url);
            if (is_array($parts) && !empty($parts['host'])) {
                $query = [];
                if (!empty($parts['query'])) {
                    parse_str($parts['query'], $query);
                }
                // Preserve an existing OSS transformation so archive/product visuals do not change.
                // Only add our size variant when the source URL has no x-oss-process directive.
                if (empty($query['x-oss-process'])) {
                    $query['x-oss-process'] = 'image/resize,m_lfit,h_' . $size . ',w_' . $size;
                }
                $encoded_query = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
                $scheme = !empty($parts['scheme']) ? $parts['scheme'] . '://' : '';
                $host = $parts['host'];
                $port = !empty($parts['port']) ? ':' . $parts['port'] : '';
                $path = isset($parts['path']) ? $parts['path'] : '';
                $fragment = !empty($parts['fragment']) ? '#' . $parts['fragment'] : '';
                $url = $scheme . $host . $port . $path . ($encoded_query !== '' ? '?' . $encoded_query : '') . $fragment;
            }
        }

        return $url ?: $original_url;
    }
}

// ==========================================
// 5. ستون‌های اختصاصی لیست کالاها در پیشخوان (Zero Host Storage)
// ==========================================
add_filter('manage_evazar_product_posts_columns', 'evazar_product_custom_admin_columns');
function evazar_product_custom_admin_columns($columns)
{
    $new_cols = [];
    foreach ($columns as $k => $title) {
        if ($k === 'title') {
            $new_cols['evazar_img'] = 'تصویر کالا';
        }
        $new_cols[$k] = $title;
    }
    $new_cols['evazar_price'] = 'قیمت فروش';
    $new_cols['evazar_discount'] = 'تخفیف';
    $new_cols['evazar_source'] = 'منبع کالا';
    return $new_cols;
}

add_action('manage_evazar_product_posts_custom_column', 'evazar_product_render_admin_columns', 10, 2);
function evazar_product_render_admin_columns($column, $post_id)
{
    if ($column === 'evazar_img') {
        $img = get_post_meta($post_id, '_evazar_main_image', true);
        if ($img) {
            $cdn_img = evazar_cdn_image_url($img);
            echo '<img src="' . esc_url($cdn_img) . '" style="width: 46px; height: 46px; object-fit: contain; border-radius: 6px; border: 1px solid #e2e8f0; background: #fff;" loading="lazy">';
        } else {
            echo '<span style="color:#94a3b8; font-size:11px;">بدون عکس</span>';
        }
    } elseif ($column === 'evazar_price') {
        $p = get_post_meta($post_id, '_evazar_current_price', true);
        echo $p ? '<strong>' . number_format((float)$p) . '</strong> <span style="font-size:10px; color:#64748b;">تومان</span>' : '—';
    } elseif ($column === 'evazar_discount') {
        $d = get_post_meta($post_id, '_evazar_discount_percent', true);
        echo $d ? '<span style="background:#fee2e2; color:#b91c1c; padding:2px 6px; border-radius:4px; font-size:11px; font-weight:bold;">' . esc_html($d) . '</span>' : '—';
    } elseif ($column === 'evazar_source') {
        $dk = get_post_meta($post_id, '_evazar_dk_url', true);
        if ($dk) {
            echo '<a href="' . esc_url($dk) . '" target="_blank" class="button button-small" style="font-size:11px;">دیجی‌کالا ↗</a>';
        } else {
            echo '—';
        }
    }
}

// فیلترهای مجازی تصویر شاخص (Zero Host Storage Virtual Thumbnail)
add_filter('has_post_thumbnail', 'evazar_core_virtual_has_thumbnail', 10, 3);
function evazar_core_virtual_has_thumbnail($has, $post, $thumbnail_id)
{
    if ($has) return true;
    $pid = is_object($post) ? $post->ID : $post;
    if ($pid && get_post_type($pid) === 'evazar_product') {
        $img = get_post_meta($pid, '_evazar_main_image', true);
        if (!empty($img)) return true;
    }
    return $has;
}

add_filter('post_thumbnail_html', 'evazar_core_virtual_thumbnail_html', 10, 5);
function evazar_core_virtual_thumbnail_html($html, $post_id, $post_thumbnail_id, $size, $attr)
{
    if (!empty($html)) return $html;
    if (get_post_type($post_id) === 'evazar_product') {
        $img = get_post_meta($post_id, '_evazar_main_image', true);
        if (!empty($img)) {
            $class = isset($attr['class']) ? esc_attr($attr['class']) : 'attachment-' . esc_attr($size) . ' size-' . esc_attr($size) . ' wp-post-image';
            $alt = esc_attr(get_the_title($post_id));
            return '<img src="' . esc_url($img) . '" class="' . $class . '" alt="' . $alt . '" loading="lazy" />';
        }
    }
    return $html;
}

// ==========================================
// 6. ویرایشگر پیشرفته متنی (Visual WYSIWYG Editor) برای توضیحات برندها و دسته‌بندی‌ها
// ==========================================
// حذف فیلترهای محدودکننده تگ‌های HTML در توضیحات تاکسونومی‌ها
remove_filter('pre_term_description', 'wp_filter_kses');
remove_filter('term_description', 'wp_kses_data');

add_action('evazar_brand_edit_form_fields', 'evazar_taxonomy_rich_editor_custom', 1, 2);
add_action('evazar_category_edit_form_fields', 'evazar_taxonomy_rich_editor_custom', 1, 2);
function evazar_taxonomy_rich_editor_custom($term, $taxonomy)
{
?>
    <tr class="form-field term-description-wrap" id="evazar-custom-editor-row">
        <th scope="row"><label for="description">توضیحات و محتوای سئو (ویرایشگر پیشرفته):</label></th>
        <td>
            <?php
            $content = !empty($term->description) ? html_entity_decode($term->description, ENT_QUOTES, 'UTF-8') : '';
            $settings = array(
                'textarea_name' => 'description',
                'textarea_rows' => 12,
                'editor_class'  => 'evazar-rich-desc',
                'media_buttons' => true,
                'tinymce'       => array(
                    'toolbar1' => 'formatselect,bold,italic,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink,undo,redo,wp_adv',
                    'toolbar2' => 'strikethrough,hr,forecolor,pastetext,removeformat,charmap,outdent,indent,fullscreen'
                ),
                'quicktags'     => true
            );
            wp_editor($content, 'evazar_term_desc_editor', $settings);
            ?>
            <p class="description">از ویرایشگر متنی پیشرفته برای قالب‌بندی متون، تعریف تیترها (H2، H3)، لیست‌ها، لینک‌های داخلی و کلمات کلیدی سئو استفاده فرمایید.</p>
            <script>
                jQuery(document).ready(function($) {
                    $('tr.term-description-wrap').not('#evazar-custom-editor-row').remove();
                });
            </script>
        </td>
    </tr>
<?php
}

add_action('evazar_brand_add_form_fields', 'evazar_taxonomy_add_rich_editor', 10, 1);
add_action('evazar_category_add_form_fields', 'evazar_taxonomy_add_rich_editor', 10, 1);
function evazar_taxonomy_add_rich_editor($taxonomy)
{
?>
    <div class="form-field term-description-wrap" id="evazar-add-editor-wrap">
        <label for="description">توضیحات و محتوای سئو:</label>
        <?php
        $settings = array(
            'textarea_name' => 'description',
            'textarea_rows' => 6,
            'media_buttons' => false,
            'tinymce'       => true,
            'quicktags'     => true
        );
        wp_editor('', 'evazar_add_desc_editor', $settings);
        ?>
        <p class="description">توضیحات اولیه سئو برای این برگه آرشیو.</p>
        <script>
            jQuery(document).ready(function($) {
                $('.form-field.term-description-wrap').not('#evazar-add-editor-wrap').remove();
            });
        </script>
    </div>
<?php
}

// افزودن فیلدهای متای سئو (عنوان و توضیحات متا) به فرم ویرایش تاکسونومی‌های دسته و برند
add_action('evazar_brand_edit_form_fields', 'evazar_taxonomy_seo_meta_edit_fields', 20, 2);
add_action('evazar_category_edit_form_fields', 'evazar_taxonomy_seo_meta_edit_fields', 20, 2);
function evazar_taxonomy_seo_meta_edit_fields($term, $taxonomy)
{
    $meta_title = get_term_meta($term->term_id, '_evazar_meta_title', true);
    $meta_desc  = get_term_meta($term->term_id, '_evazar_meta_description', true);
    ?>
    <tr class="form-field evazar-seo-meta-title-row">
        <th scope="row"><label for="evazar_meta_title">عنوان متای اختصاصی سئو (Meta Title):</label></th>
        <td>
            <input type="text" name="evazar_meta_title" id="evazar_meta_title" value="<?php echo esc_attr($meta_title); ?>" style="width: 100%; max-width: 650px;" placeholder="مثال: خرید و قیمت روز انواع <?php echo esc_attr($term->name); ?> | ایوازار">
            <p class="description">عنوان دلخواه سئو برای این برگه در تب مرورگر و نتایج گوگل. <strong>در صورت خالی بودن</strong>، مستقیماً از الگوی مرجع در تنظیمات ایوازار خوانده می‌شود.</p>
        </td>
    </tr>
    <tr class="form-field evazar-seo-meta-desc-row">
        <th scope="row"><label for="evazar_meta_description">توضیحات متای اختصاصی سئو (Meta Description):</label></th>
        <td>
            <textarea name="evazar_meta_description" id="evazar_meta_description" rows="3" style="width: 100%; max-width: 650px;" placeholder="توضیحات اختصاصی سئو برای نتایج گوگل (حداکثر ۱۶۰ کاراکتر)..."><?php echo esc_textarea($meta_desc); ?></textarea>
            <p class="description">توضیحات متای گوگل اختصاصی برای این دسته/برند. <strong>در صورت خالی بودن</strong>، به طور خودکار از الگوی مرجع تنظیمات تولید می‌شود.</p>
        </td>
    </tr>
    <?php
}

add_action('evazar_brand_add_form_fields', 'evazar_taxonomy_seo_meta_add_fields', 20, 1);
add_action('evazar_category_add_form_fields', 'evazar_taxonomy_seo_meta_add_fields', 20, 1);
function evazar_taxonomy_seo_meta_add_fields($taxonomy)
{
    ?>
    <div class="form-field evazar-seo-meta-title-wrap">
        <label for="evazar_meta_title">عنوان متای اختصاصی سئو (Meta Title):</label>
        <input type="text" name="evazar_meta_title" id="evazar_meta_title" value="" placeholder="خالی = خواندن از الگوی تنظیمات مرجع">
        <p class="description">در صورت خالی بودن، مستقیماً از تنظیمات مرجع خوانده می‌شود.</p>
    </div>
    <div class="form-field evazar-seo-meta-desc-wrap">
        <label for="evazar_meta_description">توضیحات متای اختصاصی سئو (Meta Description):</label>
        <textarea name="evazar_meta_description" id="evazar_meta_description" rows="3" placeholder="خالی = تولید خودکار از الگوی تنظیمات مرجع"></textarea>
        <p class="description">در صورت خالی بودن، به طور خودکار از الگوی تنظیمات مرجع ساخته می‌شود.</p>
    </div>
    <?php
}

add_action('edited_evazar_brand', 'evazar_save_taxonomy_seo_meta_fields');
add_action('edited_evazar_category', 'evazar_save_taxonomy_seo_meta_fields');
add_action('create_evazar_brand', 'evazar_save_taxonomy_seo_meta_fields');
add_action('create_evazar_category', 'evazar_save_taxonomy_seo_meta_fields');
function evazar_save_taxonomy_seo_meta_fields($term_id)
{
    if (isset($_POST['evazar_meta_title'])) {
        $meta_title = sanitize_text_field(wp_unslash($_POST['evazar_meta_title']));
        if (!empty($meta_title)) {
            update_term_meta($term_id, '_evazar_meta_title', $meta_title);
            update_term_meta($term_id, 'rank_math_title', $meta_title);
        } else {
            delete_term_meta($term_id, '_evazar_meta_title');
            delete_term_meta($term_id, 'rank_math_title');
        }
    }
    if (isset($_POST['evazar_meta_description'])) {
        $meta_desc = sanitize_textarea_field(wp_unslash($_POST['evazar_meta_description']));
        if (!empty($meta_desc)) {
            update_term_meta($term_id, '_evazar_meta_description', $meta_desc);
            update_term_meta($term_id, 'rank_math_description', $meta_desc);
        } else {
            delete_term_meta($term_id, '_evazar_meta_description');
            delete_term_meta($term_id, 'rank_math_description');
        }
    }
}

// ==========================================================================
// 6. هماهنگ‌سازی عمیق و بومی با رنک‌مث (Rank Math Deep SEO & Schema Engine)
// ==========================================================================

// الف) فعال‌سازی متاباکس و تحلیل‌های رنک‌مث در پست‌تایپ کالاها
add_filter('rank_math/metabox/post_types', 'evazar_rm_enable_metabox');
function evazar_rm_enable_metabox($post_types) {
    if (!in_array('evazar_product', $post_types)) {
        $post_types[] = 'evazar_product';
    }
    return $post_types;
}

// ب) گنجاندن قطعی پست‌تایپ کالاها در نقشه سایت رنک‌مث (XML Sitemap)
add_filter('rank_math/sitemap/post_types', 'evazar_rm_enable_sitemap');
function evazar_rm_enable_sitemap($post_types) {
    if (!in_array('evazar_product', $post_types)) {
        $post_types[] = 'evazar_product';
    }
    return $post_types;
}

// توابع کمکی تشخیص برگه‌های جامع دایرکتوری برندها و دسته‌بندی‌ها
if (!function_exists('evazar_is_brand_directory')) {
    function evazar_is_brand_directory() {
        if (get_query_var('evazar_directory') === 'brand') {
            return true;
        }
        $req_uri    = $_SERVER['REQUEST_URI'] ?? '';
        $clean_path = trim(parse_url($req_uri, PHP_URL_PATH), '/');
        return (bool)preg_match('#(?:^|/)brands?(?:/page/\d+)?/?$#i', $clean_path);
    }
}

if (!function_exists('evazar_is_category_directory')) {
    function evazar_is_category_directory() {
        if (get_query_var('evazar_directory') === 'category') {
            return true;
        }
        $req_uri    = $_SERVER['REQUEST_URI'] ?? '';
        $clean_path = trim(parse_url($req_uri, PHP_URL_PATH), '/');
        return (bool)(preg_match('#(?:^|/)categories?(?:/page/\d+)?/?$#i', $clean_path) || preg_match('#(?:^|/)product-category(?:/page/\d+)?/?$#i', $clean_path));
    }
}

// ج) فرمول طلایی عنوان فرانت‌اند برای کالاها، دسته‌ها و برندها
add_filter('rank_math/frontend/title', 'evazar_rm_dynamic_frontend_title', 9999);
add_filter('rank_math/opengraph/facebook/title', 'evazar_rm_dynamic_frontend_title', 9999);
add_filter('rank_math/opengraph/twitter/title', 'evazar_rm_dynamic_frontend_title', 9999);
add_filter('wpseo_title', 'evazar_rm_dynamic_frontend_title', 9999);
function evazar_rm_dynamic_frontend_title($title) {
    if (evazar_is_brand_directory()) {
        $brand_title = get_option('evazar_opt_brands_page_title', 'برندهای کالا') ?: 'برندهای کالا';
        $site_name   = get_bloginfo('name') ?: 'ایوازار';
        return evazar_append_pagination_suffix(trim($brand_title) . ' | ' . $site_name);
    }
    if (evazar_is_category_directory()) {
        $cat_title = get_option('evazar_opt_categories_page_title', 'دسته‌بندی‌های کالا') ?: 'دسته‌بندی‌های کالا';
        $site_name = get_bloginfo('name') ?: 'ایوازار';
        return evazar_append_pagination_suffix(trim($cat_title) . ' | ' . $site_name);
    }
    if (is_singular('evazar_product')) {
        $post_id = get_the_ID() ?: get_queried_object_id();
        if (empty($post_id)) {
            return $title;
        }
        $custom_title = get_post_meta($post_id, 'rank_math_title', true);
        if (empty($custom_title)) {
            $prod_title = get_the_title($post_id);
            $site_name  = get_bloginfo('name') ?: 'ایوازار';
            $tpl        = get_option('evazar_product_title_template', 'قیمت و خرید {title} | {site_name}');
            if (empty(trim($tpl))) {
                $tpl = 'قیمت و خرید {title} | {site_name}';
            }

            $brand     = get_post_meta($post_id, '_evazar_brand', true) ?: '';
            $category  = get_post_meta($post_id, '_evazar_category', true) ?: '';
            $price_raw = get_post_meta($post_id, '_evazar_current_price', true);
            $price_fmt = $price_raw ? number_format(intval($price_raw)) . ' تومان' : '';

            $replacements = [
                '{title}'     => $prod_title,
                '{site_name}' => $site_name,
                '{brand}'     => $brand,
                '{category}'  => $category,
                '{price}'     => $price_fmt,
            ];

            return trim(str_replace(array_keys($replacements), array_values($replacements), $tpl));
        }
    } elseif (is_tax('evazar_brand')) {
        $term = get_queried_object();
        if ($term && !empty($term->term_id)) {
            $custom_title = get_term_meta($term->term_id, '_evazar_meta_title', true);
            if (!empty(trim($custom_title))) {
                return evazar_append_pagination_suffix(trim($custom_title));
            }
            $site_name = get_bloginfo('name') ?: 'ایوازار';
            $tpl = get_option('evazar_opt_brand_meta_title_template', 'خرید و قیمت روز انواع محصولات {brand} | {site_name}');
            if (empty(trim($tpl))) {
                $tpl = 'خرید و قیمت روز انواع محصولات {brand} | {site_name}';
            }
            $generated = trim(str_replace(['{brand}', '{site_name}', '{title}'], [$term->name, $site_name, $term->name], $tpl));
            return evazar_append_pagination_suffix($generated);
        }
    } elseif (is_tax('evazar_category')) {
        $term = get_queried_object();
        if ($term && !empty($term->term_id)) {
            $custom_title = get_term_meta($term->term_id, '_evazar_meta_title', true);
            if (!empty(trim($custom_title))) {
                return evazar_append_pagination_suffix(trim($custom_title));
            }
            $site_name = get_bloginfo('name') ?: 'ایوازار';
            $tpl = get_option('evazar_opt_category_meta_title_template', 'خرید و قیمت روز انواع {category} | {site_name}');
            if (empty(trim($tpl))) {
                $tpl = 'خرید و قیمت روز انواع {category} | {site_name}';
            }
            $generated = trim(str_replace(['{category}', '{site_name}', '{title}'], [$term->name, $site_name, $term->name], $tpl));
            return evazar_append_pagination_suffix($generated);
        }
    }
    if (in_array(trim($title), ['Archive', 'Archives', 'بایگانی'], true) || strpos($title, 'Archive ') === 0 || strpos($title, 'Archive -') === 0 || strpos($title, 'Archive |') === 0) {
        return 'کالاهای ایوازار | ' . (get_bloginfo('name') ?: 'ایوازار');
    }
    return $title;
}

// تابع کمکی درج پسوند شماره صفحه در عنوان سئو جهت رفع داپلیکیت و رقابت صفحات پیجینیشن
function evazar_append_pagination_suffix($title) {
    $paged = max(1, intval(get_query_var('paged', 1)), intval(get_query_var('page', 1)));
    if ($paged > 1) {
        $paged_fa = function_exists('evazar_to_fa_num') ? evazar_to_fa_num($paged) : $paged;
        if (strpos($title, ' | ') !== false) {
            $parts = explode(' | ', $title, 2);
            return $parts[0] . ' - صفحه ' . $paged_fa . ' | ' . $parts[1];
        } else {
            return $title . ' - صفحه ' . $paged_fa;
        }
    }
    return $title;
}

// فیلتر عنوان تب مرورگر برای آرشیو برندها و دسته‌بندی‌ها
add_filter('document_title_parts', 'evazar_tax_meta_document_title_parts', 9999);
function evazar_tax_meta_document_title_parts($parts) {
    if (evazar_is_brand_directory()) {
        $brand_title = get_option('evazar_opt_brands_page_title', 'برندهای کالا') ?: 'برندهای کالا';
        $site_name   = get_bloginfo('name') ?: 'ایوازار';
        $parts['title'] = evazar_append_pagination_suffix(trim($brand_title) . ' | ' . $site_name);
        unset($parts['site'], $parts['tagline']);
        return $parts;
    }
    if (evazar_is_category_directory()) {
        $cat_title = get_option('evazar_opt_categories_page_title', 'دسته‌بندی‌های کالا') ?: 'دسته‌بندی‌های کالا';
        $site_name = get_bloginfo('name') ?: 'ایوازار';
        $parts['title'] = evazar_append_pagination_suffix(trim($cat_title) . ' | ' . $site_name);
        unset($parts['site'], $parts['tagline']);
        return $parts;
    }
    if (is_tax('evazar_brand')) {
        $term = get_queried_object();
        if ($term && !empty($term->name)) {
            $custom_title = get_term_meta($term->term_id, '_evazar_meta_title', true);
            if (!empty(trim($custom_title))) {
                $parts['title'] = evazar_append_pagination_suffix(trim($custom_title));
                unset($parts['site']);
            } else {
                $site_name = get_bloginfo('name') ?: 'ایوازار';
                $tpl = get_option('evazar_opt_brand_meta_title_template', 'خرید و قیمت روز انواع محصولات {brand} | {site_name}');
                if (empty(trim($tpl))) $tpl = 'خرید و قیمت روز انواع محصولات {brand} | {site_name}';
                $parts['title'] = evazar_append_pagination_suffix(trim(str_replace(['{brand}', '{site_name}', '{title}'], [$term->name, $site_name, $term->name], $tpl)));
                unset($parts['site']);
            }
        }
    } elseif (is_tax('evazar_category')) {
        $term = get_queried_object();
        if ($term && !empty($term->name)) {
            $custom_title = get_term_meta($term->term_id, '_evazar_meta_title', true);
            if (!empty(trim($custom_title))) {
                $parts['title'] = evazar_append_pagination_suffix(trim($custom_title));
                unset($parts['site']);
            } else {
                $site_name = get_bloginfo('name') ?: 'ایوازار';
                $tpl = get_option('evazar_opt_category_meta_title_template', 'خرید و قیمت روز انواع {category} | {site_name}');
                if (empty(trim($tpl))) $tpl = 'خرید و قیمت روز انواع {category} | {site_name}';
                $parts['title'] = evazar_append_pagination_suffix(trim(str_replace(['{category}', '{site_name}', '{title}'], [$term->name, $site_name, $term->name], $tpl)));
                unset($parts['site']);
            }
        }
    }
    if (isset($parts['title']) && in_array(trim($parts['title']), ['Archive', 'Archives', 'بایگانی'], true)) {
        $parts['title'] = 'کالاهای ایوازار';
    }
    return $parts;
}

add_filter('pre_get_document_title', 'evazar_tax_pre_get_document_title', 9999);
function evazar_tax_pre_get_document_title($title) {
    if (evazar_is_brand_directory()) {
        $brand_title = get_option('evazar_opt_brands_page_title', 'برندهای کالا') ?: 'برندهای کالا';
        $site_name   = get_bloginfo('name') ?: 'ایوازار';
        return evazar_append_pagination_suffix(trim($brand_title) . ' | ' . $site_name);
    }
    if (evazar_is_category_directory()) {
        $cat_title = get_option('evazar_opt_categories_page_title', 'دسته‌بندی‌های کالا') ?: 'دسته‌بندی‌های کالا';
        $site_name = get_bloginfo('name') ?: 'ایوازار';
        return evazar_append_pagination_suffix(trim($cat_title) . ' | ' . $site_name);
    }
    if (is_tax('evazar_brand')) {
        $term = get_queried_object();
        if ($term && !empty($term->name)) {
            $custom_title = get_term_meta($term->term_id, '_evazar_meta_title', true);
            if (!empty(trim($custom_title))) {
                return evazar_append_pagination_suffix(trim($custom_title));
            }
            $site_name = get_bloginfo('name') ?: 'ایوازار';
            $tpl = get_option('evazar_opt_brand_meta_title_template', 'خرید و قیمت روز انواع محصولات {brand} | {site_name}');
            if (empty(trim($tpl))) $tpl = 'خرید و قیمت روز انواع محصولات {brand} | {site_name}';
            return evazar_append_pagination_suffix(trim(str_replace(['{brand}', '{site_name}', '{title}'], [$term->name, $site_name, $term->name], $tpl)));
        }
    } elseif (is_tax('evazar_category')) {
        $term = get_queried_object();
        if ($term && !empty($term->name)) {
            $custom_title = get_term_meta($term->term_id, '_evazar_meta_title', true);
            if (!empty(trim($custom_title))) {
                return evazar_append_pagination_suffix(trim($custom_title));
            }
            $site_name = get_bloginfo('name') ?: 'ایوازار';
            $tpl = get_option('evazar_opt_category_meta_title_template', 'خرید و قیمت روز انواع {category} | {site_name}');
            if (empty(trim($tpl))) $tpl = 'خرید و قیمت روز انواع {category} | {site_name}';
            return evazar_append_pagination_suffix(trim(str_replace(['{category}', '{site_name}', '{title}'], [$term->name, $site_name, $term->name], $tpl)));
        }
    }
    if (in_array(trim($title), ['Archive', 'Archives', 'بایگانی'], true) || strpos($title, 'Archive ') === 0 || strpos($title, 'Archive -') === 0 || strpos($title, 'Archive |') === 0) {
        return 'کالاهای ایوازار | ' . (get_bloginfo('name') ?: 'ایوازار');
    }
    return $title;
}

// د) توضیحات متای پویا و خودکار رنک‌مث
add_filter('rank_math/frontend/description', 'evazar_rm_dynamic_frontend_desc', 20);
add_filter('rank_math/opengraph/facebook/description', 'evazar_rm_dynamic_frontend_desc', 20);
add_filter('rank_math/opengraph/twitter/description', 'evazar_rm_dynamic_frontend_desc', 20);
function evazar_rm_dynamic_frontend_desc($description) {
    if (evazar_is_brand_directory()) {
        $custom_desc = get_option('evazar_opt_brands_page_desc', '');
        if (!empty(trim($custom_desc))) {
            return trim(wp_strip_all_tags($custom_desc));
        }
        $site_name = get_bloginfo('name') ?: 'ایوازار';
        return 'بررسی قیمت روز، مقایسه مشخصات فنی و خرید مستقیم با بهترین گارانتی و تضمین اصالت کالا در ' . $site_name . '.';
    }
    if (evazar_is_category_directory()) {
        $custom_desc = get_option('evazar_opt_categories_page_desc', '');
        if (!empty(trim($custom_desc))) {
            return trim(wp_strip_all_tags($custom_desc));
        }
        $site_name = get_bloginfo('name') ?: 'ایوازار';
        return 'مشاهده لیست و راهنمای جامع دسته‌بندی‌های کالای ' . $site_name . '، مقایسه قیمت‌ها، نقد و بررسی و خرید هوشمندانه از معتبرترین فروشگاه‌ها.';
    }
    if (is_singular('evazar_product')) {
        if (empty($description)) {
            $post_id = get_the_ID() ?: get_queried_object_id();
            if (empty($post_id)) {
                return $description;
            }
            $saved_desc = get_post_meta($post_id, 'rank_math_description', true);
            if (!empty($saved_desc)) {
                return $saved_desc;
            }
            $title     = get_the_title($post_id);
            $site_name = get_bloginfo('name') ?: 'ایوازار';
            $tpl       = get_option('evazar_product_desc_template', 'قیمت و خرید آنلاین {title} با بهترین قیمت روز و مشخصات در {site_name}. نقد و بررسی و ضمانت اصالت کالا.');
            if (empty(trim($tpl))) {
                $tpl = 'قیمت و خرید آنلاین {title} با بهترین قیمت روز و مشخصات در {site_name}. نقد و بررسی و ضمانت اصالت کالا.';
            }

            $brand     = get_post_meta($post_id, '_evazar_brand', true) ?: '';
            $category  = get_post_meta($post_id, '_evazar_category', true) ?: '';
            $price_raw = get_post_meta($post_id, '_evazar_current_price', true);
            $price_fmt = $price_raw ? number_format(intval($price_raw)) . ' تومان' : '';

            $replacements = [
                '{title}'     => $title,
                '{site_name}' => $site_name,
                '{brand}'     => $brand,
                '{category}'  => $category,
                '{price}'     => $price_fmt,
            ];

            return trim(str_replace(array_keys($replacements), array_values($replacements), $tpl));
        }
    } elseif (is_tax(['evazar_category', 'evazar_brand'])) {
        if (empty($description)) {
            $term = get_queried_object();
            if ($term && !empty($term->term_id)) {
                $custom_desc = get_term_meta($term->term_id, '_evazar_meta_description', true);
                if (!empty(trim($custom_desc))) {
                    return trim($custom_desc);
                }
                $site_name = get_bloginfo('name') ?: 'ایوازار';
                if ($term->taxonomy === 'evazar_brand') {
                    $tpl = get_option('evazar_opt_brand_meta_desc_template', 'مشاهده لیست قیمت روز، مشخصات فنی و خرید آنلاین انواع {brand} در {site_name} با تضمین اصالت و ارسال سریع.');
                    if (empty(trim($tpl))) $tpl = 'مشاهده لیست قیمت روز، مشخصات فنی و خرید آنلاین انواع {brand} در {site_name} با تضمین اصالت و ارسال سریع.';
                    return trim(str_replace(['{brand}', '{site_name}', '{title}'], [$term->name, $site_name, $term->name], $tpl));
                } else {
                    $tpl = get_option('evazar_opt_category_meta_desc_template', 'مشاهده لیست قیمت روز، مشخصات فنی و خرید آنلاین انواع {category} در {site_name} با تضمین اصالت و ارسال سریع.');
                    if (empty(trim($tpl))) $tpl = 'مشاهده لیست قیمت روز، مشخصات فنی و خرید آنلاین انواع {category} در {site_name} با تضمین اصالت و ارسال سریع.';
                    return trim(str_replace(['{category}', '{site_name}', '{title}'], [$term->name, $site_name, $term->name], $tpl));
                }
            }
        }
    }
    return $description;
}

// لینک کانونیکال اختصاصی صفحات جامع جهت جلوگیری از کانونیکال شدن به صفحه اصلی
add_filter('rank_math/frontend/canonical', 'evazar_rm_dynamic_frontend_canonical', 20);
function evazar_rm_dynamic_frontend_canonical($canonical) {
    if (evazar_is_brand_directory()) {
        return home_url('/brand/');
    }
    if (evazar_is_category_directory()) {
        return home_url('/product-category/');
    }
    return $canonical;
}

// هـ) تزریق اسکیما پیشرفته محصول (Product Rich Snippet Schema JSON-LD) جهت نمایش ستاره‌ها و قیمت در گوگل
add_filter('rank_math/json_ld', 'evazar_rm_inject_product_schema', 99, 2);
function evazar_rm_inject_product_schema($data, $jsonld) {
    if (is_singular('evazar_product')) {
        $post_id = get_the_ID() ?: get_queried_object_id();
        if (empty($post_id)) {
            return $data;
        }
        $price        = get_post_meta($post_id, '_evazar_current_price', true);
        $old_price    = get_post_meta($post_id, '_evazar_old_price', true);
        $rating       = get_post_meta($post_id, '_evazar_rating', true);
        $satisfaction = get_post_meta($post_id, '_evazar_satisfaction', true);
        $brand        = get_post_meta($post_id, '_evazar_brand', true);
        $sku          = get_post_meta($post_id, '_sku', true) ?: $post_id;
        $main_img     = get_post_meta($post_id, '_evazar_main_image', true) ?: get_the_post_thumbnail_url($post_id, 'full');
        $desc         = get_post_meta($post_id, 'rank_math_description', true) ?: wp_trim_words(get_the_excerpt($post_id), 30);
        $site_name    = get_bloginfo('name') ?: 'ایوازار';

        if (function_exists('evazar_cdn_image_url') && $main_img) {
            $main_img = evazar_cdn_image_url($main_img);
        }

        $rating_val          = floatval($rating);
        $stored_rating_count = intval(get_post_meta($post_id, '_evazar_rating_count', true));

        $product_schema = [
            '@type'       => 'Product',
            '@id'         => get_permalink($post_id) . '#product',
            'name'        => get_the_title($post_id),
            'description' => esc_html($desc),
            'sku'         => (string)$sku,
        ];

        if (!empty($main_img)) {
            $product_schema['image'] = [esc_url($main_img)];
        }

        if (!empty($brand) && !in_array($brand, ['دیجی‌کالا', 'دیجیکالا'])) {
            $product_schema['brand'] = [
                '@type' => 'Brand',
                'name'  => esc_html($brand)
            ];
        }

        if ($stored_rating_count > 0 && $rating_val > 0) {
            $product_schema['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => (string)$rating_val,
                'bestRating'  => '5',
                'worstRating' => '1',
                'ratingCount' => (string)$stored_rating_count
            ];
        }

        if (!empty($price)) {
            $clean_price = preg_replace('/[^0-9]/', '', $price);
            if (!empty($clean_price)) {
                $product_schema['offers'] = [
                    '@type'           => 'Offer',
                    'price'           => (string)$clean_price,
                    'priceCurrency'   => 'IRT',
                    'priceValidUntil' => date('Y-12-31', strtotime('+1 year')),
                    'availability'    => 'https://schema.org/InStock',
                    'url'             => get_permalink($post_id),
                    'seller'          => [
                        '@type' => 'Organization',
                        'name'  => $site_name
                    ]
                ];
            }
        }

        $data['Product'] = $product_schema;
    }
    return $data;
}

// و) خروجی متاتگ جایگزین در صورت غیرفعال بودن رنک‌مث (Zero-Conflict Fallback)
add_action('wp_head', 'evazar_fallback_seo_meta_tags', 1);
function evazar_fallback_seo_meta_tags() {
    if (defined('RANK_MATH_VERSION') || defined('WPSEO_VERSION')) {
        return;
    }
    if (evazar_is_brand_directory()) {
        $desc = get_option('evazar_opt_brands_page_desc', '');
        if (empty(trim($desc))) {
            $site_name = get_bloginfo('name') ?: 'ایوازار';
            $desc      = 'بررسی قیمت روز، مقایسه مشخصات فنی و خرید مستقیم با بهترین گارانتی و تضمین اصالت کالا در ' . $site_name . '.';
        }
        echo '<meta name="description" content="' . esc_attr(wp_strip_all_tags($desc)) . '" />' . "\n";
        return;
    }
    if (evazar_is_category_directory()) {
        $desc = get_option('evazar_opt_categories_page_desc', '');
        if (empty(trim($desc))) {
            $site_name = get_bloginfo('name') ?: 'ایوازار';
            $desc      = 'مشاهده لیست و راهنمای جامع دسته‌بندی‌های کالای ' . $site_name . '، مقایسه قیمت‌ها، نقد و بررسی و خرید هوشمندانه از معتبرترین فروشگاه‌ها.';
        }
        echo '<meta name="description" content="' . esc_attr(wp_strip_all_tags($desc)) . '" />' . "\n";
        return;
    }
    if (is_singular('evazar_product')) {
        $post_id   = get_the_ID();
        $site_name = get_bloginfo('name') ?: 'ایوازار';
        $desc      = get_post_meta($post_id, 'rank_math_description', true);
        if (empty($desc)) {
            $desc = "مشخصات فنی، قیمت و خرید اینترنتی " . get_the_title($post_id) . " در " . $site_name . " با ضمانت اصالت کالا.";
        }
        echo '<meta name="description" content="' . esc_attr($desc) . '" />' . "\n";
    } elseif (is_tax(['evazar_category', 'evazar_brand'])) {
        $term = get_queried_object();
        if ($term && !empty($term->term_id)) {
            $custom_desc = get_term_meta($term->term_id, '_evazar_meta_description', true) ?: get_term_meta($term->term_id, 'rank_math_description', true);
            if (!empty(trim($custom_desc))) {
                $desc = trim($custom_desc);
            } else {
                $site_name = get_bloginfo('name') ?: 'ایوازار';
                if ($term->taxonomy === 'evazar_brand') {
                    $tpl = get_option('evazar_opt_brand_meta_desc_template', 'مشاهده لیست قیمت روز، مشخصات فنی و خرید آنلاین انواع {brand} در {site_name} با تضمین اصالت و ارسال سریع.');
                    if (empty(trim($tpl))) $tpl = 'مشاهده لیست قیمت روز، مشخصات فنی و خرید آنلاین انواع {brand} در {site_name} با تضمین اصالت و ارسال سریع.';
                    $desc = trim(str_replace(['{brand}', '{site_name}', '{title}'], [$term->name, $site_name, $term->name], $tpl));
                } else {
                    $tpl = get_option('evazar_opt_category_meta_desc_template', 'مشاهده لیست قیمت روز، مشخصات فنی و خرید آنلاین انواع {category} در {site_name} با تضمین اصالت و ارسال سریع.');
                    if (empty(trim($tpl))) $tpl = 'مشاهده لیست قیمت روز، مشخصات فنی و خرید آنلاین انواع {category} در {site_name} با تضمین اصالت و ارسال سریع.';
                    $desc = trim(str_replace(['{category}', '{site_name}', '{title}'], [$term->name, $site_name, $term->name], $tpl));
                }
            }
            echo '<meta name="description" content="' . esc_attr($desc) . '" />' . "\n";
        }
    }
}

// ز) بهینه‌سازی پیشرفته سئوی پیجینیشن: جلوگیری از رقابت صفحات ۲ به بعد با صفحه اصلی (Anti-Cannibalization)
add_filter('rank_math/frontend/robots', 'evazar_seo_pagination_robots_filter', 99);
function evazar_seo_pagination_robots_filter($robots) {
    if (is_archive() || is_tax(['evazar_category', 'evazar_brand']) || is_post_type_archive('evazar_product')) {
        $paged = max(1, intval(get_query_var('paged', 1)), intval(get_query_var('page', 1)));
        if ($paged > 1) {
            $robots['index'] = 'noindex';
            $robots['follow'] = 'follow';
        }
    }
    return $robots;
}

add_filter('wp_robots', 'evazar_seo_native_robots_filter', 99);
function evazar_seo_native_robots_filter($robots) {
    if (is_archive() || is_tax(['evazar_category', 'evazar_brand']) || is_post_type_archive('evazar_product')) {
        $paged = max(1, intval(get_query_var('paged', 1)), intval(get_query_var('page', 1)));
        if ($paged > 1) {
            $robots['noindex'] = true;
            $robots['follow'] = true;
        }
    }
    return $robots;
}


require_once plugin_dir_path(__FILE__) . 'batch-processor.php';

// ==========================================
// ح) سیستم ساده و سریع قفل محتوای دسته‌ها و برندها (1-Click AJAX Lock)
// ==========================================

// افزودن ستون به جدول دسته‌بندی‌ها و برندها در پیشخوان
add_filter('manage_edit-evazar_category_columns', 'evazar_core_add_lock_column');
add_filter('manage_edit-evazar_brand_columns', 'evazar_core_add_lock_column');
function evazar_core_add_lock_column($columns) {
    $columns['evazar_lock'] = 'قفل محتوا (دستی / AI)';
    return $columns;
}

// رندر دکمه تعاملی ایجکس در ستون جدول
add_filter('manage_evazar_category_custom_column', 'evazar_core_render_lock_column', 10, 3);
add_filter('manage_evazar_brand_custom_column', 'evazar_core_render_lock_column', 10, 3);
function evazar_core_render_lock_column($content, $column_name, $term_id) {
    if ($column_name === 'evazar_lock') {
        $is_locked = get_term_meta($term_id, '_evazar_lock_seo_content', true) === 'yes';
        $btn_class = $is_locked ? 'evazar-lock-pill is-locked' : 'evazar-lock-pill is-unlocked';
        $label = $is_locked ? '🔒 قفل دستی' : '🤖 مجاز برای AI';
        $title = $is_locked ? 'کلیک کنید تا برای بازنویسی هوش مصنوعی آزاد شود' : 'کلیک کنید تا قفل شود (جلوگیری قطعی از تغییر توسط هوش مصنوعی)';
        $nonce = wp_create_nonce('evazar_lock_term_' . $term_id);

        return sprintf(
            '<button type="button" class="%s" data-term-id="%d" data-nonce="%s" title="%s">%s</button>',
            esc_attr($btn_class),
            intval($term_id),
            esc_attr($nonce),
            esc_attr($title),
            esc_html($label)
        );
    }
    return $content;
}

// هندلر ایجکس سریع برای تغییر وضعیت قفل
add_action('wp_ajax_evazar_toggle_term_lock', 'evazar_core_ajax_toggle_term_lock');
function evazar_core_ajax_toggle_term_lock() {
    $term_id = intval($_POST['term_id'] ?? 0);
    if ($term_id <= 0) {
        wp_send_json_error(['message' => 'شناسه نامعتبر است.']);
    }

    check_ajax_referer('evazar_lock_term_' . $term_id, 'nonce');

    if (!current_user_can('manage_categories')) {
        wp_send_json_error(['message' => 'عدم دسترسی کافی']);
    }

    $current = get_term_meta($term_id, '_evazar_lock_seo_content', true);
    $new_status = ($current === 'yes') ? 'no' : 'yes';
    update_term_meta($term_id, '_evazar_lock_seo_content', $new_status);

    wp_send_json_success([
        'locked' => ($new_status === 'yes'),
        'label'  => ($new_status === 'yes') ? '🔒 قفل دستی' : '🤖 مجاز برای AI',
        'title'  => ($new_status === 'yes') ? 'کلیک کنید تا برای بازنویسی هوش مصنوعی آزاد شود' : 'کلیک کنید تا قفل شود (جلوگیری قطعی از تغییر توسط هوش مصنوعی)'
    ]);
}

// نمایش گزینه قفل در فرم ویرایش دسته و برند
add_action('evazar_category_edit_form_fields', 'evazar_core_render_term_lock_field', 10, 2);
add_action('evazar_brand_edit_form_fields', 'evazar_core_render_term_lock_field', 10, 2);
function evazar_core_render_term_lock_field($term, $taxonomy) {
    $is_locked = get_term_meta($term->term_id, '_evazar_lock_seo_content', true);
    ?>
    <tr class="form-field">
        <th scope="row"><label for="evazar_lock_seo_content">قفل محتوای هوش مصنوعی</label></th>
        <td>
            <label style="font-weight: 600; cursor: pointer;">
                <input type="checkbox" name="evazar_lock_seo_content" id="evazar_lock_seo_content" value="yes" <?php checked($is_locked, 'yes'); ?>>
                🔒 قفل دستی محتوا (جلوگیری ۱۰۰٪ از ویرایش یا بازنویسی توسط هوش مصنوعی)
            </label>
            <p class="description">اگر این گزینه تیک خورده باشد، سیستم هوش مصنوعی و عملیات دسته‌جمعی به هیچ وجه توضیحات این برگه را تغییر نخواهند داد.</p>
        </td>
    </tr>
    <?php
}

// ذخیره فیلد فرم ویرایش دسته و برند
add_action('edited_evazar_category', 'evazar_core_save_term_lock_field');
add_action('edited_evazar_brand', 'evazar_core_save_term_lock_field');
function evazar_core_save_term_lock_field($term_id) {
    if (isset($_POST['evazar_lock_seo_content']) && $_POST['evazar_lock_seo_content'] === 'yes') {
        update_term_meta($term_id, '_evazar_lock_seo_content', 'yes');
    } else {
        delete_term_meta($term_id, '_evazar_lock_seo_content');
    }
}

// اسکریپت و استایل فوق‌العاده سبک ایجکس در پاورقی صفحات لیست دسته‌بندی و برند
add_action('admin_footer-edit-tags.php', 'evazar_core_admin_lock_script');
function evazar_core_admin_lock_script() {
    $screen = get_current_screen();
    if (!$screen || !in_array($screen->taxonomy, ['evazar_category', 'evazar_brand'], true)) {
        return;
    }
    ?>
    <style>
        .evazar-lock-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 10px;
            font-size: 11px;
            font-weight: bold;
            border-radius: 20px;
            border: 1px solid transparent;
            cursor: pointer;
            transition: all 0.2s ease-in-out;
            outline: none;
            user-select: none;
        }
        .evazar-lock-pill.is-locked {
            background-color: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }
        .evazar-lock-pill.is-locked:hover {
            background-color: #c3e6cb;
        }
        .evazar-lock-pill.is-unlocked {
            background-color: #f1f3f5;
            color: #495057;
            border-color: #dee2e6;
        }
        .evazar-lock-pill.is-unlocked:hover {
            background-color: #e9ecef;
            color: #212529;
        }
        .evazar-lock-pill.is-busy {
            opacity: 0.5;
            pointer-events: none;
        }
        .column-evazar_lock {
            width: 140px;
            text-align: center;
        }
    </style>
    <script>
    jQuery(document).ready(function($) {
        $(document).on('click', '.evazar-lock-pill', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var termId = $btn.data('term-id');
            var nonce = $btn.data('nonce');

            $btn.addClass('is-busy');

            $.post(ajaxurl, {
                action: 'evazar_toggle_term_lock',
                term_id: termId,
                nonce: nonce
            }, function(response) {
                $btn.removeClass('is-busy');
                if (response.success && response.data) {
                    var data = response.data;
                    $btn.text(data.label);
                    $btn.attr('title', data.title);
                    if (data.locked) {
                        $btn.removeClass('is-unlocked').addClass('is-locked');
                    } else {
                        $btn.removeClass('is-locked').addClass('is-unlocked');
                    }
                } else {
                    alert('خطا در ذخیره وضعیت قفل.');
                }
            }).fail(function() {
                $btn.removeClass('is-busy');
                alert('خطای ارتباط با سرور.');
            });
        });
    });
    </script>
    <?php
}
