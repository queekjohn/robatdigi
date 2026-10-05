<?php
if (!defined('ABSPATH')) exit;


/**
 * هدر احراز هویت داخلی برای ارتباط WordPress با Cloudflare Worker.
 * مقدار توکن فقط از تنظیمات سرور خوانده می‌شود و هرگز در HTML فرانت‌اند قرار نمی‌گیرد.
 */
function evazar_worker_auth_headers() {
    $headers = [];
    $token = trim((string) get_option('evazar_cf_worker_token', ''));
    if ($token !== '') {
        $headers['X-Evazar-Internal-Token'] = $token;
    }
    return $headers;
}

/**
 * موتور یکپارچه و تفکیک‌شده پردازش هوش مصنوعی (OpenAI / Gemini)
 * تفکیک کامل و ایزوله کلیدها، مدل‌ها و سهمیه‌ها با امکان فال‌بک هوشمند دوگانه
 */

/**
 * تجزیه کلیدها بر اساس پیشوند برای تفکیک قطعی OpenAI و Gemini
 */
function evazar_extract_api_keys_by_prefix($raw_string, $target_provider = 'all') {
    $raw_string = str_replace(["\r\n", "\r"], "\n", (string)$raw_string);
    $raw_lines = explode("\n", $raw_string);
    $keys = [];
    $current_key = '';

    foreach ($raw_lines as $l) {
        $l = trim($l);
        if (empty($l)) continue;
        if (strpos($l, 'AQ.') === 0 || strpos($l, 'AIza') === 0 || strpos($l, 'sk-') === 0 || preg_match('/^[A-Za-z0-9_\-]{20,}$/', $l)) {
            if (!empty($current_key)) {
                $keys[] = $current_key;
            }
            $current_key = $l;
        } else {
            if (!empty($current_key)) {
                $current_key .= $l;
            } else {
                $current_key = $l;
            }
        }
    }
    if (!empty($current_key)) {
        $keys[] = $current_key;
    }

    if ($target_provider === 'openai') {
        return array_values(array_filter($keys, function($k) {
            return strpos($k, 'AIza') !== 0 && strpos($k, 'AQ.') !== 0;
        }));
    } elseif ($target_provider === 'gemini') {
        return array_values(array_filter($keys, function($k) {
            return strpos($k, 'AIza') === 0 || strpos($k, 'AQ.') === 0;
        }));
    }
    return $keys;
}

/**
 * بررسی وجود حداقل یک کلید هوش مصنوعی در هر یک از فیلدهای تنظیمات
 */
function evazar_has_ai_api_key() {
    $k1 = trim((string)get_option('evazar_openai_api_key', ''));
    $k2 = trim((string)get_option('evazar_gemini_api_key', ''));
    $k3 = trim((string)get_option('evazar_ai_api_key', ''));
    $worker = trim((string)get_option('evazar_cf_worker_url', ''));
    $token = trim((string)get_option('evazar_cf_worker_token', ''));
    // در حالت Worker-only نگهداری کلید OpenAI در WordPress الزامی نیست.
    return !empty($k1) || !empty($k2) || !empty($k3) || (!empty($worker) && !empty($token));
}

/**
 * اجرای OpenAI. در صورت وجود Worker، فقط Worker فراخوانی می‌شود و کلید OpenAI روی WordPress لازم نیست.
 */
function evazar_call_openai_engine($system_prompt, $user_prompt, $is_json = false, $preferred_model = null) {
    $worker_url = trim((string)get_option('evazar_cf_worker_url', ''));
    $worker_token = trim((string)get_option('evazar_cf_worker_token', ''));
    $model = !empty($preferred_model) ? $preferred_model : get_option('evazar_openai_model', '');
    if (empty($model) || strpos((string)$model, 'gemini') !== false) {
        $legacy_model = get_option('evazar_ai_model', '');
        $model = (!empty($legacy_model) && strpos((string)$legacy_model, 'gemini') === false) ? $legacy_model : 'gpt-4o-mini';
    }
    $body = ['model'=>$model, 'messages'=>[
        ['role'=>'system','content'=>$system_prompt],
        ['role'=>'user','content'=>$user_prompt]
    ]];
    $is_reasoning = (strpos((string)$model,'o1')===0 || strpos((string)$model,'o3')===0 || strpos((string)$model,'o4')===0 || strpos((string)$model,'-o1')!==false || strpos((string)$model,'-o3')!==false);
    if (!$is_reasoning) $body['temperature'] = 0.1;
    if ($is_json) $body['response_format'] = ['type'=>'json_object'];
    $timeout = max(45, (int)get_option('evazar_ai_timeout', 60));

    if ($worker_url && $worker_token) {
        $api_url = trailingslashit($worker_url) . 'ai/openai';
        $response = wp_remote_post($api_url, [
            'timeout'=>$timeout,
            'sslverify'=>true,
            'headers'=>array_merge(['Content-Type'=>'application/json'], evazar_worker_auth_headers()),
            'body'=>wp_json_encode($body)
        ]);
        if (is_wp_error($response)) return new WP_Error('openai_worker_error', 'خطای ارتباط با Worker هوش مصنوعی: '.$response->get_error_message());
        $code = (int)wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        if ($code === 400 && isset($body['temperature']) && stripos($raw,'temperature') !== false) {
            unset($body['temperature']);
            $response = wp_remote_post($api_url, ['timeout'=>$timeout,'sslverify'=>true,'headers'=>array_merge(['Content-Type'=>'application/json'], evazar_worker_auth_headers()),'body'=>wp_json_encode($body)]);
            if (!is_wp_error($response)) { $code=(int)wp_remote_retrieve_response_code($response); $raw=wp_remote_retrieve_body($response); }
        }
        if ($code === 200) {
            $json = json_decode($raw, true);
            if (isset($json['choices'][0]['message']['content'])) return ['content'=>$json['choices'][0]['message']['content'],'provider'=>'openai','model'=>$model];
        }
        $message = 'پاسخ Worker OpenAI '.$code;
        if ($code === 401) $message .= ': توکن داخلی Worker معتبر نیست.';
        elseif ($code === 403) $message .= ': دسترسی Worker رد شد.';
        elseif ($raw) $message .= ': '.mb_substr((string)$raw,0,300);
        return new WP_Error('openai_worker_failed', $message);
    }

    // سازگاری legacy: فقط وقتی Worker در دسترس نیست، اتصال مستقیم مجاز است.
    $raw_keys = get_option('evazar_openai_api_key', '');
    if (empty(trim((string)$raw_keys))) $raw_keys = get_option('evazar_ai_api_key', '');
    $keys = evazar_extract_api_keys_by_prefix($raw_keys, 'openai');
    if (empty($keys)) return new WP_Error('no_openai_key', 'Worker هوش مصنوعی تنظیم نشده و کلید OpenAI نیز وجود ندارد.');
    $last_error = 'خطای ارتباط با OpenAI.';
    foreach ($keys as $api_key) {
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout'=>$timeout,'sslverify'=>true,
            'headers'=>['Authorization'=>'Bearer '.trim($api_key),'Content-Type'=>'application/json'],
            'body'=>wp_json_encode($body)
        ]);
        if (is_wp_error($response)) { $last_error=$response->get_error_message(); continue; }
        $code=(int)wp_remote_retrieve_response_code($response); $raw=wp_remote_retrieve_body($response);
        if ($code===200) { $json=json_decode($raw,true); if(isset($json['choices'][0]['message']['content'])) return ['content'=>$json['choices'][0]['message']['content'],'provider'=>'openai','model'=>$model]; }
        $last_error='OpenAI '.$code.': '.mb_substr((string)$raw,0,250);
        if ($code===429 || $code===401 || $code===403) continue;
    }
    return new WP_Error('openai_failed',$last_error);
}

/**
 * اجرای مستقیم و ایزوله موتور Google Gemini (بدون کوچک‌ترین وابستگی به OpenAI)
 */
/**
 * موتور Gemini — حالت امن چند-ورکر (Multi-Worker Secure Mode)
 * 
 * معماری جدید:
 * - کلیدهای API داخل هر Cloudflare Worker به‌عنوان Environment Secret نگهداری می‌شوند
 * - افزونه فقط URL ورکرها را می‌شناسد (نه کلیدها)
 * - چرخش بین ورکرها به‌صورت Round-Robin با transient
 * - هیچ keyای در URL یا body درخواست‌ها ظاهر نمی‌شود
 * 
 * راه‌اندازی:
 * تنظیمات > ایوازار > آدرس ورکرهای Gemini:
 *   Worker 1: https://worker-account-a.your-domain.workers.dev
 *   Worker 2: https://worker-account-b.your-domain.workers.dev
 *   Worker 3: https://worker-account-c.your-domain.workers.dev
 * (یک URL در هر خط در فیلد evazar_cf_worker_url)
 */
function evazar_gemini_quota_worker_key($worker_url) {
    return 'evz_gemini_quota_' . md5(rtrim(trim((string) $worker_url), '/'));
}

/**
 * بررسی می‌کند آیا پاسخ Gemini واقعاً نشان‌دهنده اتمام سهمیه/Quota است.
 * 429 به‌تنهایی الزاماً به معنی تمام شدن سهمیه روزانه نیست؛ بنابراین
 * متن پاسخ Google نیز بررسی می‌شود. در صورت وجود نشانه‌های quota،
 * Worker برای 24 ساعت وارد Cooldown می‌شود.
 */
