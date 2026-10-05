/**
 * Cloudflare Worker for Digikala Product & Category Extractor
 * Designed for Evazar Platform & Affilio Marketing
 * 
 * Changelog v1.9.6:
 * - Availability detection hardened: explicit product/variant availability signals have priority; a valid priced variant with a seller is used as fallback when Digikala omits a clear status.
 * - Explicit unavailable flags override all positive signals.
 * Changelog v1.9.5:
 * - Version bump; product cache namespace refreshed after deployment.
 * - Existing secure AI/Queue token protection and image proxy hardening retained.
 * Changelog v1.9.3:
 * - Version bump: synchronized with evazar-core plugin v2.8.50
 * Changelog v1.8.0:
 * - SECURITY: Gemini API Key moved to Worker Environment Secret (env.GEMINI_API_KEY)
 * - Key never appears in URL or request body - invisible to Google rate limiting detection
 * - Each worker instance has its own isolated key secret
 * - Removed key acceptance from client requests to prevent key leakage
 * Changelog v1.7.7:
 * - Deep multi-section extraction of Digikala expert_reviews, review_sections, and sub-sections for full 600+ word AI rewrites
 * Changelog v1.7.4:
 * - Added ?route= parameter for generic safe routing of /v1/categories/ and /v1/brands/ to bypass strict WAF 307 loops
 * Changelog v1.7.3:
 * - Strictly enforce gemini-3.8-flash across all requests (gemini-3.8-pro does not exist in Gemini API)
 * - Enabled GET request handler for model inspection and validation
 * Changelog v1.7.2:
 * - Integrated official active Google Gemini 3.8 Flash (gemini-3.8-flash) replacing retired models
 * Changelog v1.7.1:
 * - Upgraded default Gemini model from deprecated 1.5-flash
 * Changelog v1.7.0:
 * - High-speed AI Reverse Proxy for Google Gemini & OpenAI (Zero Iran Host IP Block & Zero Sanctions Block)
 * Changelog v1.6.4:
 * - Real rating and satisfaction calculation: return 0 when product has no customer reviews
 * Changelog v1.6.3:
 * - Added anti-cache headers for error responses
 * Changelog v1.6.2:
 * - Accurate rating_count extraction with fallback to rates_count for customer vote counts
 * Changelog v1.6.1:
 * - Direct category resolution and CDN image rewrite
 * Changelog v1.5.2:
 * - Clean multi-line specification attributes into single coherent entries
 * Changelog v1.5.1:
 * - Strict filtering of product page self-references from category breadcrumbs
 * Changelog v1.5.0:
 * - High-speed Image Proxy & 1-Year Edge Cache for img.evazar.ir
 * - Seamless forwarding and caching of all Digikala product images
 */

/* Changelog v1.9.9: resilient image fetch retries, status-aware edge caching, and better cache diagnostics.
 * Changelog v1.9.8: cache namespace synchronized with Worker version. */
const DIGIKALA_WORKER_VERSION = '1.9.9';

export default {
    async fetch(request, env, ctx) {
        return handleRequest(request, env, ctx);
    }
};


function hasInternalToken(request, env) {
    const expected = (env && env.EVAZAR_INTERNAL_TOKEN) ? String(env.EVAZAR_INTERNAL_TOKEN).trim() : '';
    if (!expected) return false;
    const received = (request.headers.get('X-Evazar-Internal-Token') || '').trim();
    return received !== '' && received === expected;
}

