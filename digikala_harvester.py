import os
import re
import sys
import time
import json
import requests
import argparse
import urllib.parse
from concurrent.futures import ThreadPoolExecutor, as_completed

if hasattr(sys.stdout, 'reconfigure'):
    try:
        sys.stdout.reconfigure(encoding='utf-8')
    except Exception:
        pass

# =========================================================
# Evazar Industrial Harvester - Digikala to WP Queue
# Version: 1.3.1 (Authenticated Queue Ingestion & Fail-Fast Error Handling)
# Author: Moblak / Antigravity
# =========================================================

DEFAULT_WP_API = "https://evazar.ir/wp-json/evazar/v1/queue-ingest"
DEFAULT_MIN_PRICE = 50000  # Default minimum price
BATCH_SIZE = 50            # Items per WP request
MAX_PRICE_RIALS_BOUND = 50000000000 # 5 Billion Tomans as upper bound for slicing

HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
    "Accept": "application/json, text/plain, */*",
    "Referer": "https://www.digikala.com/"
}

SESSION = requests.Session()
_adapter = requests.adapters.HTTPAdapter(pool_connections=30, pool_maxsize=30, max_retries=2)
SESSION.mount("https://", _adapter)
SESSION.mount("http://", _adapter)
SESSION.headers.update(HEADERS)

# Internal EVazar queue authentication.
# Never hard-code this secret in source code or repository.
INTERNAL_TOKEN_ENV = "EVAZAR_INTERNAL_TOKEN"

def configure_evazar_auth():
    token = os.environ.get(INTERNAL_TOKEN_ENV, "").strip()
    if not token:
        print(f"[-] Authentication Error: environment variable {INTERNAL_TOKEN_ENV} is not set.")
        print("[!] Set the same internal token configured in EVazar before running the harvester.")
        return False
    SESSION.headers.update({"X-Evazar-Internal-Token": token})
    return True

EXISTING_DKPS = set()

def fetch_existing_dkps(wp_base_url):
    endpoint = wp_base_url.replace('/queue-ingest', '/existing-dkps')
    try:
        print(f"[*] Pre-flight Check: Fetching existing DKPs from {endpoint} ...")
        resp = SESSION.get(endpoint, timeout=30)

        if resp.status_code == 200:
            data = resp.json()
            if data.get('success'):
                dkps = data.get('dkps', [])
                EXISTING_DKPS.update(str(d) for d in dkps)
                print(f"[+] Loaded {len(EXISTING_DKPS)} existing DKPs. They will be skipped automatically.")
                return True
            print("[-] Pre-flight Error: WordPress returned an unsuccessful response.")
            return False

        if resp.status_code in (401, 403):
            print(f"[-] Authentication Error: existing-dkps returned HTTP {resp.status_code}.")
            print("[!] Check that EVAZAR_INTERNAL_TOKEN matches the token configured in EVazar.")
            return False

        print(f"[-] Pre-flight Error: Endpoint returned HTTP {resp.status_code}")
        return False
    except Exception as e:
        print(f"[-] Pre-flight Error: Failed to fetch existing DKPs: {e}")
        return False

def parse_digikala_url(url, only_available=False):
    url = url.strip()
    if not url.startswith("http://") and not url.startswith("https://"):
        url = "https://" + url
    parsed = urllib.parse.urlparse(url)
    path = parsed.path.strip('/')
    query_params = urllib.parse.parse_qs(parsed.query)
    
    if only_available:
        query_params['has_selling_stock'] = ['1']
        
    encoded_query = urllib.parse.urlencode(query_params, doseq=True)
    query_str = f"?{encoded_query}" if encoded_query else ""
    
    parts = path.split('/')
    if len(parts) >= 2 and parts[0] == 'search' and parts[1].startswith('category-'):
        cat = parts[1].replace('category-', '')
        if len(parts) >= 3 and parts[2]:
            brand = parts[2]
            return f"https://api.digikala.com/v1/categories/{cat}/brands/{brand}/search/{query_str}"
        return f"https://api.digikala.com/v1/categories/{cat}/search/{query_str}"
    
    elif '/brand/' in parsed.path:
        brand = parsed.path.split('/brand/')[1].replace('/', '')
        return f"https://api.digikala.com/v1/brands/{brand}/search/{query_str}"
        
    elif 'q=' in parsed.query or 'q' in query_params:
        return f"https://api.digikala.com/v1/search/{query_str}"
        
    else:
        encoded_seo = urllib.parse.quote(parsed.path)
        if 'seo_url' not in query_params:
            query_params['seo_url'] = [encoded_seo]
            query_str = f"?{urllib.parse.urlencode(query_params, doseq=True)}"
        return f"https://api.digikala.com/v1/search/{query_str}"