function evazar_is_gemini_quota_exhausted($http_code, $body_raw) {
    if ((int) $http_code !== 429) {
        return false;
    }

    $body = strtolower((string) $body_raw);
    if ($body === '') {
        // در صورت 429 بدون body، برای جلوگیری از شلیک مجدد به همان Worker
        // آن را موقتاً به‌عنوان محدودیت سهمیه در نظر می‌گیریم.
        return true;
    }

    $quota_markers = [
        'resource_exhausted',
        'resource exhausted',
        'quota exceeded',
        'quota_exceeded',
        'quota limit',
        'quota_limit',
        'daily quota',
        'daily limit',
        'exceeded your current quota',
        'per day',
        'requests per day',
        'tokens per day',
        'limit: 0',
    ];

    foreach ($quota_markers as $marker) {
        if (strpos($body, $marker) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * ثبت Cooldown سهمیه Gemini برای یک Worker به مدت 24 ساعت.
 */
function evazar_set_gemini_worker_quota_cooldown($worker_url) {
    set_transient(
        evazar_gemini_quota_worker_key($worker_url),
        time() + DAY_IN_SECONDS,
        DAY_IN_SECONDS
    );
}

/**
 * آیا Worker در حال حاضر در Cooldown سهمیه است؟
 */
function evazar_is_gemini_worker_quota_cooldown($worker_url) {
    $expires_at = get_transient(evazar_gemini_quota_worker_key($worker_url));
    return ($expires_at !== false && (int) $expires_at > time());
}

/**
 * ثبت Cooldown سراسری Gemini وقتی همه Workerها سهمیه‌شان را از دست داده‌اند.
 */
function evazar_set_gemini_global_quota_cooldown() {
    set_transient(
        'evz_gemini_global_quota_cooldown',
        time() + DAY_IN_SECONDS,
        DAY_IN_SECONDS
    );
}

/**
 * آیا کل Gemini در Cooldown سراسری است؟
 */
function evazar_is_gemini_global_quota_cooldown() {
    $expires_at = get_transient('evz_gemini_global_quota_cooldown');
    return ($expires_at !== false && (int) $expires_at > time());
}

/**
 * موتور Gemini — حالت امن چند-ورکر (Multi-Worker Secure Mode)
 *
 * Cooldown سهمیه:
 * - اگر Google برای یک Worker خطای واقعی اتمام سهمیه بدهد، همان Worker
 *   به مدت 24 ساعت بدون هیچ درخواست جدید کنار گذاشته می‌شود.
 * - اگر تمام Workerها در Cooldown باشند، یک Cooldown سراسری 24 ساعته فعال
 *   می‌شود و هیچ درخواست دیگری به Google ارسال نخواهد شد.
 * - در حالت fallback، توزیع‌کننده مستقیماً به موتور جایگزین می‌رود.
 */
function evazar_call_gemini_engine($system_prompt, $user_prompt, $is_json = false, $preferred_model = null) {

    // =====================================================================
    // ۱. جمع‌آوری لیست Worker URL‌های Gemini از تنظیمات
    // =====================================================================
    $workers_raw = trim(get_option('evazar_cf_worker_url', ''));
    if (empty($workers_raw)) {
        $workers_raw = trim(get_option('evazar_image_cdn_domain', ''));
    }

    $worker_urls = [];
    $raw_lines = preg_split('/[\r\n,]+/', $workers_raw);
    foreach ($raw_lines as $line) {
        $line = trim($line);
        if (!empty($line) && filter_var($line, FILTER_VALIDATE_URL)) {
            $worker_urls[] = rtrim($line, '/');
        }
    }

    $worker_urls = array_values(array_unique($worker_urls));

    if (empty($worker_urls)) {
        return new WP_Error('no_worker_url', 'هیچ آدرس Cloudflare Worker در تنظیمات ایوازار تعریف نشده است.');
    }

    // =====================================================================
    // ۲. اگر Gemini در Cooldown سراسری است، حتی یک درخواست هم ارسال نکن.
    // =====================================================================
    if (evazar_is_gemini_global_quota_cooldown()) {
        return new WP_Error(
            'gemini_quota_cooldown',
            'سهمیه Google Gemini برای تمام Workerهای فعال در دسترس نیست؛ Gemini تا 24 ساعت پس از آخرین تشخیص اتمام سهمیه متوقف شده است.'
        );
    }

    // =====================================================================
    // ۳. انتخاب Worker با Round-Robin و حذف Workerهای دارای Cooldown
    // =====================================================================
    $rr_key = 'evz_gemini_worker_rr_' . md5(implode('|', $worker_urls));
    $current_idx = (int) get_transient($rr_key);
    if ($current_idx < 0 || $current_idx >= count($worker_urls)) {
        $current_idx = 0;
    }

    $ordered_workers = array_merge(
        array_slice($worker_urls, $current_idx),
        array_slice($worker_urls, 0, $current_idx)
    );

    $available_workers = [];
    foreach ($ordered_workers as $worker_url) {
        if (!evazar_is_gemini_worker_quota_cooldown($worker_url)) {
            $available_workers[] = $worker_url;
        }
    }

    if (empty($available_workers)) {
        // تمام Workerها قبلاً به دلیل quota کنار گذاشته شده‌اند؛ از این لحظه
        // هیچ درخواست دیگری به Google ارسال نمی‌شود.
        evazar_set_gemini_global_quota_cooldown();
        return new WP_Error(
            'gemini_quota_cooldown',
            'سهمیه Google Gemini در تمام Workerها تمام شده است؛ درخواست‌های Gemini تا 24 ساعت متوقف شدند.'
        );
    }

    // =====================================================================
    // ۴. آماده‌سازی body درخواست (بدون هیچ API Key)
    // =====================================================================
    $model_name = !empty($preferred_model) ? $preferred_model : get_option('evazar_gemini_model', '');
    if (empty($model_name) || strpos($model_name, 'gemini') === false) {
        $legacy_model = get_option('evazar_ai_model', '');
        $model_name = (!empty($legacy_model) && strpos($legacy_model, 'gemini') !== false) ? $legacy_model : 'gemini-2.0-flash';
    }

    $ai_timeout = max(45, intval(get_option('evazar_ai_timeout', 60)));

    $gemini_body = [
        'system_instruction' => [
            'parts' => [['text' => $system_prompt]]
        ],
        'contents' => [
            ['parts' => [['text' => $user_prompt]]]
        ],
        'generationConfig' => [
            'temperature' => 0.1,
            'topP'        => 0.95
        ]
    ];
    if ($is_json) {
        $gemini_body['generationConfig']['responseMimeType'] = 'application/json';
    }

    $body_json  = json_encode($gemini_body);
    $last_error = 'خطای ارتباط با Cloudflare Worker.';
    $quota_workers = 0;

    // =====================================================================
    // ۵. ارسال درخواست فقط به Workerهای خارج از Cooldown
    // =====================================================================
    foreach ($available_workers as $worker_idx => $worker_url) {

        $api_url = $worker_url . '/ai/gemini/' . $model_name;

        $max_retries = 2;
        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            $response = wp_remote_post($api_url, [
                'timeout'   => $ai_timeout,
                'sslverify' => true,
                'headers'   => array_merge(['Content-Type' => 'application/json'], evazar_worker_auth_headers()),
                'body'      => $body_json
            ]);

            if (is_wp_error($response)) {
                $last_error = 'ورکر ' . ($worker_idx + 1) . ' در دسترس نیست: ' . $response->get_error_message();
                if ($attempt < $max_retries && strpos(strtolower($last_error), 'timed out') !== false) {
                    sleep(1);
                    continue;
                }
                break;
            }

            $code     = wp_remote_retrieve_response_code($response);
            $body_raw = wp_remote_retrieve_body($response);

            if ($code == 429) {
                if (evazar_is_gemini_quota_exhausted($code, $body_raw)) {
                    // مهم: بعد از این نقطه، برای این Worker هیچ درخواست دیگری
                    // تا 24 ساعت ارسال نمی‌شود.
                    evazar_set_gemini_worker_quota_cooldown($worker_url);
                    $quota_workers++;
                    $last_error = 'Worker-' . ($worker_idx + 1) . ' سهمیه Gemini را تمام کرده است؛ این Worker برای 24 ساعت متوقف شد.';
                    break;
                }

                // 429 غیر quota: فقط این Worker را برای درخواست فعلی رد کن؛
                // Cooldown روزانه ثبت نمی‌شود.
                $last_error = 'Worker-' . ($worker_idx + 1) . ' 429: محدودیت موقت درخواست؛ Worker بعدی امتحان می‌شود.';
                break;
            }

            if ($code == 503) {
                $last_error = 'Worker-' . ($worker_idx + 1) . ' 503: GEMINI_API_KEY در این ورکر تنظیم نشده است.';
                break;
            }

            if ($code == 400 || $code == 403) {
                $last_error = "Worker-" . ($worker_idx + 1) . " {$code}: کلید یا مدل نامعتبر: " . mb_substr($body_raw, 0, 150);
                break;
            }

            if ($code >= 500) {
                $last_error = "خطای سرور Worker ({$code}).";
                if ($attempt < $max_retries) { sleep(1); continue; }
                break;
            }

            if ($code !== 200) {
                $last_error = "پاسخ Worker {$code}: " . mb_substr($body_raw, 0, 200);
                break;
            }

            $body = json_decode($body_raw, true);
            if (isset($body['candidates'][0]['content']['parts'][0]['text'])) {
                $next_idx = ($current_idx + $worker_idx + 1) % count($worker_urls);
                set_transient($rr_key, $next_idx, DAY_IN_SECONDS);

                return [
                    'content'  => $body['candidates'][0]['content']['parts'][0]['text'],
                    'provider' => 'gemini',
                    'model'    => $model_name,
                    'worker'   => $worker_idx + 1
                ];
            }
            break;
        }
    }

    // اگر تمام Workerهایی که بررسی شدند به علت quota کنار رفتند، Cooldown سراسری
    // فعال می‌شود. این باعث می‌شود فراخوانی بعدی حتی یک HTTP request هم نفرستد.
    $all_in_cooldown = true;
    foreach ($worker_urls as $worker_url) {
        if (!evazar_is_gemini_worker_quota_cooldown($worker_url)) {
            $all_in_cooldown = false;
            break;
        }
    }

    if ($all_in_cooldown) {
        evazar_set_gemini_global_quota_cooldown();
        return new WP_Error(
            'gemini_quota_cooldown',
            'سهمیه Google Gemini تمام شده است؛ تمام Workerها برای 24 ساعت در Cooldown قرار گرفتند و هیچ درخواست جدیدی به Google ارسال نخواهد شد.'
        );
    }

    return new WP_Error('gemini_failed', $last_error);
}

/**
 * توزیع‌کننده مرکزی درخواست‌های هوش مصنوعی بر اساس مود کاری کاربر
 */
function evazar_call_ai_api($system_prompt, $user_prompt, $is_json = false) {
    $mode = get_option('evazar_ai_engine_mode', '');
    if (empty($mode)) {
        $legacy = get_option('evazar_ai_provider', 'openai');
        if ($legacy === 'gemini') {
            $mode = 'gemini_fallback_openai';
        } else {
            $mode = 'openai_fallback_gemini';
        }
    }

    // ۱. صرفاً OpenAI (ایزوله کامل و بدون ارتباط با جیمنای)
    if ($mode === 'openai_only') {
        $res = evazar_call_openai_engine($system_prompt, $user_prompt, $is_json);
        if (is_wp_error($res)) {
            evazar_import_log('error', 'خطای اختصاصی OpenAI: ' . $res->get_error_message());
            return $res;
        }
        return $res['content'];
    }

    // ۲. صرفاً Google Gemini (ایزوله کامل بدون ارتباط با OpenAI)
    if ($mode === 'gemini_only') {
        $res = evazar_call_gemini_engine($system_prompt, $user_prompt, $is_json);
        if (is_wp_error($res)) {
            evazar_import_log('error', 'خطای اختصاصی Google Gemini: ' . $res->get_error_message());
            return $res;
        }
        return $res['content'];
    }

    // ۳. اولویت Google Gemini با فال‌بک آنی و هوشمند به OpenAI
    if ($mode === 'gemini_fallback_openai') {
        $res = evazar_call_gemini_engine($system_prompt, $user_prompt, $is_json);
        if (!is_wp_error($res)) {
            return $res['content'];
        }
        $gemini_err = $res->get_error_message();

        // سوییچ خودکار به OpenAI
        $openai_res = evazar_call_openai_engine($system_prompt, $user_prompt, $is_json);
        if (!is_wp_error($openai_res)) {
            evazar_import_log('warning', "محدودیت سهمیه یا خطای جیمنای ({$gemini_err}). انتقال هوشمند به OpenAI انجام شد و محتوا با موفقیت تولید شد.");
            return $openai_res['content'];
        }

        $openai_err = $openai_res->get_error_message();
        $final_err = "هر دو موتور هوش مصنوعی ناموفق بودند. خطای جیمنای: [{$gemini_err}] | خطای OpenAI: [{$openai_err}]";
        evazar_import_log('error', $final_err);
        return new WP_Error('ai_dual_failed', $final_err);
    }

    // ۴. اولویت OpenAI با فال‌بک آنی به Google Gemini
    if ($mode === 'openai_fallback_gemini') {
        $res = evazar_call_openai_engine($system_prompt, $user_prompt, $is_json);
        if (!is_wp_error($res)) {
            return $res['content'];
        }
        $openai_err = $res->get_error_message();

        // سوییچ خودکار به Gemini
        $gemini_res = evazar_call_gemini_engine($system_prompt, $user_prompt, $is_json);
        if (!is_wp_error($gemini_res)) {
            evazar_import_log('warning', "خطای ارتباط یا شارژ OpenAI ({$openai_err}). سوییچ خودکار به Google Gemini انجام شد و محتوا با موفقیت تولید شد.");
            return $gemini_res['content'];
        }

        $gemini_err = $gemini_res->get_error_message();
        $final_err = "هر دو موتور هوش مصنوعی ناموفق بودند. خطای OpenAI: [{$openai_err}] | خطای جیمنای: [{$gemini_err}]";
        evazar_import_log('error', $final_err);
        return new WP_Error('ai_dual_failed', $final_err);
    }

    // فال‌بک پیش‌فرض
    $res = evazar_call_openai_engine($system_prompt, $user_prompt, $is_json);
    if (!is_wp_error($res)) return $res['content'];
    return $res;
}


// ==========================================
// سیستم لاگ سبک واردات کالا (فایل‌محور بدون دستکاری دیتابیس)
// ==========================================

/**
 * دریافت مسیر فایل لاگ سبک در پوشه آپلودها (یا پوشه افزونه به عنوان فال‌بک)
 */
function evazar_import_get_log_file() {
    $upload_dir = wp_upload_dir();
    $base_dir = $upload_dir['basedir'];
    $log_dir = $base_dir . '/evazar-logs';

    if (!file_exists($log_dir)) {
        @wp_mkdir_p($log_dir);
        @file_put_contents($log_dir . '/.htaccess', "Deny from all\n<Files ~ \"^.*\">\nDeny from all\n</Files>\n");
        @file_put_contents($log_dir . '/index.php', "<?php // Silence is golden\n");
    }

    $file = $log_dir . '/import.log';
    if (is_dir($log_dir) && (is_writable($log_dir) || (file_exists($file) && is_writable($file)))) {
        return $file;
    }

    // فال‌بک امن در پوشه افزونه
    return plugin_dir_path(__FILE__) . 'evazar-import.log';
}

/**
 * ثبت لاگ سبک با ساختار خطی JSON (حداکثر ۵۰ رویداد آخر)
 */
function evazar_import_log($type, $message, $dkp = '', $extra = []) {
    $log_file = evazar_import_get_log_file();

    $entry = [
        'time'    => current_time('Y/m/d H:i:s'),
        'type'    => in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info',
        'dkp'     => $dkp ? preg_replace('/[^0-9]/', '', (string)$dkp) : '-',
        'message' => sanitize_text_field($message),
        'extra'   => [
            'title'   => !empty($extra['title']) ? sanitize_text_field($extra['title']) : '',
            'post_id' => !empty($extra['post_id']) ? intval($extra['post_id']) : 0,
            'source'  => !empty($extra['source']) ? sanitize_text_field($extra['source']) : ''
        ]
    ];

    $lines = [];
    if (file_exists($log_file) && is_readable($log_file)) {
        $content = @file_get_contents($log_file);
        if (!empty($content)) {
            $lines = explode("\n", trim($content));
        }
    }

    // اضافه کردن لاگ جدید در ابتدای آرایه (جدیدترین در بالا)
    array_unshift($lines, json_encode($entry, JSON_UNESCAPED_UNICODE));

    // محدودسازی قطعی به حداکثر ۵۰ مورد اخیر جهت حفظ سبکی ۱۰۰٪
    if (count($lines) > 50) {
        $lines = array_slice($lines, 0, 50);
    }

    @file_put_contents($log_file, implode("\n", $lines) . "\n", LOCK_EX);
}

/**
 * دریافت ۵۰ لاگ اخیر از فایل سبک
 */
function evazar_import_get_last_logs($limit = 50) {
    $log_file = evazar_import_get_log_file();
    if (!file_exists($log_file) || !is_readable($log_file)) {
        return [];
    }

    $content = @file_get_contents($log_file);
    if (empty($content)) {
        return [];
    }

    $lines = explode("\n", trim($content));
    $logs = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        $decoded = json_decode($line, true);
        if ($decoded && is_array($decoded)) {
            $logs[] = $decoded;
        }
        if (count($logs) >= $limit) break;
    }

    return $logs;
}

/**
 * پاکسازی کامل فایل لاگ واردات
 */
function evazar_import_clear_logs() {
    $log_file = evazar_import_get_log_file();
    if (file_exists($log_file)) {
        @file_put_contents($log_file, '', LOCK_EX);
    }
    return true;
}

/**
 * رندر HTML جدول ۵۰ لاگ اخیر
 */
function evazar_render_import_logs_table() {
    $logs = evazar_import_get_last_logs(50);
    ob_start();
    ?>
    <div class="evazar-logs-wrap" style="margin-top: 10px;">
        <?php if (empty($logs)): ?>
            <div style="padding: 30px; text-align: center; color: #64748b; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px;">
                <span class="dashicons dashicons-clipboard" style="font-size: 36px; width: 36px; height: 36px; color: #94a3b8; display: block; margin: 0 auto 10px;"></span>
                <strong style="font-size: 14px; color: #334155;">هیچ لاگی تاکنون ثبت نشده است.</strong>
                <p style="margin: 6px 0 0; font-size: 12px; color: #64748b;">به محض انجام واردات دستی یا خودکار، گزارش ۵۰ عملیات اخیر همراه با وضعیت و خطاها در این بخش نمایش داده می‌شود.</p>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="wp-list-table widefat fixed striped" style="border-radius: 6px; overflow: hidden; border: 1px solid #cbd5e1; font-size: 12px;">
                    <thead>
                        <tr style="background: #f1f5f9;">
                            <th style="width: 145px; text-align: right; font-weight: 700;">زمان ثبت</th>
                            <th style="width: 95px; text-align: center; font-weight: 700;">وضعیت</th>
                            <th style="width: 115px; text-align: center; font-weight: 700;">شناسه DKP</th>
                            <th style="width: 28%; text-align: right; font-weight: 700;">عنوان کالا / جزئیات</th>
                            <th style="text-align: right; font-weight: 700;">شرح نتیجه و گزارش خطا</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): 
                            $type    = $log['type'] ?? 'info';
                            $time    = $log['time'] ?? '-';
                            $dkp     = $log['dkp'] ?? '-';
                            $msg     = $log['message'] ?? '';
                            $extra   = $log['extra'] ?? [];
                            $title   = $extra['title'] ?? '';
                            $post_id = $extra['post_id'] ?? 0;
                            $source  = $extra['source'] ?? '';
                            
                            $badge_bg = '#f1f5f9';
                            $badge_color = '#475569';
                            $badge_text = 'اطلاعات';
                            $row_bg = '';
                            
                            if ($type === 'success') {
                                $badge_bg = '#dcfce7';
                                $badge_color = '#15803d';
                                $badge_text = '✓ موفق';
                            } elseif ($type === 'error') {
                                $badge_bg = '#fee2e2';
                                $badge_color = '#b91c1c';
                                $badge_text = '✕ خطا';
                                $row_bg = 'background-color: #fffaf0;';
                            } elseif ($type === 'warning') {
                                $badge_bg = '#fef3c7';
                                $badge_color = '#b45309';
                                $badge_text = '⚠ هشدار';
                            }
                        ?>
                        <tr style="<?php echo $row_bg; ?>">
                            <td style="color: #475569; direction: ltr; text-align: right; font-family: monospace; font-size: 11px;">
                                <?php echo esc_html($time); ?>
                            </td>
                            <td style="text-align: center;">
                                <span style="display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 700; background: <?php echo $badge_bg; ?>; color: <?php echo $badge_color; ?>;">
                                    <?php echo esc_html($badge_text); ?>
                                </span>
                            </td>
                            <td style="text-align: center; font-family: monospace; font-size: 12px; direction: ltr;">
                                <?php if ($dkp && $dkp !== '-'): ?>
                                    <a href="https://www.digikala.com/product/dkp-<?php echo esc_attr($dkp); ?>/" target="_blank" rel="noopener" title="مشاهده صفحه کالا در دیجی‌کالا" style="text-decoration: none; font-weight: 600; color: #0284c7;">
                                        dkp-<?php echo esc_html($dkp); ?>
                                    </a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td style="line-height: 1.5;">
                                <?php if (!empty($title)): ?>
                                    <strong style="color: #1e293b; display: block; font-size: 12px;"><?php echo esc_html(mb_substr($title, 0, 55)) . (mb_strlen($title) > 55 ? '...' : ''); ?></strong>
                                <?php else: ?>
                                    <span style="color: #94a3b8;">-</span>
                                <?php endif; ?>
                                <?php if ($post_id > 0): ?>
                                    <div style="margin-top: 4px; font-size: 11px; display: flex; gap: 8px;">
                                        <a href="<?php echo esc_url(admin_url('post.php?post=' . $post_id . '&action=edit')); ?>" target="_blank" style="color: #2563eb; text-decoration: none; font-weight: 600;">
                                            ✏ ویرایش پست #<?php echo $post_id; ?>
                                        </a>
                                        <span style="color: #cbd5e1;">|</span>
                                        <a href="<?php echo esc_url(get_permalink($post_id)); ?>" target="_blank" style="color: #64748b; text-decoration: none;">
                                            🔗 نمایش کالا
                                        </a>
                                        <?php if (!empty($source)): ?>
                                            <span style="color: #cbd5e1;">|</span>
                                            <span style="color: #64748b; font-size: 10px;">[<?php echo esc_html($source); ?>]</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="line-height: 1.6; color: <?php echo ($type === 'error' ? '#b91c1c' : '#334155'); ?>; font-weight: <?php echo ($type === 'error' ? '600' : 'normal'); ?>;">
                                <?php echo esc_html($msg); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="margin-top: 10px; font-size: 11px; color: #64748b; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                <span>نمایش آخرین رویدادهای واردات کالا (ذخیره‌سازی کاملاً سبک و بدون جدول دیتابیس)</span>
                <span>تعداد لاگ‌های ثبت‌شده: <strong style="color: #1e293b;"><?php echo count($logs); ?></strong> از حداکثر ۵۰</span>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

// اندپوینت‌های ایجکس لاگ
add_action('wp_ajax_evazar_get_import_logs', 'evazar_ajax_get_import_logs');
function evazar_ajax_get_import_logs() {
    check_ajax_referer('evazar_import_action', 'evazar_import_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('عدم دسترسی');
    }
    wp_send_json_success(evazar_render_import_logs_table());
}

add_action('wp_ajax_evazar_clear_import_logs', 'evazar_ajax_clear_import_logs');
function evazar_ajax_clear_import_logs() {
    check_ajax_referer('evazar_import_action', 'evazar_import_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('عدم دسترسی');
    }
    evazar_import_clear_logs();
    wp_send_json_success(evazar_render_import_logs_table());
}

// ==========================================
// صفحه ایمپورت در ادمین وردپرس
// ==========================================
add_action('admin_menu', 'evazar_core_add_importer_menu');
function evazar_core_add_importer_menu() {
    add_submenu_page(
        'edit.php?post_type=evazar_product',
        'واردات هوشمند از دیجی‌کالا',
        'واردات کالا (AI)',
        'manage_options',
        'evazar-importer',
        'evazar_core_importer_page'
    );
}

add_action('wp_ajax_evazar_import_product', 'evazar_ajax_import_product');
function evazar_ajax_import_product() {
    check_ajax_referer('evazar_import_action', 'evazar_import_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('عدم دسترسی');
    }
    
    $dkp_or_url = sanitize_text_field($_POST['dk_url']);
    $post_id = evazar_core_process_import($dkp_or_url);
    
    if (is_wp_error($post_id)) {
        wp_send_json_error($post_id->get_error_message());
    } else {
        $edit_link = admin_url('post.php?post=' . $post_id . '&action=edit');
        $view_link = add_query_arg('nocache', time(), get_permalink($post_id));
        $ai_status = get_post_meta($post_id, '_evazar_ai_status_msg', true);
        $ai_badge = !empty($ai_status) ? "<br><span style='display:inline-block; margin-top:6px; background:#eef7ee; color:#1e6a2b; border:1px solid #c2e5c8; padding:3px 10px; border-radius:4px; font-weight:600;'>وضعیت هوش مصنوعی: " . esc_html($ai_status) . "</span>" : '';
        $msg = 'محصول با موفقیت وارد/بروزرسانی شد! <a href="' . $edit_link . '">مشاهده و ویرایش کالا</a> | <a href="' . $view_link . '" target="_blank">مشاهده در سایت</a>' . $ai_badge;
        wp_send_json_success($msg);
    }
}

function evazar_core_importer_page() {
    ?>
    <div class="wrap" style="direction: rtl;">
        <h1>واردات هوشمند کالا از دیجی‌کالا (بدون ووکامرس)</h1>
        <div id="evazar-message-container"></div>
        
        <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); max-width: 600px; margin-top: 20px;">
            <p>لینک محصول دیجی‌کالا یا کد DKP را وارد کنید. سیستم از طریق <strong>ورکر کلودفلر</strong> اطلاعات را استخراج کرده و در صورت فعال بودن <strong>هوش مصنوعی</strong> در تنظیمات، محتوا را سئو شده تولید می‌کند.</p>
            
            <form id="evazar-import-form" onsubmit="return submitEvazarImport(event)">
                <?php wp_nonce_field('evazar_import_action', 'evazar_import_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="dk_url">لینک یا شناسه DKP</label></th>
                        <td>
                            <input type="text" name="dk_url" id="dk_url" style="width: 100%; direction: ltr;" placeholder="https://www.digikala.com/product/dkp-12345/" required>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" id="evazar-submit-btn" class="button button-primary button-large">شروع واردات و هوش مصنوعی</button>
                </p>
                <div id="evazar-loading" style="display:none; margin-top:15px; align-items:center; gap:10px; background:#f0f6fc; padding:15px; border-radius:6px; border:1px solid #c8e1ff;">
                    <span class="spinner is-active" style="float:none; margin:0;"></span>
                    <div style="color:#0366d6;">
                        <strong style="display:block; margin-bottom:5px;">در حال پردازش درخواست (لطفاً این صفحه را نبندید) ...</strong>
                        <span style="font-size:12px; color:#586069;">ممکن است با توجه به ترافیک سرور هوش مصنوعی، بین ۱۰ تا ۳۰ ثانیه زمان ببرد.</span>
                    </div>
                </div>
            </form>
        </div>

        <!-- بخش نمایش لاگ ۵۰ واردات اخیر -->
        <div id="evazar-import-logs" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-top: 25px;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 14px; margin-bottom: 5px; flex-wrap: wrap; gap: 10px;">
                <div>
                    <h2 style="margin: 0; font-size: 15px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 8px;">
                        <span class="dashicons dashicons-list-view" style="font-size: 20px; width: 20px; height: 20px; color: #2563eb;"></span>
                        سیستم گزارش و لاگ واردات (۵۰ مورد اخیر)
                    </h2>
                    <p style="margin: 4px 0 0; font-size: 12px; color: #64748b;">
                        لاگ سبک و مستقیم بدون ایجاد هیچ‌گونه جدول در دیتابیس برای مشاهده سریع خطاها یا وضعیت واردات اخیر.
                    </p>
                </div>
                <div style="display: flex; gap: 8px;">
                    <button type="button" class="button" id="evazar-refresh-log-btn" onclick="evazarRefreshLogs(this)" title="دریافت آخرین لاگ‌ها">
                        <span class="dashicons dashicons-update" style="vertical-align: middle; font-size: 16px; width: 16px; height: 16px; margin-left: 2px;"></span> بروزرسانی لاگ
                    </button>
                    <button type="button" class="button" id="evazar-clear-log-btn" onclick="evazarClearLogs(this)" style="color: #b91c1c; border-color: #fca5a5;" title="حذف تمام لاگ‌ها">
                        <span class="dashicons dashicons-trash" style="vertical-align: middle; font-size: 16px; width: 16px; height: 16px; margin-left: 2px;"></span> پاکسازی لاگ‌ها
                    </button>
                </div>
            </div>
            
            <div id="evazar-logs-table-container">
                <?php echo evazar_render_import_logs_table(); ?>
            </div>
        </div>
    </div>
    <script>
    function evazarRefreshLogs(btn) {
        var container = document.getElementById('evazar-logs-table-container');
        var nonceEl = document.querySelector('input[name="evazar_import_nonce"]');
        if (!nonceEl || !container) return;
        
        var originalText = '';
        if (btn) {
            btn.disabled = true;
            originalText = btn.innerHTML;
            btn.innerHTML = '<span class="spinner is-active" style="float:none; margin:0 0 0 5px; vertical-align:middle;"></span> در حال بارگذاری...';
        }
        
        var formData = new FormData();
        formData.append('action', 'evazar_get_import_logs');
        formData.append('evazar_import_nonce', nonceEl.value);
        
        fetch(ajaxurl, {
            method: 'POST',
            body: formData
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
            if (data && data.success && container) {
                container.innerHTML = data.data;
            }
        })
        .catch(function() {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        });
    }

    function evazarClearLogs(btn) {
        if (!confirm('آیا از پاکسازی تمام لاگ‌های واردات اطمینان دارید؟')) {
            return;
        }
        var container = document.getElementById('evazar-logs-table-container');
        var nonceEl = document.querySelector('input[name="evazar_import_nonce"]');
        if (!nonceEl || !container) return;
        
        var originalText = '';
        if (btn) {
            btn.disabled = true;
            originalText = btn.innerHTML;
            btn.innerHTML = 'در حال پاکسازی...';
        }
        
        var formData = new FormData();
        formData.append('action', 'evazar_clear_import_logs');
        formData.append('evazar_import_nonce', nonceEl.value);
        
        fetch(ajaxurl, {
            method: 'POST',
            body: formData
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
            if (data && data.success && container) {
                container.innerHTML = data.data;
            }
        })
        .catch(function() {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        });
    }

    function submitEvazarImport(e) {
        e.preventDefault();
        var form = document.getElementById('evazar-import-form');
        var btn = document.getElementById('evazar-submit-btn');
        var loading = document.getElementById('evazar-loading');
        var msgContainer = document.getElementById('evazar-message-container');
        var urlInput = document.getElementById('dk_url').value;
        var nonce = document.querySelector('input[name="evazar_import_nonce"]').value;
        
        if(!urlInput) return false;
        
        btn.disabled = true;
        btn.innerHTML = 'در حال پردازش...';
        loading.style.display = 'flex';
        msgContainer.innerHTML = '';
        
        var formData = new FormData();
        formData.append('action', 'evazar_import_product');
        formData.append('dk_url', urlInput);
        formData.append('evazar_import_nonce', nonce);
        
        fetch(ajaxurl, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = 'شروع واردات و هوش مصنوعی';
            loading.style.display = 'none';
            if(data.success) {
                msgContainer.innerHTML = '<div class="notice notice-success is-dismissible"><p>' + data.data + '</p></div>';
                document.getElementById('dk_url').value = ''; // خالی کردن فرم در صورت موفقیت
            } else {
                msgContainer.innerHTML = '<div class="notice notice-error is-dismissible"><p>' + (data.data || 'خطای ناشناخته رخ داد.') + '</p></div>';
            }
            evazarRefreshLogs();
        })
        .catch(error => {
            btn.disabled = false;
            btn.innerHTML = 'شروع واردات و هوش مصنوعی';
            loading.style.display = 'none';
            msgContainer.innerHTML = '<div class="notice notice-error is-dismissible"><p>ارتباط با سرور قطع شد یا خطای تایم‌اوت رخ داد (ممکن است پردازش در پس‌زمینه ادامه داشته باشد).</p></div>';
            evazarRefreshLogs();
        });
        
        return false;
    }
    </script>
    <?php
}

