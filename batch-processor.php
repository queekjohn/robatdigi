<?php
if (!defined('ABSPATH')) exit;

// ==========================================
// Batch Processor UI (Admin Footer)
// ==========================================
add_action('evazar_core_settings_page_bottom', 'evazar_ai_batch_processor_ui');
function evazar_ai_batch_processor_ui() {
    ?>
    <div style="background: #fff; padding: 25px; border: 1px solid #ccd0d4; margin-top: 40px; border-radius: 4px; border-right: 4px solid #d63638;">
        <h2 style="margin-top:0; font-size: 1.3em;">پاک‌سازی و بازنویسی هوشمند دسته‌ها و برندها (AI Batch Rewrite)</h2>
        <p style="font-size: 14px; line-height: 1.6;">این ابزار به صورت خودکار تمام دسته‌ها و برندهایی که در گذشته ساخته شده‌اند را بررسی می‌کند. اگر دیجی‌کالا برای آنها متنی نداشت، متن سایت شما را <strong>خالی می‌کند</strong> تا توهم هوش مصنوعی پاک شود. اگر متنی داشت، متن را با استفاده از پرامپت جدید، مجدداً با کیفیت بالا <strong>بازنویسی می‌کند</strong>.</p>
        <button id="evazar-run-batch" class="button button-primary button-large" style="background: #d63638; border-color: #b32d2e; margin-top: 10px;">اجرای عملیات پاک‌سازی و بازنویسی (توصیه شده)</button>
        <div id="evazar-batch-status" style="margin-top: 15px; font-weight: bold; color: #0071a1; font-size: 15px;"></div>
    </div>
        <script>
        jQuery(document).ready(function($){
            $('#evazar-run-batch').on('click', function(e){
                e.preventDefault();
                if(!confirm('هشدار: آیا از شروع این عملیات اطمینان دارید؟ این فرآیند ممکن است چندین دقیقه طول بکشد.')) return;
                
                var $btn = $(this);
                var $status = $('#evazar-batch-status');
                $btn.prop('disabled', true);
                $status.html('در حال دریافت لیست برندها و دسته‌ها...');

                $.post(ajaxurl, {
                    action: 'evazar_batch_fetch_terms',
                    _wpnonce: '<?php echo wp_create_nonce("evazar_batch_nonce"); ?>'
                }, function(response) {
                    if (response.success && response.data.terms) {
                        var terms = response.data.terms;
                        var total = terms.length;
                        if (total === 0) {
                            $status.html('هیچ دسته‌بندی یا برندی برای پردازش یافت نشد.');
                            $btn.prop('disabled', false);
                            return;
                        }
                        
                        $status.html('پیدا شد: ' + total + ' مورد. در حال بررسی و پردازش هوشمند... (لطفاً پنجره را نبندید)');
                        
                        var stats = { rewritten: 0, locked: 0, manual: 0, excluded: 0, no_source: 0 };
                        
                        var processNext = function(index) {
                            if (index >= total) {
                                var summaryHtml = '<div style="margin-top:15px; padding:12px; background:#e8f5e9; border:1px solid #c8e6c9; border-radius:6px; color:#2e7d32;">'
                                    + '<strong>عملیات با موفقیت به پایان رسید!</strong><br>'
                                    + '⚡ بازنویسی هوشمند: ' + stats.rewritten + ' مورد | '
                                    + '🔒 قفل دستی (حفظ شده): ' + stats.locked + ' مورد | '
                                    + '✍ محتوای دستی کاربر: ' + stats.manual + ' مورد | '
                                    + '⛔ مستثنی: ' + stats.excluded + ' مورد | '
                                    + '⚪ فاقد متن دیجی‌کالا: ' + stats.no_source + ' مورد'
                                    + '</div>';
                                $status.html(summaryHtml);
                                $btn.prop('disabled', false);
                                return;
                            }
                            
                            var currentTerm = terms[index];
                            $status.html('در حال پردازش: <strong>' + currentTerm.name + '</strong> (' + (index + 1) + ' از ' + total + ') ...');
                            
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'evazar_batch_process_single_term',
                                    term_id: currentTerm.term_id,
                                    tax: currentTerm.taxonomy,
                                    name: currentTerm.name,
                                    _wpnonce: '<?php echo wp_create_nonce("evazar_batch_nonce"); ?>'
                                },
                                timeout: 60000,
                                success: function(res) {
                                    if (res.success && res.data) {
                                        var st = res.data.status;
                                        if (st === 'rewritten') stats.rewritten++;
                                        else if (st === 'skipped_locked') stats.locked++;
                                        else if (st === 'skipped_manual') stats.manual++;
                                        else if (st === 'skipped_excluded') stats.excluded++;
                                        else if (st === 'skipped_no_source') stats.no_source++;
                                    }
                                    processNext(index + 1);
                                },
                                error: function() {
                                    console.error('Failed processing term ' + currentTerm.name);
                                    processNext(index + 1);
                                }
                            });
                        };
                        
                        processNext(0);
                        
                    } else {
                        $status.html('خطا در دریافت لیست.');
                        $btn.prop('disabled', false);
                    }
                }).fail(function(){
                    $status.html('خطای ارتباط با سرور.');
                    $btn.prop('disabled', false);
                });
            });
        });
        </script>
    <?php
}