def fetch_page(api_url, page=1):
    url = f"{api_url}?page={page}" if "?" not in api_url else f"{api_url}&page={page}"
    for attempt in range(3):
        try:
            resp = SESSION.get(url, timeout=15)
            if resp.status_code == 200:
                return resp.json()
            elif resp.status_code == 429:
                time.sleep(1.5 * (attempt + 1))
        except Exception as e:
            if attempt == 2:
                print(f"[-] Network Error on page {page}: {e}")
            time.sleep(0.5)
    return None

def extract_items(data, min_price):
    if not data or 'data' not in data or 'products' not in data['data']:
        return []
    
    products = data['data']['products']
    valid_items = []
    
    for p in products:
        if p.get('status') != 'marketable':
            continue
            
        selling_price = p.get('default_variant', {}).get('price', {}).get('selling_price', 0)
        price_toman = selling_price / 10
        
        if price_toman < min_price:
            continue
            
        dkp = str(p.get('id'))
        
        # Pre-flight Deduplication check
        if dkp in EXISTING_DKPS:
            continue
            
        url = f"https://www.digikala.com/product/dkp-{dkp}/"
        
        valid_items.append({
            "dkp": dkp,
            "url": url,
            "title": p.get('title_fa', '')
        })
        
    return valid_items

def process_page(api_url, page, min_price):
    data = fetch_page(api_url, page)
    return extract_items(data, min_price)

def build_api_url_with_price(base_url, p_min, p_max):
    parsed = urllib.parse.urlparse(base_url)
    query_params = urllib.parse.parse_qs(parsed.query)
    
    if p_min is not None or p_max is not None:
        actual_min = p_min if p_min is not None else 0
        actual_max = p_max if p_max is not None else MAX_PRICE_RIALS_BOUND
        query_params['price[min]'] = [str(int(actual_min))]
        query_params['price[max]'] = [str(int(actual_max))]
        
    encoded_query = urllib.parse.urlencode(query_params, doseq=True)
    return urllib.parse.urlunparse((parsed.scheme, parsed.netloc, parsed.path, parsed.params, encoded_query, parsed.fragment))

def recursive_scan(base_url, p_min, p_max, all_extracted_items, min_price_toman, threads, depth=1):
    url = build_api_url_with_price(base_url, p_min, p_max)
    data = fetch_page(url, 1)
    if not data:
        print(f"[-] Failed to fetch data for range {p_min} to {p_max}")
        return
        
    pager = data.get('data', {}).get('pager', {})
    total_items = pager.get('total_items', 0)
    total_pages = pager.get('total_pages', 1)
    
    p_min_display = p_min if p_min is not None else (min_price_toman * 10)
    p_max_display = p_max if p_max is not None else "MAX"
    
    prefix = "  " * depth
    print(f"{prefix}[*] Range {p_min_display} - {p_max_display} Rials -> Found {total_items} items.")
    
    if total_items > 2000:
        current_max = p_max if p_max is not None else MAX_PRICE_RIALS_BOUND
        current_min = p_min if p_min is not None else (min_price_toman * 10 if min_price_toman else 0)
        
        if current_max - current_min <= 10000:
            print(f"{prefix}[!] Price range too narrow to split further. Capping at 2000 items.")
        else:
            mid = (current_min + current_max) // 2
            print(f"{prefix}[*] Smart Slicing -> Splitting into: {current_min}-{mid} and {mid+1}-{current_max}")
            recursive_scan(base_url, current_min, mid, all_extracted_items, min_price_toman, threads, depth + 1)
            recursive_scan(base_url, mid + 1, current_max, all_extracted_items, min_price_toman, threads, depth + 1)
            return

    max_scan_pages = min(total_pages, 100)
    
    if total_items > 0:
        page_1_items = extract_items(data, min_price_toman)
        all_extracted_items.extend(page_1_items)
        if page_1_items:
            print(f"{prefix}[>] Page 1: Extracted {len(page_1_items)} new valid products.")
        
        if max_scan_pages > 1:
            with ThreadPoolExecutor(max_workers=threads) as executor:
                futures = {executor.submit(process_page, url, page, min_price_toman): page for page in range(2, max_scan_pages + 1)}
                for future in as_completed(futures):
                    page_num = futures[future]
                    try:
                        items = future.result()
                        all_extracted_items.extend(items)
                        if items:
                            print(f"{prefix}[>] Page {page_num}: Extracted {len(items)} new valid products.")
                    except Exception as exc:
                        print(f"{prefix}[-] Error on page {page_num}: {exc}")