// ==========================================
// پاکسازی کش صفحات و آبجکت محصول (LiteSpeed, WP Rocket, Cloudflare)
// ==========================================
function evazar_purge_product_cache($post_id) {
    if (!$post_id) return;
    clean_post_cache($post_id);
    
    // LiteSpeed Cache
    if (defined('LSCWP_V') || has_action('litespeed_purge_post')) {
        do_action('litespeed_purge_post', $post_id);
    }
    
    // WP Rocket
    if (function_exists('rocket_clean_post')) {
        rocket_clean_post($post_id);
    }
    
    // WP Super Cache
    if (function_exists('wp_cache_post_change')) {
        wp_cache_post_change($post_id);
    }
    
    // W3 Total Cache
    if (function_exists('w3tc_flush_post')) {
        w3tc_flush_post($post_id);
    }
    
    // Nginx Helper / FastCGI
    do_action('rt_nginx_helper_purge_post', $post_id);
}

// ==========================================
// پردازش واردات (ورکر + هوش مصنوعی + ذخیره به عنوان evazar_product)
// ==========================================
function evazar_core_process_import($dkp_input) {
    @set_time_limit(180);
    // 1. استخراج DKP
    $dkp = preg_replace('/[^0-9]/', '', $dkp_input);
    if (empty($dkp)) {
        evazar_import_log('error', 'شناسه DKP یا لینک ورودی معتبر نیست: ' . esc_html($dkp_input), $dkp_input);
        return new WP_Error('invalid', 'شناسه DKP معتبر نیست.');
    }

    // 2. درخواست به ورکر کلودفلر یا فال‌بک به API مستقیم
    $worker_url = trim(get_option('evazar_cf_worker_url', ''));
    if (empty($worker_url)) {
        $worker_url = trim(get_option('evazar_image_cdn_domain', 'https://img.evazar.ir'));
    }
    if (empty($worker_url)) {
        $worker_url = 'https://img.evazar.ir';
    }
    $product_loaded = false;
    $import_stock_status = null;
    $import_availability_source = '';

    if (!empty($worker_url)) {
        $cf_endpoint = add_query_arg('dkp', $dkp, rtrim($worker_url, '/'));
        $cf_res = wp_remote_get($cf_endpoint, [
            'timeout' => 25,
            'headers' => array_merge(['Accept' => 'application/json'], evazar_worker_auth_headers())
        ]);

        if (!is_wp_error($cf_res) && wp_remote_retrieve_response_code($cf_res) === 200) {
            $cf_data = json_decode(wp_remote_retrieve_body($cf_res), true);
            if (!empty($cf_data['success']) && !empty($cf_data['product'])) {
                $cp = $cf_data['product'];
                if (array_key_exists('is_available', $cp)) {
                    $import_stock_status = !empty($cp['is_available']) ? 1 : 0;
                }
                if (isset($cp['availability_source']) && in_array((string)$cp['availability_source'], ['explicit_unavailable','explicit_available','fallback'], true)) {
                    $import_availability_source = (string)$cp['availability_source'];
                }
                $raw_title = $cp['title_fa'] ?? '';
                $en_title = $cp['title_en'] ?? '';
                $raw_desc = $cp['description'] ?? '';
                $main_img = $cp['image'] ?? '';
                $gallery = !empty($cp['gallery']) && is_array($cp['gallery']) ? $cp['gallery'] : [];
                if ($main_img && !in_array($main_img, $gallery)) array_unshift($gallery, $main_img);

                $specs_text = "";
                if (!empty($cp['specs']) && is_array($cp['specs'])) {
                    foreach ($cp['specs'] as $spec) {
                        $specs_text .= "- " . ($spec['title'] ?? '') . ": " . ($spec['value'] ?? '') . "\n";
                    }
                }

                $selling_price = (int)($cp['price_toman'] ?? 0);
                // Canonical availability: current price > 0, except explicit_unavailable.
                // This intentionally ignores a stale boolean from the Worker when the price is absent.
                if ($import_availability_source === '') $import_availability_source = 'fallback';
                $import_stock_status = ($selling_price > 0 && $import_availability_source !== 'explicit_unavailable') ? 1 : 0;
                $rrp_price = $cp['regular_price_toman'] ?? $selling_price;
                if ($rrp_price == 0) $rrp_price = $selling_price;

                $discount_num = intval($cp['discount_percent'] ?? 0);
                $discount_text = $discount_num > 0 ? $discount_num : 0;
                $rating_count = intval($cp['rating_count'] ?? 0);
                $rating = ($rating_count > 0 && isset($cp['rating'])) ? floatval($cp['rating']) : 0;
                $satisfaction_val = ($rating_count > 0 && isset($cp['satisfaction'])) ? intval($cp['satisfaction']) : 0;
                $brand_val = !empty($cp['brand']) && !in_array($cp['brand'], ['دیجی‌کالا', 'دیجیکالا', 'عمومی']) ? $cp['brand'] : '';
                $brand_en_val = $cp['brand_en'] ?? '';
                $category_name = $cp['category'] ?? '';
                if (empty($cp['breadcrumbs']) && !empty($category_name)) {
                    $breadcrumbs_val = json_encode([['title' => $category_name, 'url' => '']], JSON_UNESCAPED_UNICODE);
                } else {
                    $breadcrumbs_val = !empty($cp['breadcrumbs']) ? json_encode($cp['breadcrumbs'], JSON_UNESCAPED_UNICODE) : '';
                }

                $product_loaded = true;
            }
        }
    }

    if (!$product_loaded) {
        $api_url = "https://api.digikala.com/v2/product/{$dkp}/";
        $response = wp_remote_get($api_url, [
            'timeout' => 7,
            'headers' => ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)']
        ]);

        if (is_wp_error($response)) {
            // Fallback to v1
            $response = wp_remote_get("https://api.digikala.com/v1/product/{$dkp}/", [
                'timeout' => 7, 
                'headers' => ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)']
            ]);
            if (is_wp_error($response)) {
                $err = 'خطا در ارتباط با سرور دیجی‌کالا: ' . $response->get_error_message() . ' (پیشنهاد: ورکر کلودفلر را فعال کنید).';
                evazar_import_log('error', $err, $dkp);
                return new WP_Error('API_CONN_ERROR', $err);
            }
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 403) {
            $err = 'آی‌پی هاست توسط فایروال دیجی‌کالا مسدود شده است (HTTP 403 Forbidden). فعال‌سازی ورکر کلودفلر الزامی است.';
            evazar_import_log('error', $err, $dkp);
            return new WP_Error('DK_BLOCKED_403', $err);
        } elseif ($code === 429) {
            $err = 'محدودیت تعداد درخواست اعمال شد (HTTP 429 Too Many Requests). لطفاً فاصله زمانی صف را افزایش دهید.';
            evazar_import_log('warning', $err, $dkp);
            return new WP_Error('DK_RATE_LIMIT_429', $err);
        } elseif ($code === 404) {
            $err = 'کالای مورد نظر در دیجی‌کالا یافت نشد یا صفحه آن حذف شده است (HTTP 404 Not Found).';
            evazar_import_log('error', $err, $dkp);
            return new WP_Error('DK_NOT_FOUND_404', $err);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data) || !is_array($data)) {
            $err = 'پاسخ دریافتی ساختار معتبر JSON نداشت (احتمال نمایش صفحه خطای HTML به جای دیتا).';
            evazar_import_log('error', $err, $dkp);
            return new WP_Error('INVALID_JSON', $err);
        }
        
        if (empty($data['data']['product'])) {
            $err = 'ساختار دیتای دریافتی فاقد کلید استاندارد data.product است (احتمال تغییر نسخه یا متدهای API دیجی‌کالا).';
            evazar_import_log('error', $err, $dkp);
            return new WP_Error('SCHEMA_MISMATCH', $err);
        }

        $p = $data['data']['product'];
        $raw_title = $p['title_fa'] ?? '';
        $en_title = $p['title_en'] ?? '';

        // استخراج جامع تمام بخش‌های معرفی و بررسی تخصصی دیجی‌کالا
        $desc_parts = [];
        $intro_desc = trim($p['review']['description'] ?? '');
        if (!empty($intro_desc)) {
            $desc_parts[] = $intro_desc;
        }

        $raw_sections = $p['expert_reviews']['review_sections'] ?? $p['review']['review_sections'] ?? [];
        if (!empty($raw_sections) && is_array($raw_sections)) {
            foreach ($raw_sections as $sec) {
                $sec_title = trim($sec['title'] ?? '');
                $sub_texts = [];
                if (!empty($sec['sections']) && is_array($sec['sections'])) {
                    foreach ($sec['sections'] as $sub) {
                        $txt = trim(strip_tags((string)($sub['text'] ?? $sub['template'] ?? '')));
                        $txt = str_replace('&zwnj;', '‌', $txt);
                        if (!empty($txt) && stripos($txt, 'http') !== 0) {
                            $sub_texts[] = $txt;
                        }
                    }
                } elseif (!empty($sec['text'])) {
                    $txt = trim(strip_tags((string)$sec['text']));
                    $txt = str_replace('&zwnj;', '‌', $txt);
                    if (!empty($txt) && stripos($txt, 'http') !== 0) {
                        $sub_texts[] = $txt;
                    }
                }
                if (!empty($sub_texts)) {
                    $block = (!empty($sec_title) ? $sec_title . ":\n" : "") . implode("\n", $sub_texts);
                    $desc_parts[] = $block;
                }
            }
        }

        if (empty($desc_parts) && !empty($p['expert_reviews']['description'])) {
            $desc_parts[] = trim($p['expert_reviews']['description']);
        }
        if (empty($desc_parts) && !empty($p['content'])) {
            $desc_parts[] = is_string($p['content']) ? trim($p['content']) : trim($p['content']['description'] ?? '');
        }

        $raw_desc = implode("\n\n", array_filter($desc_parts));
        
        // استخراج تصاویر
        $gallery = [];
        $main_img = ($p['images']['main']['url'] ?? [''])[0];
        if (!empty($main_img)) $gallery[] = $main_img;
        if (!empty($p['images']['list'])) {
            foreach ($p['images']['list'] as $img) {
                $u = ($img['url'] ?? [''])[0];
                if ($u && !in_array($u, $gallery)) $gallery[] = $u;
            }
        }
        
        // استخراج مشخصات
        $specs_text = "";
        if (!empty($p['specifications'][0]['attributes'])) {
            foreach ($p['specifications'][0]['attributes'] as $attr) {
                $specs_text .= "- " . $attr['title'] . ": " . implode('، ', $attr['values']) . "\n";
            }
        }

        // قیمت
        $selling_price = (int)(($p['default_variant']['price']['selling_price'] ?? 0) / 10);
        $import_availability_source = 'fallback';
        $import_stock_status = $selling_price > 0 ? 1 : 0;
        $rrp_price = ($p['default_variant']['price']['rrp_price'] ?? 0) / 10;
        if ($rrp_price == 0) $rrp_price = $selling_price;
        
        $discount_percent = intval($p['default_variant']['price']['discount_percent'] ?? 0);
        $discount_text = $discount_percent > 0 ? $discount_percent : 0;

        $rating_count = intval($p['rating']['count'] ?? ($p['rating_count'] ?? 0));
        $raw_rate = $p['rating']['rate'] ?? 0;
        if ($raw_rate > 5) $raw_rate = round($raw_rate / 20, 1);

        if ($rating_count > 0 && $raw_rate > 0) {
            $rating = floatval($raw_rate);
            if (!empty($p['rating']['count_buyers']) && !empty($p['rating']['count']) && $p['rating']['count'] > 0) {
                $satisfaction_val = round(($p['rating']['count_buyers'] / $p['rating']['count']) * 100);
                if ($satisfaction_val > 100) $satisfaction_val = 100;
            } elseif (!empty($p['satisfaction'])) {
                $satisfaction_val = intval($p['satisfaction']);
            } else {
                $satisfaction_val = round(($rating / 5) * 100);
            }
        } else {
            $rating = 0;
            $satisfaction_val = 0;
            $rating_count = 0;
        }

        $brand_val = !empty($p['brand']['title_fa']) && !in_array($p['brand']['title_fa'], ['دیجی‌کالا', 'دیجیکالا', 'عمومی']) ? $p['brand']['title_fa'] : '';
        $brand_en_val = $p['brand']['title_en'] ?? '';
        $category_name = trim($p['category']['title_fa'] ?? $p['category']['title'] ?? '');
        
        $raw_crumbs = $data['data']['breadcrumb'] ?? $data['data']['breadcrumbs'] ?? $p['breadcrumb'] ?? $p['breadcrumb_list'] ?? [];
        $breadcrumbs_arr = [];
        if (!empty($raw_crumbs) && is_array($raw_crumbs)) {
            foreach ($raw_crumbs as $bc) {
                $t = trim($bc['title'] ?? $bc['title_fa'] ?? $bc['name'] ?? '');
                if (!empty($t) && $t !== 'دیجی‌کالا' && stripos($t, 'dkp-') !== 0) {
                    $uri = $bc['url']['uri'] ?? ($bc['url'] ?? '');
                    $breadcrumbs_arr[] = ['title' => $t, 'url' => $uri];
                }
            }
        }
        if (empty($breadcrumbs_arr) && !empty($category_name)) {
            $breadcrumbs_arr[] = ['title' => $category_name, 'url' => ''];
        }
        if (empty($category_name) && !empty($breadcrumbs_arr)) {
            $last_c = end($breadcrumbs_arr);
            $category_name = $last_c['title'];
        }
        $breadcrumbs_val = !empty($breadcrumbs_arr) ? json_encode($breadcrumbs_arr, JSON_UNESCAPED_UNICODE) : '';
    }

    // 3. هوش مصنوعی
    $final_desc = $raw_desc;
    $short_desc = '';
    $pros = "طراحی کاربردی و استاندارد\nکیفیت ساخت مناسب";
    $cons = '';
    $ai_meta_desc = '';
    $ai_focus_kw = '';
    $site_name = get_bloginfo('name') ?: 'ایوازار';

    $ai_enabled = get_option('evazar_ai_enabled', '');
    $has_ai_key = evazar_has_ai_api_key();
    $prompt = get_option('evazar_ai_product_prompt_v7', '');
    if (empty($prompt)) {
        $prompt = get_option('evazar_ai_product_prompt_v6', '');
    }
    if (empty($prompt)) {
        $prompt = get_option('evazar_ai_product_prompt_v5', '');
    }
    if (empty($prompt)) {
        $prompt = get_option('evazar_ai_product_prompt_v4', '');
    }
    // در صورت خالی بودن، از پرامپت استاندارد پیش‌فرض استفاده کن
    if (empty($prompt)) {
        $prompt = function_exists('evazar_get_default_ai_product_prompt') ? evazar_get_default_ai_product_prompt() : '';
    }
    $ai_status_msg = '';
    if ($ai_enabled === 'yes' && $has_ai_key) {
        $system_prompt = $prompt . "\n\nقوانین حیاتی سئو، ساختار و برندینگ:\n"
            . "۱. به هیچ وجه نام «دیجی‌کالا»، «دیجیکالا» یا هیچ فروشگاه دیگری را در نقد و بررسی، توضیحات متا (meta_description)، عنوان و کلمه کلیدی ذکر نکنید.\n"
            . "۲. خروجی فقط و فقط در قالب یک شیء JSON با کلیدهای زیر باشد:\n"
            . "- review: متن کامل نقد و بررسی تخصصی، روان، جذاب و چندپاراگرافی کالا بدون هیچ‌گونه عنوان، تیتر، سرفصل، علامت هدر مارک‌داون یا کلمات برچسبی (اکیداً از نوشتن عناوینی مانند معرفی، بررسی، ### یا کلمات تیترمانند خودداری شود). متن باید به صورت چند بند (پاراگراف) پیوسته و ارگانیک متناسب با پتانسیل کالا باشد و بین پاراگراف‌ها خط خالی (\\n\\n) قرار گیرد.\n"
            . "- pros: آرایه‌ای از ۵ تا ۷ مورد از ویژگی‌های کلیدی و نقاط قوت فنی واقعی کالا بر اساس مشخصات ثبت‌شده (بدون کلیشه‌نویسی، اغراق یا ادعای غیرواقعی)\n"
            . "- meta_description: متن توضیحات متای جذاب و ترغیب‌کننده برای نتایج جستجوی گوگل و افزونه‌های سئو (شامل نام کالا، قیمت و دعوت به خرید در {$site_name} با ضمانت اصالت)\n"
            . "- focus_keyword: عبارت کلیدی اصلی کالا جهت سئو در افزونه‌های سئو (شامل نام و مدل اصلی کالا بدون ذکر نام دیجی‌کالا)";
        
        $desc_clean = trim(strip_tags((string)$raw_desc));
        $desc_snippet = !empty($desc_clean) ? $desc_clean : "فاقد توضیحات متنی مبدا (صرفاً بر پایه عنوان و جدول مشخصات بالا بازنویسی شود)";
        $user_msg = "عنوان: $raw_title\nمشخصات:\n$specs_text\nتوضیحات اصلی:\n" . $desc_snippet;
        
        $ai_response = evazar_call_ai_api($system_prompt, $user_msg, true);
        if (is_wp_error($ai_response)) {
            // توقف کامل عملیات و بازگرداندن خطا برای اینکه کالا بدون بازنویسی ذخیره نشود
            $err = 'توقف واردات به دلیل خطای هوش مصنوعی: ' . $ai_response->get_error_message();
            evazar_import_log('error', $err, $dkp, ['title' => $raw_title]);
            return new WP_Error('AI_FAILED', $err);
        } elseif (!empty($ai_response)) {
            // Gemini ممکن است خروجی را بین بلوک‌های ```json ``` بفرستد، پس پاکسازی می‌کنیم
            $clean_json = preg_replace('/^```(?:json)?\s*/i', '', trim($ai_response));
            $clean_json = preg_replace('/\s*```$/i', '', $clean_json);
            
            $ai_data = json_decode($clean_json, true);
            if (is_array($ai_data)) {
                if (!empty($ai_data['review'])) {
                    $rev = $ai_data['review'];
                    // پاکسازی کامل هرگونه هدر مارک‌داون یا برچسب تیتر در صورتی که مدل به اشتباه تولید کرده باشد
                    $rev = preg_replace('/^#{1,6}\s*(.+)$/m', '$1', $rev);
                    $rev = preg_replace('/^\*\*(?:معرفی|بررسی|طراحی|عملکرد|منبع|نظافت|جمع‌بندی|مشخصات|قابلیت‌ها|ارگونومی)[^\*]*\*\*[:\-]?\s*/mu', '', $rev);
                    $rev = preg_replace('/^(?:معرفی و نمای کلی|بررسی طراحی|عملکرد فنی|منبع تغذیه|نظافت و شستشو|جمع‌بندی و ارزش خرید|طراحی و ارگونومی|کارایی تخصصی)[^:\n]{0,50}[:\-]\s*/mu', '', $rev);
                    // اصلاح ابعاد ترکیبی (تبدیل x به علامت ضربدر × و فارسی‌سازی اعداد ابعاد منحصراً برای ابعاد فیزیکی متقارن، بدون دستکاری پارت‌نامبرهایی مثل Vivobook 16 X1605)
                    $rev = preg_replace_callback('/(?<![a-zA-Z۰-۹])([0-9۰-۹]+(?:[.,][0-9۰-۹]+)?)(?:\s*[xX×]\s*)([0-9۰-۹]+(?:[.,][0-9۰-۹]+)?)(?:(?:\s*[xX×]\s*)([0-9۰-۹]+(?:[.,][0-9۰-۹]+)?))?(?![a-zA-Z۰-۹])/u', function($m) {
                        $n1 = function_exists('evazar_to_fa_num') ? evazar_to_fa_num($m[1]) : $m[1];
                        $n2 = function_exists('evazar_to_fa_num') ? evazar_to_fa_num($m[2]) : $m[2];
                        if (!empty($m[3])) {
                            $n3 = function_exists('evazar_to_fa_num') ? evazar_to_fa_num($m[3]) : $m[3];
                            return "{$n1} × {$n2} × {$n3}";
                        }
                        return "{$n1} × {$n2}";
                    }, $rev);
                    // اصلاح ارقام چسبیده وصله‌پینه‌ای (مانند ۳0000 یا ۱۶0) به فارسی یکدست
                    $rev = preg_replace_callback('/([۰-۹]+[0-9]+|[0-9]+[۰-۹]+)/u', function($m) {
                        return function_exists('evazar_to_fa_num') ? evazar_to_fa_num($m[0]) : $m[0];
                    }, $rev);
                    $final_desc = $rev;
                }
                if (!empty($ai_data['pros']) && is_array($ai_data['pros'])) $pros = implode("\n", $ai_data['pros']);
                $cons = ''; // نقاط ضعف به دلیل ایجاد حس منفی و خطای مصنوعی از سیستم حذف شد
                if (!empty($ai_data['meta_description'])) $ai_meta_desc = sanitize_text_field($ai_data['meta_description']);
                if (!empty($ai_data['focus_keyword'])) $ai_focus_kw = sanitize_text_field($ai_data['focus_keyword']);
                $ai_status_msg = 'محتوای کالا با موفقیت توسط هوش مصنوعی بازنویسی شد.';
            } else {
                $ai_status_msg = 'پاسخ هوش مصنوعی دریافت شد ولی ساختار JSON معتبر نبود.';
            }
        }
    } else {
        $ai_status_msg = ($ai_enabled !== 'yes') ? 'هوش مصنوعی در تنظیمات ایوازار فعال نیست.' : 'کلید API هوش مصنوعی در تنظیمات ایوازار وارد نشده است.';
    }

    // عدم تولید توضیحات کوتاه طبق نظر کاربر و پاکسازی قطعی آن
    $short_desc = '';

    // تولید مقادیر استاندارد سئو برای رنک‌مث در صورت عدم وجود پاسخ هوش مصنوعی
    if (empty($ai_meta_desc)) {
        $ai_meta_desc = "مشخصات فنی، قیمت و خرید اینترنتی " . mb_substr($raw_title, 0, 75) . " در " . $site_name . " با تضمین اصالت و بهترین قیمت روز.";
    }
    // پاکسازی قطعی هرگونه نام دیجی‌کالا از توضیحات متا و کلمه کلیدی
    $ai_meta_desc = str_replace(['دیجی‌کالا', 'دیجیکالا', 'دیجی کالا'], $site_name, $ai_meta_desc);
    if (empty($ai_focus_kw)) {
        $words = preg_split('/\s+/', trim($raw_title));
        $ai_focus_kw = implode(' ', array_slice($words, 0, 4));
    }
    $ai_focus_kw = trim(str_replace(['دیجی‌کالا', 'دیجیکالا', 'دیجی کالا'], '', $ai_focus_kw));

    // 4. ذخیره کالا در پست‌تایپ evazar_product
    // بررسی تکراری بودن با استفاده از کش آبجکت
    global $wpdb;
    $cache_key = 'evz_dk_url_' . $dkp;
    $post_id = wp_cache_get($cache_key, 'evazar_core');
    if ($post_id === false) {
        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_evazar_dk_url' AND meta_value = %s LIMIT 1",
            "https://www.digikala.com/product/dkp-{$dkp}/"
        ));
        wp_cache_set($cache_key, $post_id ?: 0, 'evazar_core', 3600);
    }
    
    $is_update = ($post_id > 0);
    if ($is_update) {
        $update_res = wp_update_post([
            'ID' => $post_id,
            'post_content' => $final_desc,
            'post_excerpt' => '', // توضیحات کوتاه کاملاً حذف شد
            'post_modified' => current_time('mysql'),
            'post_modified_gmt' => current_time('mysql', 1),
        ]);
        if (is_wp_error($update_res)) {
            $err = 'خطا در به‌روزرسانی پست وردپرس: ' . $update_res->get_error_message();
            evazar_import_log('error', $err, $dkp, ['title' => $raw_title, 'post_id' => $post_id]);
            return $update_res;
        }
    } else {
        $post_id = wp_insert_post([
            'post_title' => $raw_title,
            'post_content' => $final_desc,
            'post_excerpt' => '', // توضیحات کوتاه کاملاً حذف شد
            'post_status' => 'publish',
            'post_type' => 'evazar_product'
        ]);
        if (is_wp_error($post_id) || empty($post_id)) {
            $err = is_wp_error($post_id) ? $post_id->get_error_message() : 'عدم امکان ایجاد پست در وردپرس.';
            evazar_import_log('error', 'خطا در ثبت پست وردپرس: ' . $err, $dkp, ['title' => $raw_title]);
            return is_wp_error($post_id) ? $post_id : new WP_Error('DB_INSERT_FAILED', $err);
        }
    }

    if ($post_id) {
        delete_post_meta($post_id, '_evazar_short_desc'); // پاکسازی متادیتاهای قدیمی توضیحات کوتاه
        update_post_meta($post_id, '_evazar_sku', $dkp);
        update_post_meta($post_id, '_sku', $dkp);
        update_post_meta($post_id, '_evazar_dk_url', "https://www.digikala.com/product/dkp-{$dkp}/");
        update_post_meta($post_id, '_evazar_en_title', $en_title);
        update_post_meta($post_id, '_evazar_old_price', $rrp_price);
        update_post_meta($post_id, '_evazar_current_price', $selling_price);
        update_post_meta($post_id, '_evazar_discount_percent', $discount_text);
        update_post_meta($post_id, '_evazar_rating', $rating);
        update_post_meta($post_id, '_evazar_rating_count', $rating_count);
        update_post_meta($post_id, '_evazar_satisfaction', $satisfaction_val);
        update_post_meta($post_id, '_evazar_brand', $brand_val);
        update_post_meta($post_id, '_evazar_brand_en', $brand_en_val);
        update_post_meta($post_id, '_evazar_breadcrumbs', $breadcrumbs_val);
        update_post_meta($post_id, '_evazar_category', $category_name);
        update_post_meta($post_id, '_evazar_key_features', str_replace('- ', '', $specs_text));
        update_post_meta($post_id, '_evazar_pros', $pros);
        update_post_meta($post_id, '_evazar_cons', $cons);
        update_post_meta($post_id, '_evazar_ai_status_msg', $ai_status_msg);

        // وضعیت موجودی از همان پاسخ Import ذخیره می‌شود تا فیلتر موجودی به داده تاییدشده تکیه کند.
        if ($import_stock_status === null) {
            $existing_stock_status = get_post_meta($post_id, '_evazar_stock_status', true);
            if ($existing_stock_status === '' && !get_post_meta($post_id, '_evazar_is_available', true)) {
                $import_stock_status = -1;
            }
        }
        if ($import_stock_status !== null) {
            update_post_meta($post_id, '_evazar_stock_status', (string) $import_stock_status);
            update_post_meta($post_id, '_evazar_last_stock_check', current_time('timestamp'));
            update_post_meta($post_id, '_evazar_is_available', $import_stock_status === 1 ? '1' : '0');
            if ($import_availability_source !== '') {
                update_post_meta($post_id, '_evazar_availability_source', $import_availability_source);
            }
        }
        
        // همگام‌سازی فیلدهای سئوی بومی رنک‌مث (Rank Math Integration)
        update_post_meta($post_id, 'rank_math_description', $ai_meta_desc);
        update_post_meta($post_id, 'rank_math_focus_keyword', $ai_focus_kw);
        if (!get_post_meta($post_id, 'rank_math_robots', true)) {
            update_post_meta($post_id, 'rank_math_robots', ['index']);
        }
        
        // ذخیره لینک عکس‌ها برای قالب (فرمت امن JSON بدون شکستن کوئری استرینگ‌ها)
        update_post_meta($post_id, '_evazar_main_image', $main_img);
        update_post_meta($post_id, '_evazar_gallery', json_encode(array_values($gallery), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // ۱. ساخت سلسله‌مراتبی و اتصال دسته‌بندی‌ها به کالا در evazar_category
        evazar_sync_product_categories($post_id, $breadcrumbs_val, $category_name, $brand_val);

        // ۲. ساخت و اتصال برند به کالا در evazar_brand
        if (!empty($brand_val)) {
            evazar_sync_product_brand($post_id, $brand_val, $brand_en_val);
        }

        // ایندکس سریع محصول برای فیلتر و مرتب‌سازی
        if (function_exists('evazar_product_index_sync')) {
            evazar_product_index_sync($post_id);
        }

        // ۳. پاکسازی کش صفحات و آبجکت محصول (LiteSpeed, WP Rocket, Cloudflare)
        evazar_purge_product_cache($post_id);
    }

    // ثبت گزارش موفقیت در لاگ سبک فایل‌محور
    $action_title = $is_update ? 'به‌روزرسانی موفق' : 'واردات و انتشار موفق';
    $source_name = $product_loaded ? 'ورکر کلودفلر' : 'API مستقیم دیجی‌کالا';
    $summary_msg = "{$action_title} از طریق {$source_name}. {$ai_status_msg}";
    evazar_import_log('success', $summary_msg, $dkp, [
        'title'   => $raw_title,
        'post_id' => $post_id,
        'source'  => $source_name
    ]);

    return $post_id;
}

/**
 * ایجاد سلسله‌مراتبی دسته‌بندی‌ها و اتصال به کالا در تاکسونومی evazar_category
 * شامل ایجاد زیردسته تخصصی لندینگ برند + دسته برای سئو و تطابق کامل با دیجی‌کالا
 */
function evazar_sync_product_categories($post_id, $breadcrumbs_data, $category_name = '', $brand_name = '') {
    $tax = 'evazar_category';
    if (!taxonomy_exists($tax)) {
        return false;
    }

    $titles = [];
    
    if (!empty($breadcrumbs_data)) {
        $raw_crumbs = is_string($breadcrumbs_data) ? json_decode($breadcrumbs_data, true) : $breadcrumbs_data;
        if (is_array($raw_crumbs)) {
            foreach ($raw_crumbs as $crumb) {
                $title = is_array($crumb) ? ($crumb['title'] ?? $crumb['name'] ?? '') : (string)$crumb;
                $title = trim($title);
                $uri = is_array($crumb) ? ($crumb['url']['uri'] ?? ($crumb['url'] ?? '')) : '';
                
                // فیلتر کردن ریشه دیجی‌کالا یا لینک محصول خود کالا
                if (empty($title) || $title === 'دیجی‌کالا' || strpos($uri, '/product/') !== false || stripos($title, 'dkp-') === 0) {
                    continue;
                }
                
                $titles[] = $title;
            }
        }
    }

    if (empty($titles) && !empty($category_name)) {
        $titles[] = trim($category_name);
    }

    if (empty($titles)) {
        return false;
    }

    // ایجاد زیردسته لندینگ برند + دسته برای سئو و ساختار کامل مشابه دیجی‌کالا (مانند «هارد دیسک اکسترنال وسترن دیجیتال» یا «دسته بازی فیلیپس»)
    if (empty($brand_name)) {
        $brand_name = get_post_meta($post_id, '_evazar_brand', true);
    }
    $brand_name = trim((string)$brand_name);
    if (!empty($brand_name) && !in_array($brand_name, ['متفرقه', 'دیجی‌کالا', 'دیجیکالا', 'عمومی'])) {
        $last_cat_title = end($titles);
        if (!empty($last_cat_title) && mb_stripos($last_cat_title, $brand_name) === false) {
            $titles[] = $last_cat_title . ' ' . $brand_name;
        }
    }

    $parent_id = 0;
    $term_ids = [];

    foreach ($titles as $cat_title) {
        $cat_title = trim($cat_title);
        if (empty($cat_title)) continue;

        // جستجوی ترم موجود در تاکسونومی
        $term = get_term_by('name', $cat_title, $tax);
        $term_id = 0;

        if ($term && !is_wp_error($term)) {
            $term_id = intval($term->term_id);
            // اگر ترم قبلاً وجود داشته اما والد آن ثبت نشده یا متفاوت است، والد را تنظیم کن
            if ($parent_id > 0 && intval($term->parent) !== $parent_id && $term_id !== $parent_id) {
                wp_update_term($term_id, $tax, ['parent' => $parent_id]);
                clean_term_cache($term_id, $tax);
            }
        } else {
            $slug = sanitize_title($cat_title);
            $res = wp_insert_term($cat_title, $tax, [
                'parent' => $parent_id,
                'slug'   => $slug
            ]);

            if (!is_wp_error($res)) {
                $term_id = intval($res['term_id']);
            } elseif ($res->get_error_code() === 'term_exists') {
                $term_id = intval($res->get_error_data());
                if ($parent_id > 0 && $term_id !== $parent_id) {
                    wp_update_term($term_id, $tax, ['parent' => $parent_id]);
                    clean_term_cache($term_id, $tax);
                }
            } else {
                // تلاش مجدد بدون مشخص کردن اسلاگ
                $res2 = wp_insert_term($cat_title, $tax, [
                    'parent' => $parent_id
                ]);
                if (!is_wp_error($res2)) {
                    $term_id = intval($res2['term_id']);
                } elseif ($res2->get_error_code() === 'term_exists') {
                    $term_id = intval($res2->get_error_data());
                    if ($parent_id > 0 && $term_id !== $parent_id) {
                        wp_update_term($term_id, $tax, ['parent' => $parent_id]);
                        clean_term_cache($term_id, $tax);
                    }
                }
            }
        }

        if ($term_id > 0) {
            $term_ids[] = $term_id;
            $parent_id = $term_id;

            // استخراج و ذخیره اسلاگ دیجی‌کالا برای جلوگیری از توهم در تولید محتوا
            if (!empty($uri)) {
                $dk_slug = '';
                if (preg_match('#/search/([^/]+)/?#i', $uri, $matches)) {
                    $dk_slug = $matches[1];
                } elseif (preg_match('#/main/([^/]+)/?#i', $uri, $matches)) {
                    $dk_slug = 'category-' . $matches[1];
                }
                if (!empty($dk_slug)) {
                    update_term_meta($term_id, '_evazar_dk_slug', $dk_slug);
                }
            }

            // تولید خودکار راهنمای خرید سئو شده برای دسته‌بندی جدید (فقط یک‌بار)
            evazar_ai_ensure_term_seo_content($term_id, $tax, $cat_title, 'category');
        }
    }

    if (!empty($term_ids)) {
        wp_set_object_terms($post_id, $term_ids, $tax, false);
        return count($term_ids);
    }

    return false;
}

/**
 * ترمیم هوشمند والدها و ساختار درختی یک دسته‌بندی از روی متادیتای کالاهای آن دسته
 */
if (!function_exists('evazar_repair_term_ancestors')) {
    function evazar_repair_term_ancestors($term_id) {
        $tax = 'evazar_category';
        $term = get_term($term_id, $tax);
        if (!$term || is_wp_error($term) || intval($term->parent) > 0) {
            return;
        }

        // جستجوی یک نمونه کالا که دارای بردکرامب ذخیره‌شده است
        $posts = get_posts([
            'post_type'      => 'evazar_product',
            'posts_per_page' => 1,
            'tax_query'      => [
                [
                    'taxonomy' => $tax,
                    'field'    => 'term_id',
                    'terms'    => $term_id,
                ]
            ],
            'meta_query'     => [
                [
                    'key'     => '_evazar_breadcrumbs',
                    'compare' => 'EXISTS'
                ]
            ]
        ]);

        if (empty($posts)) {
            $posts = get_posts([
                'post_type'      => 'evazar_product',
                'posts_per_page' => 1,
                'meta_query'     => [
                    [
                        'key'     => '_evazar_category',
                        'value'   => $term->name,
                        'compare' => '='
                    ]
                ]
            ]);
        }

        if (empty($posts)) {
            return;
        }

        $sample_post = $posts[0];
        $bc_meta = get_post_meta($sample_post->ID, '_evazar_breadcrumbs', true);
        if (empty($bc_meta)) {
            return;
        }

        $raw_crumbs = is_string($bc_meta) ? json_decode($bc_meta, true) : $bc_meta;
        if (!is_array($raw_crumbs)) {
            return;
        }

        $titles = [];
        foreach ($raw_crumbs as $crumb) {
            $title = is_array($crumb) ? ($crumb['title'] ?? $crumb['name'] ?? '') : (string)$crumb;
            $title = trim($title);
            $uri = is_array($crumb) ? ($crumb['url']['uri'] ?? ($crumb['url'] ?? '')) : '';
            if (empty($title) || $title === 'دیجی‌کالا' || strpos($uri, '/product/') !== false || stripos($title, 'dkp-') === 0) {
                continue;
            }
            $titles[] = $title;
        }

        if (in_array($term->name, $titles)) {
            $parent_id = 0;
            foreach ($titles as $t_title) {
                $existing = get_term_by('name', $t_title, $tax);
                $curr_id = 0;
                if ($existing && !is_wp_error($existing)) {
                    $curr_id = intval($existing->term_id);
                    if ($parent_id > 0 && intval($existing->parent) !== $parent_id && $curr_id !== $parent_id) {
                        wp_update_term($curr_id, $tax, ['parent' => $parent_id]);
                        clean_term_cache($curr_id, $tax);
                    }
                } else {
                    $res = wp_insert_term($t_title, $tax, ['parent' => $parent_id]);
                    if (!is_wp_error($res)) {
                        $curr_id = intval($res['term_id']);
                    }
                }
                if ($curr_id > 0) {
                    $parent_id = $curr_id;
                }
                if ($t_title === $term->name) {
                    break;
                }
            }
            clean_taxonomy_cache($tax);
        }
    }
}

/**
 * استخراج زنده داده‌های کالا (شامل قیمت روز بای‌باکس دیجی‌کالا، فروشنده برنده، بردکرامب و دسته‌بندی)
 */

/**
 * تشخیص موجودی واقعی دیجی‌کالا با اولویت سیگنال‌های صریح بای‌باکس/واریانت.
 * قیمت به‌تنهایی هرگز نشانه موجود بودن نیست؛ اما اگر API وضعیت صریحی ندهد،
 * ترکیب محصول marketable + واریانت دارای فروشنده + قیمت معتبر به‌عنوان fallback
 * قابل اتکا استفاده می‌شود تا false-negative ایجاد نشود.
 */
function evazar_detect_digikala_availability($product, $variant = []) {
    $product = is_array($product) ? $product : [];
    $variant = is_array($variant) ? $variant : [];

    $negative_tokens = [
        'unavailable','out_of_stock','out-of-stock','outofstock','sold_out','sold-out',
        'not_available','not-available','not_in_stock','not-in-stock','inactive','disabled',
        'ناموجود','اتمام موجودی','تمام شد','تمام‌شد','موجود نیست','قابل خرید نیست'
    ];
    $positive_tokens = [
        'marketable','available','in_stock','in-stock','instock','purchasable','buyable',
        'موجود','قابل خرید','قابل‌خرید'
    ];
    $boolean_keys = [
        'is_available','available','in_stock','is_in_stock','is_marketable','marketable',
        'is_purchasable','purchasable','buyable','is_buyable'
    ];
    $string_keys = [
        'status','availability','availability_status','stock_status','availability_text',
        'status_text','stock_text','purchase_status'
    ];

    $neg = false;
    $pos = false;
    $seen_bool = false;

    foreach ([$product, $variant] as $obj) {
        foreach ($boolean_keys as $key) {
            if (!array_key_exists($key, $obj)) continue;
            $v = $obj[$key];
            if (is_bool($v)) {
                $seen_bool = true;
                if ($v) $pos = true; else $neg = true;
            } elseif (is_numeric($v) && ($v === 0 || $v === 1 || $v === '0' || $v === '1')) {
                $seen_bool = true;
                if ((int)$v === 1) $pos = true; else $neg = true;
            } elseif (is_string($v)) {
                $lv = mb_strtolower(trim($v), 'UTF-8');
                if (in_array($lv, ['true','yes','on','1'], true)) { $seen_bool = true; $pos = true; }
                if (in_array($lv, ['false','no','off','0'], true)) { $seen_bool = true; $neg = true; }
            }
        }
        foreach ($string_keys as $key) {
            if (!isset($obj[$key]) || !is_scalar($obj[$key])) continue;
            $v = mb_strtolower(trim((string)$obj[$key]), 'UTF-8');
            if ($v === '') continue;
            foreach ($negative_tokens as $token) {
                if (mb_strpos($v, mb_strtolower($token, 'UTF-8')) !== false) { $neg = true; break; }
            }
            if ($v === 'marketable' || in_array($v, $positive_tokens, true)) $pos = true;
        }
    }

    if ($neg) return false;
    if ($pos) return true;

    $product_status = mb_strtolower(trim((string)($product['status'] ?? '')), 'UTF-8');
    $variant_status = mb_strtolower(trim((string)($variant['status'] ?? '')), 'UTF-8');
    if ($product_status === 'marketable' || $variant_status === 'marketable') return true;
    foreach (array_merge([$product_status, $variant_status], array_map('strval', [
        $variant['availability_text'] ?? '', $variant['availability'] ?? '',
        $product['availability_text'] ?? '', $product['availability'] ?? ''
    ])) as $text) {
        $text = mb_strtolower(trim($text), 'UTF-8');
        if ($text === '') continue;
        foreach ($negative_tokens as $token) {
            if (mb_strpos($text, mb_strtolower($token, 'UTF-8')) !== false) return false;
        }
        foreach ($positive_tokens as $token) {
            if (mb_strpos($text, mb_strtolower($token, 'UTF-8')) !== false) return true;
        }
    }

    // Fallback محافظه‌کارانه برای پاسخ‌هایی که status عمومی را ناقص برمی‌گردانند.
    $selling_price = isset($variant['price']['selling_price']) ? (int)$variant['price']['selling_price'] : 0;
    $has_seller = !empty($variant['seller']);
    if (!$neg && !empty($variant) && $selling_price > 0 && $has_seller) {
        return true;
    }
    return false;
}

/**
 * همگام‌سازی تدریجی موجودی کالاها از مسیر Cloudflare Worker.
 * نسخه 2.8.60: هر 5 دقیقه، 50 کالا؛ اولویت با کالاهای بررسی‌نشده و قدیمی‌تر.
 */
add_filter('cron_schedules', function($schedules) {
    $schedules['evazar_stock_5min'] = [
        'interval' => 300,
        'display'  => 'هر ۵ دقیقه - موجودی ایوازار',
    ];
    return $schedules;
});

function evazar_stock_sync_schedule() {
    $event = wp_get_scheduled_event('evazar_sync_stock_batch');
    if ($event && isset($event->interval) && (int)$event->interval !== 300) {
        wp_unschedule_event($event->timestamp, 'evazar_sync_stock_batch');
        $event = false;
    }
    if (!$event) {
        wp_schedule_event(time() + 60, 'evazar_stock_5min', 'evazar_sync_stock_batch');
    }
}

function evazar_stock_sync_clear_schedule() {
    $timestamp = wp_next_scheduled('evazar_sync_stock_batch');
    while ($timestamp) {
        wp_unschedule_event($timestamp, 'evazar_sync_stock_batch');
        $timestamp = wp_next_scheduled('evazar_sync_stock_batch');
    }
}

add_action('init', function() {
    if (function_exists('evazar_stock_sync_schedule')) {
        evazar_stock_sync_schedule();
    }
}, 20);

add_action('evazar_sync_stock_batch', function() {
    global $wpdb;

    // 50 کالا در هر 5 دقیقه ≈ 14,400 استعلام در روز در صورت اجرای منظم WP-Cron.
    $limit = 50;
    $lock_key = 'evazar_stock_batch_lock_v2';
    if (get_transient($lock_key)) {
        return;
    }
    set_transient($lock_key, 4 * MINUTE_IN_SECONDS + 30, 4 * MINUTE_IN_SECONDS + 30);

    $rows = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} ss ON ss.post_id = p.ID AND ss.meta_key = '_evazar_stock_status'
             LEFT JOIN {$wpdb->postmeta} sc ON sc.post_id = p.ID AND sc.meta_key = '_evazar_last_stock_check'
             WHERE p.post_type = 'evazar_product'
               AND p.post_status = 'publish'
             ORDER BY
               CASE WHEN ss.meta_value IS NULL OR ss.meta_value = '' OR ss.meta_value = '-1' THEN 0 ELSE 1 END ASC,
               COALESCE(CAST(sc.meta_value AS UNSIGNED), 0) ASC,
               p.ID ASC
             LIMIT %d",
            $limit
        )
    );

    foreach ((array)$rows as $post_id) {
        evazar_inquire_product_price((int)$post_id, true);
    }
    delete_transient($lock_key);
});