// ==========================================
// AJAX endpoints
// ==========================================
add_action('wp_ajax_evazar_batch_fetch_terms', 'evazar_batch_fetch_terms_callback');
function evazar_batch_fetch_terms_callback() {
    check_ajax_referer('evazar_batch_nonce', '_wpnonce');
    if (!current_user_can('manage_options')) wp_send_json_error();

    $terms = get_terms([
        'taxonomy' => ['evazar_category', 'evazar_brand'],
        'hide_empty' => false,
        'fields' => 'all'
    ]);
    
    $result = [];
    foreach ($terms as $t) {
        $result[] = [
            'term_id' => $t->term_id,
            'taxonomy' => $t->taxonomy,
            'name' => $t->name
        ];
    }
    
    wp_send_json_success(['terms' => $result]);
}

add_action('wp_ajax_evazar_batch_process_single_term', 'evazar_batch_process_single_term_callback');
function evazar_batch_process_single_term_callback() {
    check_ajax_referer('evazar_batch_nonce', '_wpnonce');
    if (!current_user_can('manage_options')) wp_send_json_error();

    $term_id = intval($_POST['term_id']);
    $taxonomy = sanitize_text_field($_POST['tax']);
    $name = sanitize_text_field($_POST['name']);
    
    if (!$term_id || !$taxonomy) wp_send_json_error();

    // ۱. بررسی قفل دستی توسط کاربر
    if (get_term_meta($term_id, '_evazar_lock_seo_content', true) === 'yes') {
        wp_send_json_success(['status' => 'skipped_locked', 'message' => 'دارای قفل دستی']);
        return;
    }

    // ۲. بررسی وجود متن دستی قبلی (در صورتی که فال‌بک دیفالت نباشد)
    $term = get_term($term_id, $taxonomy);
    if ($term && !is_wp_error($term)) {
        $existing_desc = trim(strip_tags((string)$term->description));
        $is_fallback = (mb_strpos($existing_desc, 'راهنمای خرید و بررسی تخصصی جدیدترین مدل‌های') !== false);
        if (!empty($existing_desc) && !$is_fallback) {
            wp_send_json_success(['status' => 'skipped_manual', 'message' => 'دارای متن دستی کاربر']);
            return;
        }
    }

    // ۳. بررسی لیست برندها یا کلمات مستثنی
    $raw_kw = get_option('evazar_ai_taxonomy_exclude_keywords', 'متفرقه, miscellaneous, عمومی');
    $kw_arr = array_filter(array_map('trim', explode(',', (string)$raw_kw)));
    $slug = $term->slug ?? '';
    foreach ($kw_arr as $kw) {
        if (!empty($kw) && (mb_stripos($name, $kw) !== false || stripos($slug, $kw) !== false)) {
            wp_send_json_success(['status' => 'skipped_excluded', 'message' => 'در لیست مستثنی']);
            return;
        }
    }
    
    $dk_slug = get_term_meta($term_id, '_evazar_dk_slug', true);
    $brand_en = get_term_meta($term_id, '_evazar_brand_en', true);
    
    if (empty($dk_slug) && $taxonomy === 'evazar_brand') {
        $dk_slug = strtolower(trim($brand_en));
    }
    
    // کشف خودکار dk_slug برای دسته‌های قدیمی
    if (empty($dk_slug) && $taxonomy === 'evazar_category') {
        $args = [
            'post_type' => 'evazar_product',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'tax_query' => [
                [
                    'taxonomy' => $taxonomy,
                    'field' => 'term_id',
                    'terms' => $term_id
                ]
            ]
        ];
        $posts = get_posts($args);
        if (!empty($posts)) {
            $dkp = get_post_meta($posts[0], '_evazar_dkp', true);
            if ($dkp) {
                $worker_url = get_option('evazar_cf_worker_url', '');
                $product_url = !empty($worker_url) ? rtrim($worker_url, '/') . '/?dkp=' . $dkp : "https://api.digikala.com/v2/product/{$dkp}/";
                $batch_headers = function_exists('evazar_worker_auth_headers') ? evazar_worker_auth_headers() : [];
                $response = wp_remote_get($product_url, ['timeout' => 5, 'headers' => $batch_headers]);
                if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                    $body = json_decode(wp_remote_retrieve_body($response), true);
                    $breadcrumbs = $body['data']['product']['breadcrumb'] ?? ($body['product']['breadcrumbs'] ?? []);
                    foreach ($breadcrumbs as $crumb) {
                        $title = $crumb['title'] ?? '';
                        if ($title === $name && !empty($crumb['url']['uri'])) {
                            $uri = $crumb['url']['uri'];
                            if (preg_match('#/search/([^/]+)/?#i', $uri, $matches)) {
                                $dk_slug = $matches[1];
                                update_term_meta($term_id, '_evazar_dk_slug', $dk_slug);
                                break;
                            } elseif (preg_match('#/main/([^/]+)/?#i', $uri, $matches)) {
                                $dk_slug = 'category-' . $matches[1];
                                update_term_meta($term_id, '_evazar_dk_slug', $dk_slug);
                                break;
                            }
                        }
                    }
                }
            }
        }
    }
    
    if (empty($dk_slug) && $taxonomy === 'evazar_brand') {
        $brand_en = get_term_meta($term_id, '_evazar_brand_en', true);
        if (!empty($brand_en)) {
            $dk_slug = strtolower(trim($brand_en));
        }
    }
    
    if (empty($dk_slug)) {
        update_term_meta($term_id, '_evazar_ai_content_status', 'skipped_no_source');
        wp_send_json_success(['status' => 'skipped_no_source', 'message' => 'فاقد اسلاگ']);
        return;
    }
    
    $context_type = ($taxonomy === 'evazar_brand') ? 'brand' : 'category';
    if (!function_exists('evazar_ai_ensure_term_seo_content')) {
        require_once plugin_dir_path(__FILE__) . 'importer.php';
    }
    
    // بازنویسی ایمن با هوش مصنوعی (در صورت عدم وجود متن در دیجی‌کالا هیچ متنی پاک نمی‌شود)
    evazar_ai_ensure_term_seo_content($term_id, $taxonomy, $name, $context_type, $brand_en);
    
    $final_status = get_term_meta($term_id, '_evazar_ai_content_status', true);
    if ($final_status === 'skipped_no_source') {
        wp_send_json_success(['status' => 'skipped_no_source', 'message' => 'بدون متن مبدا']);
        return;
    }
    
    wp_send_json_success(['status' => 'rewritten', 'message' => 'بازنویسی موفق']);
}