def push_to_wordpress(items, wp_api_url):
    try:
        payload = {"items": items}
        resp = SESSION.post(wp_api_url, json=payload, timeout=20)

        if resp.status_code == 200:
            res_data = resp.json()
            print(f"[+] Successfully Sent | Inserted: {res_data.get('inserted')} | Skipped: {res_data.get('skipped')}")
            return True

        if resp.status_code in (401, 403):
            print(f"[-] Authentication Error: WordPress queue returned HTTP {resp.status_code}.")
            print("[!] Check that EVAZAR_INTERNAL_TOKEN matches the token configured in EVazar.")
            return False

        print(f"[-] WP Error: Code {resp.status_code} - {resp.text}")
    except Exception as e:
        print(f"[-] Connection to WP Failed: {e}")
    return False

def main():
    print("=====================================================")
    print(" [*] Evazar Industrial Harvester - Digikala to WP Queue")
    print(" [*] Deep Scan & Smart Price Slicing Enabled")
    print("=====================================================\n")
    
    parser = argparse.ArgumentParser(description="Digikala Scraper & WP Injector")
    parser.add_argument("--url", help="Digikala URL (Category/Brand/Search)", required=True)
    parser.add_argument("--wp", help="Your WP Endpoint URL", default=DEFAULT_WP_API)
    parser.add_argument("--min_price", help="Minimum price in Toman", type=int, default=DEFAULT_MIN_PRICE)
    parser.add_argument("--threads", help="Number of threads", type=int, default=3)
    parser.add_argument("--only_available", action="store_true", help="Only fetch in-stock available products")
    
    args = parser.parse_args()
    
    if not configure_evazar_auth():
        sys.exit(2)

    api_base_url = parse_digikala_url(args.url, only_available=args.only_available)
    print(f"[*] Extracted API: {api_base_url}")
    print(f"[*] WP Endpoint: {args.wp}")
    print(f"[*] Min Price: {args.min_price} Toman")
    print(f"[*] Only Available: {'Yes (Filtered)' if 'has_selling_stock=1' in api_base_url else 'No (All Products)'}")
    print("-" * 50)
    
    # Pre-flight duplicate prevention
    if not fetch_existing_dkps(args.wp):
        print("[-] Harvester stopped before scanning because WordPress authentication failed.")
        sys.exit(3)
    print("-" * 50)
    
    all_valid_items = []
    initial_p_min = args.min_price * 10 if args.min_price else 0
    
    print("\n[*] Starting Deep Scan with Smart Price Slicing...")
    recursive_scan(api_base_url, initial_p_min, None, all_valid_items, args.min_price, args.threads)
    
    # Deduplicate locally (just in case overlapping ranges returned same product)
    unique_items = {}
    for item in all_valid_items:
        unique_items[item['dkp']] = item
    all_valid_items = list(unique_items.values())
    
    print("-" * 50)
    print(f"[+] Total new unique items extracted: {len(all_valid_items)} (Skipping already imported items)")
    
    if len(all_valid_items) == 0:
        print("[-] No new items to push. Operation finished.")
        sys.exit(0)
        
    print(f"\n[*] Starting Data Injection in batches of {BATCH_SIZE}...")
    
    for i in range(0, len(all_valid_items), BATCH_SIZE):
        batch = all_valid_items[i:i + BATCH_SIZE]
        print(f"[*] Sending Package {i//BATCH_SIZE + 1} ({len(batch)} items)...")
        if not push_to_wordpress(batch, args.wp):
            print(f"[-] Harvester stopped: Package {i//BATCH_SIZE + 1} was not accepted by WordPress.")
            print("[!] No further batches will be sent.")
            sys.exit(4)
        time.sleep(1)

    print("\n[+] Operation finished successfully! All batches were accepted by WordPress. Background Cron will start processing them.")

if __name__ == "__main__":
    main()