function evazar_fetch_raw_product_data($dkp, $force_fresh = false, $allow_direct_fallback = false) {
    $dkp = preg_replace('/[^0-9]/', '', (string)$dkp);
    if (empty($dkp)) return false;

    // ۱. استعلام از ورکر کلودفلر (با فال‌بک خودکار به دامنه ورکر img.evazar.ir)
    $cf_worker = trim(get_option('evazar_cf_worker_url', ''));
    if (empty($cf_worker)) {
        $cf_worker = trim(get_option('evazar_image_cdn_domain', 'https://img.evazar.ir'));
    }
    if (empty($cf_worker)) {
        $cf_worker = 'https://img.evazar.ir';
    }
    if (!empty($cf_worker)) {
        $params = ['dkp' => $dkp];
        if ($force_fresh) {
            $params['fresh'] = '1';
        }
        $worker_url = add_query_arg($params, trailingslashit($cf_worker));
        
        $worker_request_args = [
            'timeout' => 8,
            'headers' => evazar_worker_auth_headers(),
        ];
        $res = wp_remote_get($worker_url, $worker_request_args);
        if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200) {
            $data = json_decode(wp_remote_retrieve_body($res), true);
            if (!empty($data['product'])) {
                return $data['product'];
            }
        }
    }

    // ۲. فالبک به API مستقیم دیجی‌کالا (فقط برای مسیرهای داخلی/ادمین؛ فرانت‌اند از Worker خارج نمی‌شود)
    if (!$allow_direct_fallback) {
        return false;
    }

    $api_url = "https://api.digikala.com/v2/product/{$dkp}/";
    $res = wp_remote_get($api_url, [
        'timeout' => 7,
        'headers' => [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Referer'    => 'https://www.digikala.com/'
        ]
    ]);
    if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200) {
        $data = json_decode(wp_remote_retrieve_body($res), true);
        if (!empty($data['data']['product'])) {
            $p = $data['data']['product'];
            $dv = $p['default_variant'] ?? [];
            $price_obj = $dv['price'] ?? [];
            $selling_price = isset($price_obj['selling_price']) ? floor(intval($price_obj['selling_price']) / 10) : 0;
            $rrp_price = isset($price_obj['rrp_price']) ? floor(intval($price_obj['rrp_price']) / 10) : $selling_price;
            $discount = $price_obj['discount_percent'] ?? 0;
            $is_available = evazar_detect_digikala_availability($p, $dv);
            $import_stock_status = $is_available ? 1 : 0;
            $seller_title = $dv['seller']['title'] ?? 'دیجی‌کالا';
            $seller_code = $dv['seller']['code'] ?? '';
            $seller_stars = $dv['seller']['stars'] ?? null;
            $warranty = $dv['warranty']['title_fa'] ?? '';

            $raw_crumbs = $data['data']['breadcrumb'] ?? $data['data']['breadcrumbs'] ?? $p['breadcrumb'] ?? $p['breadcrumb_list'] ?? [];
            $bc_arr = [];
            if (!empty($raw_crumbs) && is_array($raw_crumbs)) {
                foreach ($raw_crumbs as $bc) {
                    $t = trim($bc['title'] ?? $bc['title_fa'] ?? $bc['name'] ?? '');
                    if (!empty($t) && $t !== 'دیجی‌کالا' && stripos($t, 'dkp-') !== 0) {
                        $bc_arr[] = ['title' => $t, 'url' => $bc['url']['uri'] ?? ($bc['url'] ?? '')];
                    }
                }
            }
            $cat = trim($p['category']['title_fa'] ?? $p['category']['title'] ?? '');
            if (empty($bc_arr) && !empty($cat)) {
                $bc_arr[] = ['title' => $cat, 'url' => ''];
            }
            $sync_rating_count = isset($p['rating']['count']) ? intval($p['rating']['count']) : (isset($p['rating_count']) ? intval($p['rating_count']) : null);
            $raw_sync_rating = $p['rating']['rate'] ?? ($p['rating'] ?? null);
            if ($raw_sync_rating !== null && $raw_sync_rating > 5) $raw_sync_rating = round($raw_sync_rating / 20, 1);

            $sync_rating = 0;
            $sync_satisfaction = 0;
            if ($sync_rating_count !== null && $sync_rating_count > 0 && $raw_sync_rating !== null && $raw_sync_rating > 0) {
                $sync_rating = floatval($raw_sync_rating);
                if (!empty($p['rating']['count_buyers']) && !empty($p['rating']['count']) && $p['rating']['count'] > 0) {
                    $sync_satisfaction = round(($p['rating']['count_buyers'] / $p['rating']['count']) * 100);
                    if ($sync_satisfaction > 100) $sync_satisfaction = 100;
                } elseif (!empty($p['satisfaction'])) {
                    $sync_satisfaction = intval($p['satisfaction']);
                } else {
                    $sync_satisfaction = round(($sync_rating / 5) * 100);
                }
            } else {
                $sync_rating = 0;
                $sync_satisfaction = 0;
                if ($sync_rating_count === null) $sync_rating_count = 0;
            }

            return [
                'id'                  => $p['id'] ?? $dkp,
                'title_fa'            => $p['title_fa'] ?? '',
                'price_toman'         => $selling_price,
                'regular_price_toman' => $rrp_price,
                'discount_percent'    => $discount,
                'is_available'        => $is_available,
                'availability_source' => 'fallback',
                'seller'              => $seller_title,
                'seller_code'         => $seller_code,
                'seller_rating'       => $seller_stars,
                'warranty'            => $warranty,
                'breadcrumbs'         => $bc_arr,
                'category'            => $cat ?: (!empty($bc_arr) ? end($bc_arr)['title'] : ''),
                'brand'               => (!empty($p['brand']['title_fa']) && !in_array($p['brand']['title_fa'], ['دیجی‌کالا', 'دیجیکالا', 'عمومی'])) ? $p['brand']['title_fa'] : '',
                'brand_en'            => $p['brand']['title_en'] ?? '',
                'rating'              => $sync_rating,
                'rating_count'        => $sync_rating_count,
                'satisfaction'        => $sync_satisfaction
            ];
        }
    }

    return false;
}