function requireInternalToken(request, env) {
    const expected = (env && env.EVAZAR_INTERNAL_TOKEN) ? String(env.EVAZAR_INTERNAL_TOKEN).trim() : '';
    if (!expected || !hasInternalToken(request, env)) {
        return new Response(JSON.stringify({ success: false, message: 'Unauthorized.' }), {
            status: 401,
            headers: { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' }
        });
    }
    return null;
}

async function handleRequest(request, env, event) {
    const corsHeaders = {
        'Access-Control-Allow-Origin': '*',
        'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
        'Access-Control-Allow-Headers': 'Content-Type, Authorization, X-Requested-With, X-Evazar-Internal-Token',
        'Content-Type': 'application/json; charset=utf-8'
    };

    if (request.method === 'OPTIONS') {
        return new Response(null, { headers: corsHeaders });
    }

    try {
        const urlObj = new URL(request.url);
        const pathname = urlObj.pathname;
        let dkp = urlObj.searchParams.get('dkp') || urlObj.searchParams.get('id');
        let query = (urlObj.searchParams.get('q') || urlObj.searchParams.get('search') || '').trim().slice(0, 100);
        let page = Number.parseInt(urlObj.searchParams.get('page') || '1', 10);
        if (!Number.isFinite(page)) page = 1;
        page = Math.max(1, Math.min(page, 100));
        let isFresh = urlObj.searchParams.get('fresh') === '1' || urlObj.searchParams.get('nocache') === '1';
        let route = urlObj.searchParams.get('route');

        // Extract DKP from URL if full product URL is provided
        const fullUrl = urlObj.searchParams.get('url');
        if (fullUrl) {
            const match = fullUrl.match(/dkp-(\d+)/i) || fullUrl.match(/product\/(\d+)/i);
            if (match) {
                dkp = match[1];
            }
        }

        // Clean DKP numeric ID
        if (dkp) {
            dkp = dkp.replace(/[^0-9]/g, '');
        }

        // 0. AI Reverse Proxy — فقط ارتباط داخلی Core
        if (pathname.includes('/ai/') || pathname.includes('/gemini') || pathname.includes('/openai') || urlObj.searchParams.get('action') === 'ai_proxy') {
            const authError = requireInternalToken(request, env);
            if (authError) return authError;
            return await handleAiProxy(request, urlObj, corsHeaders, env);
        }

        // 1. Image Proxy & Edge Cache for img.evazar.ir
        const imgParam = urlObj.searchParams.get('img') || urlObj.searchParams.get('image');
        if (pathname.includes('/digikala-') || pathname.match(/\.(jpg|jpeg|png|webp|gif|svg)$/i) || imgParam) {
            return await handleImageProxy(request, urlObj, imgParam, event);
        }

        // 1. Single Product Fetch — عمومی و cacheable؛ داده حساس یا کلید API در پاسخ نیست.
        if (dkp) {
            return await fetchSingleProduct(dkp, corsHeaders, isFresh, event);
        }

        // 2. Search / Category Query Fetch
        if (query) {
            return await fetchSearchResults(query, page, corsHeaders);
        }

        // 3. Generic Safe Routing for Brands and Categories
        if (route) {
            if (route.startsWith('/v1/categories/') || route.startsWith('/v1/brands/')) {
                const targetApiUrl = `https://api.digikala.com${route}`;
                const digiHeaders = {
                    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept': 'application/json, text/plain, */*',
                    'Referer': 'https://www.digikala.com/'
                };
                let response = await fetchDigiWithCookie(targetApiUrl, digiHeaders);
                const proxyHeaders = new Headers(response.headers);
                proxyHeaders.set('Access-Control-Allow-Origin', '*');
                
                return new Response(response.body, {
                    status: response.status,
                    headers: proxyHeaders
                });
            }
        }

        return new Response(JSON.stringify({
            success: false,
            version: DIGIKALA_WORKER_VERSION,
            message: 'لطفاً پارامتر dkp یا q را ارسال کنید.'
        }), { status: 400, headers: corsHeaders });

    } catch (err) {
        return new Response(JSON.stringify({
            success: false,
            version: DIGIKALA_WORKER_VERSION,
            error: err.message
        }), { status: 500, headers: { ...corsHeaders, 'Cache-Control': 'no-cache, no-store, must-revalidate' } });
    }
}

/**
 * Resilient Image Proxy & 1-Year Cloudflare Edge Cache for img.evazar.ir
 */
async function handleImageProxy(request, urlObj, imgParam, event) {
    if (request.method !== 'GET' && request.method !== 'HEAD') {
        return new Response('Method not allowed', { status: 405 });
    }

    let targetUrl = '';
    if (imgParam) {
        targetUrl = imgParam;
    } else {
        const path = urlObj.pathname.startsWith('/') ? urlObj.pathname.substring(1) : urlObj.pathname;
        targetUrl = `https://dkstatics-public.digikala.com/${path}${urlObj.search}`;
    }

    if (!/^https?:\/\//i.test(targetUrl)) {
        targetUrl = `https://${targetUrl}`;
    }

    let parsedTarget;
    try {
        parsedTarget = new URL(targetUrl);
    } catch (_) {
        return new Response('Invalid image URL', { status: 400 });
    }

    const allowedImageHosts = new Set(['dkstatics-public.digikala.com']);
    if (!allowedImageHosts.has(parsedTarget.hostname.toLowerCase())) {
        return new Response('Image host not allowed', { status: 403 });
    }

    // ساخت کلید امن کش بدون تداخل هدرهای کلاینت
    let cacheKey;
    try {
        cacheKey = new Request(targetUrl, { method: 'GET' });
    } catch (_) {
        cacheKey = new Request(targetUrl);
    }

    const cache = caches.default;
    let response = null;
    try {
        response = await cache.match(cacheKey);
    } catch (_) {}

    if (response) {
        const newHeaders = new Headers(response.headers);
        newHeaders.set('Access-Control-Allow-Origin', '*');
        newHeaders.set('X-Evazar-CDN-Cache', 'HIT');
        return new Response(response.body, {
            status: response.status,
            statusText: response.statusText,
            headers: newHeaders
        });
    }

    const fetchHeaders = {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Accept': 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
        'Referer': 'https://www.digikala.com/'
    };

    const imageAttempts = [
        { delay: 0, sendReferer: true },
        { delay: 250, sendReferer: false },
        { delay: 750, sendReferer: true }
    ];

    let upstreamRes = null;
    for (let attempt = 0; attempt < imageAttempts.length; attempt++) {
        const attemptCfg = imageAttempts[attempt];
        if (attemptCfg.delay > 0) {
            await new Promise(resolve => setTimeout(resolve, attemptCfg.delay));
        }

        const attemptHeaders = { ...fetchHeaders };
        if (!attemptCfg.sendReferer) {
            delete attemptHeaders['Referer'];
        }

        try {
            const candidate = await fetch(targetUrl, {
                headers: attemptHeaders,
                cf: {
                    cacheEverything: true,
                    cacheTtlByStatus: {
                        '200-299': 31536000,
                        '300-399': 60,
                        '400-499': 60,
                        '500-599': 0
                    }
                }
            });

            upstreamRes = candidate;

            // فقط خطاهای موقتی را Retry می‌کنیم؛ 404/403 و امثال آن بی‌دلیل تکرار نمی‌شوند.
            if (candidate.ok || ![429, 500, 502, 503, 504, 522, 524].includes(candidate.status)) {
                break;
            }
        } catch (e) {
            upstreamRes = null;
        }
    }

    if (!upstreamRes) {
        return new Response('Error loading image', { status: 502, headers: { 'Cache-Control': 'no-store' } });
    }

    if (!upstreamRes.ok) {
        return new Response('Image not found', {
            status: upstreamRes.status,
            headers: { 'Cache-Control': upstreamRes.status >= 500 ? 'no-store' : 'public, max-age=60' }
        });
    }

    const responseHeaders = new Headers(upstreamRes.headers);
    responseHeaders.set('Access-Control-Allow-Origin', '*');
    responseHeaders.set('Cache-Control', 'public, max-age=31536000, immutable');
    responseHeaders.set('CDN-Cache-Control', 'public, max-age=31536000, stale-if-error=604800');

    const upstreamCacheStatus = (upstreamRes.headers.get('CF-Cache-Status') || '').toUpperCase();
    responseHeaders.set('X-Evazar-CDN-Cache', upstreamCacheStatus === 'HIT' ? 'UPSTREAM-HIT' : 'MISS');
    responseHeaders.set('Vary', 'Accept');
    if (!responseHeaders.get('Content-Type')) {
        responseHeaders.set('Content-Type', 'image/jpeg');
    }
    // حذف قطعی Set-Cookie برای جلوگیری از Cache ناامن/خطای 520
    responseHeaders.delete('Set-Cookie');

    response = new Response(upstreamRes.body, {
        status: upstreamRes.status,
        statusText: upstreamRes.statusText,
        headers: responseHeaders
    });

    // L1 محلی را نگه می‌داریم تا درخواست‌های هم‌زمان در یک Edge سریع پاسخ بگیرند؛
    // subrequest بالا نیز از مسیر fetch/cf cache استفاده می‌کند و با Tiered Cache سازگار است.
    if (event && typeof event.waitUntil === 'function') {
        try {
            const responseToCache = response.clone();
            responseToCache.headers.delete('Set-Cookie');
            event.waitUntil(cache.put(cacheKey, responseToCache).catch(() => {}));
        } catch (_) {}
    }

    return response;
}

/**
 * Safe fetch handler to resolve DigiCDN 307 loop with Set-Cookie forwarding
 */
async function fetchDigiWithCookie(targetUrl, baseHeaders) {
    let response = await fetch(targetUrl, {
        headers: baseHeaders,
        redirect: 'manual'
    });

    if (response.status === 307 || response.status === 302 || response.status === 301) {
        const setCookie = response.headers.get('set-cookie');
        const loc = response.headers.get('location') || targetUrl;
        const nextUrl = loc.startsWith('http') ? loc : new URL(loc, targetUrl).toString();
        const nextHeaders = { ...baseHeaders };
        if (setCookie) {
            nextHeaders['Cookie'] = setCookie.split(';')[0];
        }
        response = await fetch(nextUrl, {
            headers: nextHeaders,
            redirect: 'follow'
        });
    }
    return response;
}

async function fetchSingleProduct(dkpId, corsHeaders, isFresh = false, ctx = null) {
    const targetApiUrl = `https://api.digikala.com/v2/product/${dkpId}/`;

    // fallback cache محلی برای زمانی که Workers Caching در deployment فعال نشده باشد.
    const productCache = caches.default;
    const productCacheKey = new Request(`https://cache.evazar.internal/product/${encodeURIComponent(dkpId)}?v=1.9.8`, { method: 'GET' });
    if (!isFresh) {
        try {
            const cached = await productCache.match(productCacheKey);
            if (cached) {
                const hitHeaders = new Headers(cached.headers);
                hitHeaders.set('X-Evazar-Product-Cache', 'HIT');
                return new Response(cached.body, { status: cached.status, statusText: cached.statusText, headers: hitHeaders });
            }
        } catch (_) {}
    }
    
    const digiHeaders = {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept': 'application/json, text/plain, */*',
        'Referer': 'https://www.digikala.com/'
    };

    let response = await fetchDigiWithCookie(targetApiUrl, digiHeaders);

    if (!response.ok) {
        // Fallback to v1 endpoint
        response = await fetchDigiWithCookie(`https://api.digikala.com/v1/product/${dkpId}/`, digiHeaders);
    }

    if (!response.ok) {
        return new Response(JSON.stringify({
            success: false,
            message: `محصول با شناسه DKP-${dkpId} در دیجی‌کالا یافت نشد (کد ${response.status}).`
        }), { status: 404, headers: { ...corsHeaders, 'Cache-Control': 'no-cache, no-store, must-revalidate' } });
    }

    const raw = await response.json();
    const p = raw?.data?.product;

    if (!p) {
        return new Response(JSON.stringify({
            success: false,
            message: 'ساختار داده دریافتی از سرور دیجی‌کالا نامعتبر است.'
        }), { status: 500, headers: { ...corsHeaders, 'Cache-Control': 'no-cache, no-store, must-revalidate' } });
    }

    // Extract Gallery Images (Zero Host Storage)
    const gallery = [];
    const mainImg = p.images?.main?.url?.[0] || '';
    if (mainImg) gallery.push(mainImg);
    if (Array.isArray(p.images?.list)) {
        for (const img of p.images.list) {
            const u = img?.url?.[0];
            if (u && !gallery.includes(u)) {
                gallery.push(u);
            }
        }
    }

    // Extract Specifications
    const specs = [];
    if (Array.isArray(p.specifications)) {
        for (const sg of p.specifications) {
            if (Array.isArray(sg.attributes)) {
                for (const attr of sg.attributes) {
                    if (attr.title && Array.isArray(attr.values) && attr.values.length > 0) {
                        const rawVal = attr.values.join('، ');
                        const cleanVal = rawVal.replace(/[\r\n]+/g, ' ').replace(/\s*\/\s*/g, ' / ').replace(/\s+/g, ' ').replace(/\s*[\/،,-]\s*$/g, '').trim();
                        if (cleanVal) {
                            specs.push({
                                title: attr.title.trim(),
                                value: cleanVal
                            });
                        }
                    }
                }
            }
        }
    }

    // Extract Breadcrumbs (Parent Categories)
    const breadcrumbs = [];
    const rawBreadcrumbs = raw?.data?.breadcrumb 
                        || raw?.data?.breadcrumbs 
                        || raw?.data?.seo?.markup?.breadcrumb 
                        || p?.breadcrumb 
                        || p?.breadcrumb_list 
                        || [];

    const productTitleFa = (p.title_fa || '').trim();

    if (Array.isArray(rawBreadcrumbs)) {
        for (const bc of rawBreadcrumbs) {
            const title = (bc?.title || bc?.title_fa || bc?.name || '').trim();
            if (title && title !== 'دیجی‌کالا' && !title.toLowerCase().startsWith('dkp-')) {
                const uri = bc?.url?.uri || (typeof bc?.url === 'string' ? bc.url : '') || '';
                // فیلتر کردن خود محصول از لیست دسته‌بندی‌ها
                if (uri.includes('/product/') || (productTitleFa && title === productTitleFa)) {
                    continue;
                }
                breadcrumbs.push({
                    title: title,
                    url: uri
                });
            }
        }
    }

    // Direct Category Extraction (Fallback if breadcrumbs missing or empty)
    const catTitle = (p?.category?.title_fa || p?.category?.title || raw?.data?.category?.title_fa || '').trim();
    if (breadcrumbs.length === 0 && catTitle) {
        breadcrumbs.push({
            title: catTitle,
            url: ''
        });
    }

    const finalCategory = catTitle || (breadcrumbs.length > 0 ? breadcrumbs[breadcrumbs.length - 1].title : '');

    // Price & BuyBox Winning Seller parsing (Digikala Marketplace Logic)
    const defaultVariant = p.default_variant;
    const sellingPriceRials = defaultVariant?.price?.selling_price || 0;
    const rrpPriceRials = defaultVariant?.price?.rrp_price || sellingPriceRials;
    // موجودی: ابتدا سیگنال‌های صریح واریانت/محصول را بررسی می‌کنیم. اگر API
    // وضعیت صریحی ندهد، ترکیب واریانت معتبر + قیمت فروش + فروشنده را fallback
    // می‌گیریم تا false-negative ایجاد نشود. سیگنال صریح ناموجود همیشه اولویت دارد.
    const falseTokens = ['unavailable','out_of_stock','out-of-stock','outofstock','sold_out','sold-out','not_available','not-available','inactive','disabled','ناموجود','اتمام موجودی','تمام شد','موجود نیست','قابل خرید نیست'];
    const trueTokens = ['marketable','available','in_stock','in-stock','instock','purchasable','buyable','موجود','قابل خرید','قابل‌خرید'];
    const boolKeys = ['is_available','available','in_stock','is_in_stock','is_marketable','marketable','is_purchasable','purchasable','buyable','is_buyable'];
    const textKeys = ['status','availability','availability_status','stock_status','availability_text','status_text','stock_text','purchase_status'];
    function scanAvailability(obj) {
        if (!obj || typeof obj !== 'object') return {pos:false, neg:false};
        let pos=false, neg=false;
        for (const key of boolKeys) {
            if (!(key in obj)) continue;
            const v=obj[key];
            if (v===true || v===1 || v==='1' || String(v).toLowerCase()==='true' || String(v).toLowerCase()==='yes') pos=true;
            if (v===false || v===0 || v==='0' || String(v).toLowerCase()==='false' || String(v).toLowerCase()==='no') neg=true;
        }
        for (const key of textKeys) {
            if (obj[key] == null) continue;
            const v=String(obj[key]).trim().toLowerCase();
            if (!v) continue;
            if (falseTokens.some(t=>v.includes(t))) neg=true;
            if (v==='marketable' || trueTokens.some(t=>v.includes(t))) pos=true;
        }
        return {pos,neg};
    }
    const productAvail = scanAvailability(p);
    const variantAvail = scanAvailability(defaultVariant);
    const availabilitySource = (productAvail.neg || variantAvail.neg) ? 'explicit_unavailable' : ((productAvail.pos || variantAvail.pos) ? 'explicit_available' : 'fallback');
    const isAvailable = availabilitySource === 'explicit_unavailable'
        ? false
        : (availabilitySource === 'explicit_available'
            ? true
            : Boolean(defaultVariant) && sellingPriceRials > 0 && Boolean(defaultVariant?.seller));
    const sellerTitle = defaultVariant?.seller?.title || 'دیجی‌کالا';
    const sellerCode = defaultVariant?.seller?.code || '';
    const sellerRating = defaultVariant?.seller?.stars || defaultVariant?.seller?.rating || null;
    const warranty = defaultVariant?.warranty?.title_fa || '';
    const leadTime = defaultVariant?.shipment_methods?.description || '';

    // Rating & Satisfaction Logic (Zero-review honest handling)
    const rawRatingCount = (typeof p.rating?.count !== 'undefined' && p.rating.count !== null) ? Number(p.rating.count) : (Number(p.rating?.rates_count) || 0);
    let rating = 0;
    let satisfaction = 0;
    let rating_count = rawRatingCount;

    if (rating_count > 0 && p.rating?.rate) {
        rating = Number(p.rating.rate);
        if (rating > 5) {
            satisfaction = Math.floor(rating);
            rating = Number((rating / 20).toFixed(1));
        } else {
            satisfaction = Math.floor((rating / 5) * 100);
        }
        
        // Use true satisfaction if count_buyers exists
        if (p.rating?.count_buyers && p.rating?.count && p.rating.count > 0) {
            const calculated = Math.round((p.rating.count_buyers / p.rating.count) * 100);
            satisfaction = calculated > 100 ? 100 : (calculated < 0 ? 0 : calculated);
        }
    }

    // استخراج جامع و عمیق تمام بخش‌های معرفی و بررسی تخصصی دیجی‌کالا
    const descParts = [];

    // ۱. متن معرفی اولیه (review.description)
    const introDesc = (p.review?.description || '').trim();
    if (introDesc) {
        descParts.push(introDesc);
    }

    // ۲. بررسی تخصصی کامل (expert_reviews شامل تمام review_sections و زیربخش‌های متنی)
    if (p.expert_reviews) {
        if (typeof p.expert_reviews === 'string' && p.expert_reviews.trim()) {
            if (!descParts.includes(p.expert_reviews.trim())) {
                descParts.push(p.expert_reviews.trim());
            }
        } else if (typeof p.expert_reviews === 'object') {
            const rawSections = p.expert_reviews.review_sections || p.review?.review_sections || [];
            if (Array.isArray(rawSections)) {
                for (const sec of rawSections) {
                    const secTitle = (sec?.title || '').trim();
                    const subTexts = [];
                    if (Array.isArray(sec?.sections)) {
                        for (const sub of sec.sections) {
                            const txt = (sub?.text || sub?.template || '').replace(/&zwnj;/g, '‌').replace(/<[^>]+>/g, '').trim();
                            if (txt && !txt.startsWith('http')) subTexts.push(txt);
                        }
                    } else if (sec?.text) {
                        const txt = (sec.text || '').replace(/&zwnj;/g, '‌').replace(/<[^>]+>/g, '').trim();
                        if (txt && !txt.startsWith('http')) subTexts.push(txt);
                    }
                    if (subTexts.length > 0) {
                        const block = (secTitle ? secTitle + ":\n" : "") + subTexts.join("\n");
                        if (!descParts.some(prev => prev.includes(subTexts[0]))) {
                            descParts.push(block);
                        }
                    }
                }
            }

            if (descParts.length === 0 && typeof p.expert_reviews.description === 'string' && p.expert_reviews.description.trim()) {
                descParts.push(p.expert_reviews.description.trim());
            }
        }
    }

    // ۳. فال‌بک content در صورت خالی بودن
    if (descParts.length === 0 && p.content) {
        const cDesc = typeof p.content === 'string' ? p.content : (p.content?.description || '');
        if (cDesc.trim()) descParts.push(cDesc.trim());
    }

    const prodDesc = descParts.join("\n\n");

    const cleanResult = {
        success: true,
        version: DIGIKALA_WORKER_VERSION,
        product: {
            id: p.id,
            title_fa: p.title_fa || '',
            title_en: p.title_en || '',
            url: `https://www.digikala.com/product/dkp-${p.id}/`,
            is_available: isAvailable,
            availability_source: availabilitySource,
            status_text: isAvailable ? 'موجود' : 'ناموجود',
            price_toman: Math.floor(sellingPriceRials / 10),
            regular_price_toman: Math.floor(rrpPriceRials / 10),
            discount_percent: defaultVariant?.price?.discount_percent || 0,
            seller: sellerTitle,
            seller_code: sellerCode,
            seller_rating: sellerRating,
            warranty: warranty,
            lead_time: leadTime,
            image: mainImg,
            gallery: gallery.slice(0, 10),
            description: prodDesc,
            specs: specs,
            breadcrumbs: breadcrumbs,
            category: finalCategory,
            category_id: p.category?.id || null,
            rating: rating,
            satisfaction: satisfaction,
            rating_count: rating_count,
            brand: p.brand?.title_fa || 'دیجی‌کالا',
            brand_en: p.brand?.title_en || ''
        }
    };

    const cacheHeader = isFresh
        ? 'no-cache, no-store, must-revalidate'
        : 'public, max-age=120'; // 2 minutes; price/availability freshness

    const resultResponse = new Response(JSON.stringify(cleanResult), {
        headers: {
            ...corsHeaders,
            'Cache-Control': cacheHeader,
            'X-Evazar-Product-Cache': 'MISS'
        }
    });

    if (!isFresh) {
        try {
            const cacheCopy = resultResponse.clone();
            if (ctx && typeof ctx.waitUntil === 'function') {
                ctx.waitUntil(productCache.put(productCacheKey, cacheCopy));
            } else {
                await productCache.put(productCacheKey, cacheCopy);
            }
        } catch (_) {}
    }

    return resultResponse;
}

async function fetchSearchResults(query, page, corsHeaders) {
    const encoded = encodeURIComponent(query);
    const targetApiUrl = `https://api.digikala.com/v1/search/?q=${encoded}&page=${page}`;

    const digiHeaders = {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'Accept': 'application/json, text/plain, */*',
        'Referer': 'https://www.digikala.com/'
    };

    const response = await fetchDigiWithCookie(targetApiUrl, digiHeaders);
    if (!response.ok) {
        return new Response(JSON.stringify({ success: false, message: 'خطا در دریافت نتایج جستجو.' }), { status: 500, headers: { ...corsHeaders, 'Cache-Control': 'no-cache, no-store, must-revalidate' } });
    }

    const data = await response.json();
    const products = (data?.data?.products || []).slice(0, 20).map(p => {
        const sellingPrice = p.default_variant?.price?.selling_price || 0;
        const rrpPrice = p.default_variant?.price?.rrp_price || sellingPrice;
        return {
            id: p.id,
            title: p.title_fa,
            price: Math.floor(sellingPrice / 10),
            rrp_price: Math.floor(rrpPrice / 10),
            image: p.images?.main?.url?.[0] || '',
            rating: (p.rating?.count > 0 && p.rating?.rate) ? Number(p.rating.rate) : 0,
            url: `https://www.digikala.com/product/dkp-${p.id}/`
        };
    });

    return new Response(JSON.stringify({
        success: true,
        version: DIGIKALA_WORKER_VERSION,
        count: products.length,
        products: products
    }), { headers: { ...corsHeaders, 'Cache-Control': 'public, max-age=60' } });
}

/**
 * AI Reverse Proxy — Multi-Key Secure Mode
 * سه کلید داخل همین ورکر: GEMINI_API_KEY_1 / _2 / _3
 * چرخش خودکار هر دقیقه بین کلیدها
 */
/* Duplicate AI proxy implementation removed in 1.9.4; single secured handler follows. */

async function handleAiProxy(request, urlObj, corsHeaders, env) {
    const pathname = urlObj.pathname;
    const isGemini = pathname.includes('gemini') || urlObj.searchParams.get('provider') === 'gemini';
    const isOpenAI = pathname.includes('openai') || urlObj.searchParams.get('provider') === 'openai';

    // GET: بازگرداندن اطلاعات ورکر (بدون نمایش کلید)
    if (request.method === 'GET') {
        return new Response(JSON.stringify({
            worker: 'evazar-ai-proxy',
            version: DIGIKALA_WORKER_VERSION,
            has_gemini_key: !!(env && env.GEMINI_API_KEY),
            has_openai_key: !!(env && env.OPENAI_API_KEY),
            mode: 'secure-env-secret'
        }), {
            status: 200,
            headers: { ...corsHeaders, 'Content-Type': 'application/json; charset=utf-8' }
        });
    }

    if (request.method !== 'POST') {
        return new Response(JSON.stringify({ error: 'POST method required for AI proxy' }), { status: 405, headers: corsHeaders });
    }

    let targetUrl = '';
    let authHeaders = { 'Content-Type': 'application/json' };

    if (isGemini || (!isOpenAI)) {
        // ========================================================
        // GEMINI: کلید از Environment Secret — نه از URL یا body
        // ========================================================
        const geminiKey = (env && env.GEMINI_API_KEY) ? env.GEMINI_API_KEY.trim() : '';
        if (!geminiKey) {
            return new Response(JSON.stringify({
                error: 'GEMINI_API_KEY not configured in Worker environment secrets.'
            }), { status: 503, headers: corsHeaders });
        }

        // استخراج مدل از pathname (مثلاً /ai/gemini/gemini-2.0-flash)
        let modelAndMethod = pathname.replace(/^.*\/(?:ai\/)?gemini\/?/i, '').replace(/^models\//, '');
        if (!modelAndMethod || modelAndMethod === 'gemini') {
            modelAndMethod = 'gemini-2.0-flash';
        }
        // حذف :generateContent اگر وجود داشت تا دوبار اضافه نشود
        modelAndMethod = modelAndMethod.replace(/:generateContent$/, '');

        // کلید فقط روی سرور اضافه می‌شود — هرگز از client نمی‌آید
        targetUrl = `https://generativelanguage.googleapis.com/v1beta/models/${modelAndMethod}:generateContent?key=${geminiKey}`;

    } else {
        // ========================================================
        // OPENAI: کلید از Environment Secret
        // ========================================================
        const openaiKey = (env && env.OPENAI_API_KEY) ? env.OPENAI_API_KEY.trim() : '';
        if (!openaiKey) {
            return new Response(JSON.stringify({
                error: 'OPENAI_API_KEY not configured in Worker environment secrets.'
            }), { status: 503, headers: corsHeaders });
        }
        targetUrl = 'https://api.openai.com/v1/chat/completions';
        authHeaders['Authorization'] = `Bearer ${openaiKey}`;
    }

    try {
        // دریافت body از client — اما هیچ keyای از آن استخراج نمی‌شود
        let bodyText = await request.text();

        // اطمینان از اینکه client کلیدی embed نکرده (لایه امنیتی اضافه)
        try {
            const bodyObj = JSON.parse(bodyText);
            // حذف هرگونه key که client ممکن است ارسال کرده باشد
            delete bodyObj._key;
            delete bodyObj._api_key;
            delete bodyObj.key;
            bodyText = JSON.stringify(bodyObj);
        } catch (_) {}

        const upstreamRes = await fetch(targetUrl, {
            method: 'POST',
            headers: authHeaders,
            body: bodyText
        });

        const upstreamBody = await upstreamRes.text();
        return new Response(upstreamBody, {
            status: upstreamRes.status,
            headers: {
                ...corsHeaders,
                'Content-Type': 'application/json; charset=utf-8'
            }
        });
    } catch (e) {
        return new Response(JSON.stringify({ error: 'Worker AI Proxy Failed: ' + e.message }), { status: 502, headers: corsHeaders });
    }
}