/**
 * استعلام و همگام‌سازی بلادرنگ قیمت و بای‌باکس کالا از دیجی‌کالا
 */
function evazar_inquire_product_price($post_id, $force_fresh = false) {
    $post_id = intval($post_id);
    if (!$post_id || get_post_type($post_id) !== 'evazar_product') {
        return false;
    }

    $sku = get_post_meta($post_id, '_evazar_sku', true) ?: get_post_meta($post_id, '_sku', true);
    if (empty($sku)) {
        $dk_url = get_post_meta($post_id, '_evazar_dk_url', true);
        if ($dk_url && preg_match('/dkp-(\d+)/i', $dk_url, $m)) {
            $sku = $m[1];
        }
    }
    if (empty($sku)) {
        return false;
    }

    // کش محلی کوتاه برای پاسخ سریع به بازدیدهای همزمان
    $cache_key = 'evz_live_inq_' . $post_id;
    if (!$force_fresh) {
        $cached = get_transient($cache_key);
        if ($cached && is_array($cached)) {
            return $cached;
        }
    }

    $lock_key = 'evz_live_lock_' . $post_id;
    $lock_acquired = wp_cache_add($lock_key, 1, 'evazar_live', 12);
    if (!$lock_acquired) {
        // اگر درخواست دیگری در حال بروزرسانی است، داده ذخیره‌شده فعلی را تحویل بده تا صفحه معطل نشود.
        $stored_price = intval(get_post_meta($post_id, '_evazar_current_price', true));
        if ($stored_price > 0) {
            $stored_old = intval(get_post_meta($post_id, '_evazar_old_price', true));
            $stored_disc = intval(get_post_meta($post_id, '_evazar_discount_percent', true));
            $stored_source = (string)get_post_meta($post_id, '_evazar_availability_source', true);
            $stored_available = ($stored_price > 0 && $stored_source !== 'explicit_unavailable');
            $stored_seller = get_post_meta($post_id, '_evazar_seller_name', true) ?: 'دیجی‌کالا';
            return [
                'post_id' => $post_id,
                'sku' => $sku,
                'price' => $stored_price,
                'price_formatted' => function_exists('evazar_format_price') ? evazar_format_price($stored_price) : number_format($stored_price),
                'old_price' => $stored_old,
                'old_price_formatted' => ($stored_old > $stored_price) ? (function_exists('evazar_format_price') ? evazar_format_price($stored_old) : number_format($stored_old)) : '',
                'discount_percent' => $stored_disc > 0 ? (function_exists('evazar_to_fa_num') ? evazar_to_fa_num($stored_disc) . '٪' : $stored_disc . '٪') : '',
                'seller' => $stored_seller,
                'seller_rate_text' => '',
                'warranty' => '',
                'lead_time' => '',
                'is_available' => $stored_available,
                'availability_source' => (string)get_post_meta($post_id, '_evazar_availability_source', true),
                'stale' => true,
                'sync_status' => 'busy',
                'checked_at' => time(),
            ];
        }
        return false;
    }

    try {
        // از کش عادی Worker استفاده کن؛ force fresh فقط برای مسیرهای داخلی آینده محفوظ می‌ماند.
        $fresh = evazar_fetch_raw_product_data($sku, $force_fresh, false);
        if (!$fresh || !isset($fresh['price_toman'])) {
            // شکست استعلام نباید داده معتبر قبلی را پاک یا ناموجود اعلام کند.
            update_post_meta($post_id, '_evazar_price_sync_status', 'error');
            update_post_meta($post_id, '_evazar_price_sync_failed_at', time());
            update_post_meta($post_id, '_evazar_price_sync_error', 'worker_or_source_unavailable');
            return false;
        }

    $new_price = intval($fresh['price_toman']);
    $new_old_price = intval($fresh['regular_price_toman'] ?? $new_price);
    $new_discount = intval($fresh['discount_percent'] ?? 0);
    $availability_source = in_array((string)($fresh['availability_source'] ?? ''), ['explicit_unavailable','explicit_available','fallback'], true)
        ? (string)$fresh['availability_source']
        : 'fallback';
    // Canonical availability for Evazar: price must exist; explicit_unavailable wins.
    $is_available = ($new_price > 0 && $availability_source !== 'explicit_unavailable');
    $seller = !empty($fresh['seller']) ? sanitize_text_field($fresh['seller']) : 'دیجی‌کالا';
    $warranty = !empty($fresh['warranty']) ? sanitize_text_field($fresh['warranty']) : '';
    $lead_time = !empty($fresh['lead_time']) ? sanitize_text_field($fresh['lead_time']) : '';

    // به‌روزرسانی مشخصات بای‌باکس در پایگاه داده وردپرس
    if ($new_price > 0 || !$is_available) {
        // بررسی و ثبت خودکار کاهش قیمت (Price Drop Detection)
        $stored_cur_price = intval(get_post_meta($post_id, '_evazar_current_price', true));
        if ($stored_cur_price > 0 && $new_price > 0 && $new_price < $stored_cur_price) {
            $drop_diff = $stored_cur_price - $new_price;
            update_post_meta($post_id, '_evazar_price_drop_time', time());
            update_post_meta($post_id, '_evazar_price_drop_diff', $drop_diff);
            update_post_meta($post_id, '_evazar_price_drop_prev', $stored_cur_price);
        } elseif ($stored_cur_price > 0 && $new_price > $stored_cur_price) {
            delete_post_meta($post_id, '_evazar_price_drop_time');
            delete_post_meta($post_id, '_evazar_price_drop_diff');
            delete_post_meta($post_id, '_evazar_price_drop_prev');
        }

        $meta_changed = false;
        $set_meta_if_changed = static function($key, $value) use ($post_id, &$meta_changed) {
            $old = get_post_meta($post_id, $key, true);
            if ((string) $old !== (string) $value) {
                update_post_meta($post_id, $key, $value);
                $meta_changed = true;
            }
        };

        $set_meta_if_changed('_evazar_current_price', $new_price);
        $set_meta_if_changed('_evazar_old_price', $new_old_price);
        $set_meta_if_changed('_evazar_discount_percent', $new_discount);
        $set_meta_if_changed('_evazar_seller_name', $seller);
        if (isset($fresh['seller_rating']) && $fresh['seller_rating'] !== null) {
            $set_meta_if_changed('_evazar_seller_rating', floatval($fresh['seller_rating']));
        }
        $set_meta_if_changed('_evazar_stock_status', $is_available ? '1' : '0');
        $set_meta_if_changed('_evazar_is_available', $is_available ? '1' : '0');
        $set_meta_if_changed('_evazar_availability_source', $availability_source);

        // زمان آخرین تغییر واقعی داده‌ها؛ زمان check در پاسخ AJAX جداگانه اعلام می‌شود.
        if ($meta_changed) {
            $set_meta_if_changed('_evazar_last_price_sync', time());
        }

        // همگام‌سازی امتیاز، تعداد آرا و درصد رضایت فقط در صورت تغییر
        if (isset($fresh['rating']) && $fresh['rating'] !== null) {
            $set_meta_if_changed('_evazar_rating', floatval($fresh['rating']));
        }
        if (isset($fresh['rating_count']) && $fresh['rating_count'] !== null) {
            $set_meta_if_changed('_evazar_rating_count', intval($fresh['rating_count']));
        }
        if (isset($fresh['satisfaction']) && $fresh['satisfaction'] !== null) {
            $set_meta_if_changed('_evazar_satisfaction', intval($fresh['satisfaction']));
        }

        // این هسته فروش مستقیم ندارد؛ متاهای قدیمی ووکامرس (_price و ... ) عمداً نوشته نمی‌شوند.

    }

    // آخرین زمان استعلام موفق Live را برای Smart Stock Filter نگه می‌داریم؛ حداکثر هر ۹۰ ثانیه یک Write.
    $last_live_check = intval(get_post_meta($post_id, '_evazar_last_live_check', true));
    if ($last_live_check < (time() - 90)) {
        update_post_meta($post_id, '_evazar_last_live_check', time());
    }

    // آخرین بررسی معتبر موجودی؛ این فیلد مبنای صف همگام‌سازی موجودی است.
    update_post_meta($post_id, '_evazar_last_stock_check', time());

    if (function_exists('evazar_product_index_sync')) {
        evazar_product_index_sync($post_id);
    }

    $disc_label = '';
    if ($new_discount > 0) {
        $disc_label = function_exists('evazar_to_fa_num') ? evazar_to_fa_num($new_discount) . '٪' : $new_discount . '٪';
    }

    $price_formatted = function_exists('evazar_format_price') ? evazar_format_price($new_price) : number_format($new_price);
    $old_price_formatted = ($new_old_price > $new_price) ? (function_exists('evazar_format_price') ? evazar_format_price($new_old_price) : number_format($new_old_price)) : '';

    $res = [
        'post_id'             => $post_id,
        'sku'                 => $sku,
        'price'               => $new_price,
        'price_formatted'     => $price_formatted,
        'old_price'           => $new_old_price,
        'old_price_formatted' => $old_price_formatted,
        'discount_percent'    => $disc_label,
        'seller'              => $seller,
        'seller_rate_text'    => '',
        'seller_rating'       => isset($fresh['seller_rating']) ? floatval($fresh['seller_rating']) : null,
        'warranty'            => $warranty,
        'lead_time'           => $lead_time,
        'is_available'        => $is_available,
        'availability_source' => $availability_source,
        'rating'              => isset($fresh['rating']) ? floatval($fresh['rating']) : floatval(get_post_meta($post_id, '_evazar_rating', true)),
        'rating_count'        => isset($fresh['rating_count']) ? intval($fresh['rating_count']) : intval(get_post_meta($post_id, '_evazar_rating_count', true)),
        'satisfaction'        => isset($fresh['satisfaction']) ? intval($fresh['satisfaction']) : intval(get_post_meta($post_id, '_evazar_satisfaction', true)),
    ];

    update_post_meta($post_id, '_evazar_price_sync_status', 'ok');
    delete_post_meta($post_id, '_evazar_price_sync_error');
    update_post_meta($post_id, '_evazar_price_sync_last_success', time());

    $res['stale'] = false;
    $res['checked_at'] = time();
    set_transient($cache_key, $res, 90);
    return $res;
    } finally {
        wp_cache_delete($lock_key, 'evazar_live');
    }
}

/** وضعیت استاندارد موجودی برای استفاده مشترک فیلترها و Live Inquiry. */
function evazar_get_stock_status($post_id) {
    $post_id = absint($post_id);
    if (!$post_id) return 0;
    $price = (string)get_post_meta($post_id, '_evazar_current_price', true);
    $price = str_replace(['٬', ',', '،', ' '], '', $price);
    $price = strtr($price, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
    $price = is_numeric($price) ? (float)$price : 0.0;
    $source = (string)get_post_meta($post_id, '_evazar_availability_source', true);
    if ($source === 'explicit_unavailable') return 0;
    return $price > 0 ? 1 : 0;
}

// هوک آژاکس استعلام لحظه‌ای و بی‌درنگ در فرانت‌اند
add_action('wp_ajax_evazar_live_product_inquiry', 'evazar_ajax_live_product_inquiry');
add_action('wp_ajax_nopriv_evazar_live_product_inquiry', 'evazar_ajax_live_product_inquiry');
function evazar_ajax_live_product_inquiry() {
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    if (!$post_id) {
        wp_send_json_error(['message' => 'شناسه کالا نامعتبر است.']);
    }

    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!wp_verify_nonce($nonce, 'evazar_live_product_nonce')) {
        wp_send_json_error(['message' => 'درخواست نامعتبر است.'], 403);
    }

    // force برای درخواست‌های عمومی عمداً حذف شده است تا هیچ کاربری نتواند کش را دور بزند.
    $result = evazar_inquire_product_price($post_id, false);
    if ($result) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error(['message' => 'استعلام قیمت ناموفق بود.']);
    }
}

/**
 * اتصال و ثبت برند در تاکسونومی evazar_brand
 */
function evazar_sync_product_brand($post_id, $brand_fa, $brand_en = '') {
    $tax = 'evazar_brand';
    if (!taxonomy_exists($tax)) {
        return;
    }
    
    $brand_fa = trim($brand_fa);
    if (empty($brand_fa) || in_array($brand_fa, ['دیجی‌کالا', 'دیجیکالا'])) {
        return;
    }

    $slug = !empty($brand_en) ? sanitize_title($brand_en) : sanitize_title($brand_fa);

    // بررسی وجود ترم بر اساس نام یا نامک
    $term = get_term_by('slug', $slug, $tax);
    if (!$term) {
        $term = get_term_by('name', $brand_fa, $tax);
    }

    $term_id = 0;
    if ($term) {
        $term_id = intval($term->term_id);
    } else {
        $created = wp_insert_term($brand_fa, $tax, [
            'slug' => $slug
        ]);
        if (!is_wp_error($created)) {
            $term_id = intval($created['term_id']);
        } elseif ($created->get_error_code() === 'term_exists') {
            $term_id = intval($created->get_error_data());
        }
    }

    if (!empty($term_id)) {
        if (!empty($brand_en)) {
            update_term_meta($term_id, '_evazar_brand_en', sanitize_text_field($brand_en));
        }
        wp_set_object_terms($post_id, [$term_id], $tax, false);
        // تولید خودکار راهنمای خرید سئو شده برای برند جدید (فقط یک‌بار)
        if (!empty($brand_en)) {
            update_term_meta($term_id, '_evazar_dk_slug', strtolower(trim($brand_en)));
        }
        evazar_ai_ensure_term_seo_content($term_id, $tax, $brand_fa, 'brand', $brand_en);
    }
}

/**
 * تولید خودکار محتوای راهنمای خرید سئو شده برای دسته‌ها و برندهای جدید توسط هوش مصنوعی
 * (اجرا فقط یک‌بار هنگام ساخته شدن دسته/برند جهت حفظ حداکثر سرعت و صرفه‌جویی ۱۰۰٪ در توکن)
 */
function evazar_ai_ensure_term_seo_content($term_id, $taxonomy, $term_name, $context_type = 'category', $brand_en = '') {
    $term_id = intval($term_id);
    if ($term_id <= 0 || empty($term_name)) return;

    // ۱. بررسی قفل دستی توسط کاربر (عدم دخالت قطعی هوش مصنوعی در صورت فعال بودن قفل)
    $is_locked = get_term_meta($term_id, '_evazar_lock_seo_content', true);
    if ($is_locked === 'yes') {
        update_term_meta($term_id, '_evazar_ai_content_status', 'skipped_locked');
        return;
    }

    // ۲. بررسی وجود توضیحات قبلی - اگر محتوای سفارشی دارد دست نزن، اما اگر همان متن پیش‌فرض دیفالت است اجازه بازنویسی بده
    $term = get_term($term_id, $taxonomy);
    if (!$term || is_wp_error($term)) return;
    $existing_desc = trim(strip_tags((string)$term->description));
    $is_fallback = (mb_strpos($existing_desc, 'راهنمای خرید و بررسی تخصصی جدیدترین مدل‌های') !== false);
    if (!empty($existing_desc) && !$is_fallback) return;

    // ۳. بررسی استثنای دسته‌بندی‌ها و برندهای مشخص یا متفرقه از تولید محتوا
    $exclude_misc = get_option('evazar_ai_taxonomy_exclude_misc', 'yes');
    if ($exclude_misc === 'yes') {
        $raw_kw = get_option('evazar_ai_taxonomy_exclude_keywords', 'متفرقه, miscellaneous, عمومی');
        $kw_arr = array_filter(array_map('trim', explode(',', (string)$raw_kw)));
        if (empty($kw_arr)) {
            $kw_arr = ['متفرقه', 'miscellaneous', 'عمومی'];
        }
        $slug = $term->slug ?? '';
        foreach ($kw_arr as $kw) {
            if (empty($kw)) continue;
            if (mb_stripos($term_name, $kw) !== false || stripos($slug, $kw) !== false) {
                update_term_meta($term_id, '_evazar_ai_content_status', 'skipped_excluded');
                return; // مستثنی شده: هوش مصنوعی برای این مورد محتوا تولید نمی‌کند
            }
        }
    }

    $ai_enabled     = get_option('evazar_ai_enabled', '');
    $ai_tax_enabled = get_option('evazar_ai_taxonomy_enabled', 'yes');
    $has_ai_key     = evazar_has_ai_api_key();

    $site_name = get_bloginfo('name') ?: 'ایوازار';

    if ($ai_enabled === 'yes' && $ai_tax_enabled === 'yes' && $has_ai_key) {
        // ۱. واکشی اطلاعات از دیجی‌کالا
        $dk_slug = get_term_meta($term_id, '_evazar_dk_slug', true);
        if (empty($dk_slug) && $context_type === 'brand') {
            $dk_slug = !empty($brand_en) ? strtolower(trim($brand_en)) : '';
        }
        
        $source_content = '';
        if (!empty($dk_slug)) {
            $route = ($context_type === 'brand') 
                ? "/v1/brands/{$dk_slug}/search/"
                : "/v1/categories/{$dk_slug}/search/";
                
            $worker_url = get_option('evazar_cf_worker_url', '');
            $api_url = !empty($worker_url) ? rtrim($worker_url, '/') . '/?route=' . urlencode($route) : "https://api.digikala.com{$route}";
                
            $response = wp_remote_get($api_url, [
                'timeout' => 10,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept'     => 'application/json'
                ]
            ]);
            
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                if (isset($body['data']['seo']['content'])) {
                    $source_content = trim(strip_tags((string)$body['data']['seo']['content']));
                }
            }
        }
        
        // اگر منبعی وجود نداشت، هوش مصنوعی را درگیر نمی‌کنیم تا توهم ایجاد نشود
        if (empty($source_content)) {
            update_term_meta($term_id, '_evazar_ai_content_status', 'skipped_no_source');
            return;
        }

        if ($context_type === 'brand') {
            $brand_en_val = !empty($brand_en) ? trim($brand_en) : trim((string)get_term_meta($term_id, '_evazar_brand_en', true));
            if (empty($brand_en_val) && !empty($term->slug) && preg_match('/^[a-z0-9\-_]+$/i', $term->slug) && !is_numeric($term->slug)) {
                $brand_en_val = ucwords(str_replace('-', ' ', $term->slug));
            }

            $default_prompt = "شما یک ویراستار ارشد و کارشناس سئو برای فروشگاه اینترنتی «{site_name}» هستید.\n"
                            . "نام این برند «{term_name}» است و داده‌های واقعی آن در بازار به این شرح است:\n{source_content}\n\n"
                            . "دستورالعمل نگارش (اصل تناسب و عدم تخیل):\n"
                            . "۱. طول و عمق متن خروجی باید کاملاً متناسب با حجم اطلاعات موجود در متن منبع باشد. به هیچ وجه برای افزایش تعداد کلمات به متن آب نبندید و هیچ داستان، تاریخچه یا محصولاتی اختراع نکنید.\n"
                            . "۲. اگر اطلاعات پایه کوتاه است، صرفاً به صورت روان، کاربردی و در اندازه همان اطلاعات موجود بازنویسی کنید.\n"
                            . "۳. اگر اطلاعات پایه جامع است، آن را با تیترهای جذاب H2 و H3، نکات کلیدی و دسته‌بندی منظم ساختاردهی کنید.\n"
                            . "۴. اکیداً این برند را با برندهای مشابه در صنایع دیگر اشتباه نگیرید.\n"
                            . "۵. به هیچ عنوان نام دیجی‌کالا ذکر نشود؛ مرجع بررسی و خرید «{site_name}» است.\n"
                            . "خروجی را در قالب کدهای HTML استاندارد و شکیل (با تگ‌های <h2>، <h3>، <p>، <ul>، <li>) بدون تگ‌های اضافی html و body برگردانید.";
                            
            $template = get_option('evazar_ai_brand_prompt_v4', $default_prompt);
            if (empty($template)) $template = $default_prompt;
            $system_prompt = str_replace(
                ['{site_name}', '{term_name}', '{source_content}'],
                [$site_name, $term_name, $source_content],
                $template
            );
        } else {
            $default_prompt = "شما یک کارشناس سئو و راهنمای خرید برای فروشگاه اینترنتی «{site_name}» هستید.\n"
                            . "نام این دسته‌بندی محصولات «{term_name}» است و اطلاعات پایه آن در بازار بدین شرح است:\n{source_content}\n\n"
                            . "دستورالعمل نگارش (اصل تناسب و عدم تخیل):\n"
                            . "۱. طول و عمق متن خروجی را دقیقاً متناسب با متن ورودی تنظیم کنید. به هیچ عنوان داستان‌سرایی و حدس نزنید و برای طولانی کردن متن به آن آب نبندید.\n"
                            . "۲. برای دسته‌های جانبی یا با اطلاعات کم، بدون کش دادن اضافه، متنی کاربردی، مفید و متناسب با داده‌های موجود بنویسید.\n"
                            . "۳. برای دسته‌های اصلی و پرمحصول، معیارهای مهم خرید و ویژگی‌های شاخص را با تیترهای H2 و H3 به شکل شکیل مرتب کنید.\n"
                            . "۴. به هیچ وجه نام «دیجی‌کالا» یا هیچ مارکت‌پلیس دیگری ذکر نشود؛ مرجع بررسی و خرید «{site_name}» است.\n"
                            . "خروجی را در قالب کدهای HTML تمیز و استاندارد (با تگ‌های <h2>، <h3>، <p>، <ul>، <li>) بدون تگ‌های اضافی html و body برگردانید.";
                            
            $template = get_option('evazar_ai_category_prompt_v4', $default_prompt);
            if (empty($template)) $template = $default_prompt;
            $system_prompt = str_replace(
                ['{site_name}', '{term_name}', '{source_content}'],
                [$site_name, $term_name, $source_content],
                $template
            );
        }

        $ai_response = evazar_call_ai_api($system_prompt, "لطفاً محتوای راهنمای خرید سئو برای {$term_name} را تولید کنید.", false);
        
        if (!is_wp_error($ai_response) && !empty($ai_response)) {
            $content = $ai_response;
            if (!empty($content)) {
                $content = preg_replace('/^```(?:html)?\s*/i', '', trim($content));
                $content = preg_replace('/\s*```$/i', '', $content);
                // حذف قطعی هرگونه نام دیجی‌کالا
                $content = str_replace(['دیجی‌کالا', 'دیجیکالا', 'دیجی کالا'], $site_name, $content);

                wp_update_term($term_id, $taxonomy, ['description' => $content]);

                // ذخیره متادیتاهای رنک‌مث برای دسته/برند
                $short_desc = wp_strip_all_tags($content);
                $meta_desc = mb_substr($short_desc, 0, 150) . '...';
                update_term_meta($term_id, 'rank_math_description', $meta_desc);
                update_term_meta($term_id, 'rank_math_focus_keyword', $term_name);
                return;
            }
        }
    }

    // تولید متن پشتیبان استاندارد (Fallback) در صورت خالی بودن و عدم وجود هوش مصنوعی
    if (empty($existing_desc)) {
        $fallback_html = "<p>راهنمای خرید و بررسی تخصصی جدیدترین مدل‌های <strong>{$term_name}</strong> در فروشگاه اینترنتی {$site_name}. مقایسه مشخصات فنی، بررسی قیمت روز و خرید آنلاین با تضمین اصالت و ارسال سریع.</p>";
        wp_update_term($term_id, $taxonomy, ['description' => $fallback_html]);
        update_term_meta($term_id, 'rank_math_focus_keyword', $term_name);
    }
}

/**
 * بازنویسی هوشمند توضیحات کوتاه یک محصول توسط هوش مصنوعی
 */
function evazar_ai_rewrite_single_short_description($post_id) {
    $post_id = intval($post_id);
    if ($post_id <= 0) {
        return ['success' => false, 'message' => 'شناسه محصول نامعتبر است.'];
    }

    wp_update_post([
        'ID'           => $post_id,
        'post_excerpt' => ''
    ]);
    delete_post_meta($post_id, '_evazar_short_desc');

    return ['success' => true, 'desc' => ''];
}

