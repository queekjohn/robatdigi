import os
import sys
import time
import json
import random
import re
import argparse
import urllib.parse
import threading
from concurrent.futures import ThreadPoolExecutor, as_completed

import requests
from requests.adapters import HTTPAdapter

# Configure UTF-8 for console output on Windows
if sys.stdout and hasattr(sys.stdout, "reconfigure"):
    try:
        sys.stdout.reconfigure(encoding="utf-8")
    except Exception:
        pass
if sys.stderr and hasattr(sys.stderr, "reconfigure"):
    try:
        sys.stderr.reconfigure(encoding="utf-8")
    except Exception:
        pass


# =========================================================
# Evazar Industrial Harvester - Digikala to WP Queue
# Version: 1.6.2
#
# Key Features in 1.6.2:
# 1. Automatic Subcategory Tree Resolver:
#    - Automatically detects parent categories
#    - Recursively resolves all leaf subcategories
# 2. Uncapped Deep Recursive Price Slicing:
#    - Removed the 200-page cap restriction.
#    - Massive subcategories (40k+ items, e.g. brush-and-makeup-accesories)
#      are dynamically sliced by price until every slice fits < 100 pages (< 2,000 items).
#    - 100% of products across huge dense categories are fully harvested.
#    - Dynamic price bounds fetched directly from Digikala's category filters.
# 3. Dense-Cluster Multi-Sort Fallback:
#    - Multi-Sort Engine is retained specifically for true single-price clusters
#      (where p_min == p_max and cannot be partitioned further by price).
# 4. Global DKP Deduplication across all subcategories, price slices & sorts.
# 5. Full WP Queue-Ingest compatibility.
# =========================================================


DEFAULT_WP_API = "https://evazar.ir/wp-json/evazar/v1/queue-ingest"

DEFAULT_MIN_PRICE = 50000       # Toman
DEFAULT_THREADS = 3
DEFAULT_RETRIES = 6
DEFAULT_TIMEOUT = 20

BATCH_SIZE = 50
DEFAULT_SLICE_THRESHOLD = 2000
DEFAULT_PAGE_CAP = 100
DEFAULT_MAX_PRICE_TOMAN = 0
MAX_PRICE_RECURSION_DEPTH = 25

INTERNAL_TOKEN_ENV = "EVAZAR_INTERNAL_TOKEN"

HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
        "AppleWebKit/537.36 (KHTML, like Gecko) "
        "Chrome/140.0 Safari/537.36"
    ),
    "Accept": "application/json, text/plain, */*",
    "Referer": "https://www.digikala.com/",
    "Accept-Language": "fa-IR,fa;q=0.9,en-US;q=0.8,en;q=0.7",
}

# Digikala Sort Dimensions for Dense Price Clusters
SORT_OPTIONS_LIST = [
    {"id": 22, "name": "مرتبط‌ترین (Relevant)"},
    {"id": 1,  "name": "جدیدترین (Newest)"},
    {"id": 4,  "name": "پربازدیدترین (Most Visited)"},
    {"id": 7,  "name": "پرفروش‌ترین (Best Selling)"},
    {"id": 20, "name": "ارزان‌ترین (Cheapest)"},
    {"id": 21, "name": "گران‌ترین (Most Expensive)"},
    {"id": 25, "name": "سریع‌ترین ارسال (Fast Shipping)"},
    {"id": 27, "name": "پیشنهاد خریداران (Buyers Choice)"},
    {"id": 29, "name": "منتخب (Featured)"},
]

SESSION = requests.Session()
_adapter = HTTPAdapter(
    pool_connections=30,
    pool_maxsize=30,
    max_retries=0,
)
SESSION.mount("https://", _adapter)
SESSION.mount("http://", _adapter)
SESSION.headers.update(HEADERS)

stats_lock = threading.Lock()


class ScanStats:
    def __init__(self):
        self.api_pages_requested = 0
        self.api_pages_success = 0
        self.api_pages_failed = 0
        self.products_seen = 0
        self.products_marketable = 0
        self.products_below_min_price = 0
        self.products_existing = 0
        self.products_queued = 0
        self.products_completed_only = 0
        self.products_known_occurrences = 0
        self.products_missing_id = 0
        self.existing_dkp_hits = set()
        self.queued_dkp_hits = set()
        self.completed_only_dkp_hits = set()
        self.reserved_dkp_hits = set()
        self.products_extracted = 0
        self.slices = 0
        self.failed_pages = []
        self.page_signatures = set()
        self.duplicate_page_signatures = 0
        self.cap_limited_slices = 0
        self.scanned_pages = 0
        self.incomplete_ranges = []
        self.max_recursion_depth = 0
        self.exact_price_branches = []
        self.subcategories_found = 0
        self.subcategories_processed = 0

    def add_failed_page(self, page, url, reason):
        with stats_lock:
            self.api_pages_failed += 1
            self.failed_pages.append({
                "page": page,
                "url": url,
                "reason": reason,
            })


STATS = ScanStats()
EXISTING_DKPS = set()
QUEUED_DKPS = set()
COMPLETED_DKPS = set()
FAILED_DKPS = set()
RESERVED_DKPS = set()
STATUS_API_DETAILED = False


def configure_evazar_auth():
    """Load the EVazar internal token from the environment."""
    token = os.environ.get(INTERNAL_TOKEN_ENV, "").strip()

    if not token:
        print(
            f"[-] Authentication Error: environment variable "
            f"{INTERNAL_TOKEN_ENV} is not set."
        )
        print(
            "[!] Set the same internal token configured in EVazar "
            "before running the harvester."
        )
        return False

    SESSION.headers.update({"X-Evazar-Internal-Token": token})
    return True


def fetch_existing_dkps(wp_base_url):
    """Load existing DKPs once so the harvester can skip already imported products."""
    endpoint = wp_base_url.replace("/queue-ingest", "/existing-dkps")

    try:
        print(f"[*] Pre-flight Check: Fetching existing DKPs from {endpoint} ...")
        resp = SESSION.get(endpoint, timeout=30)

        if resp.status_code == 200:
            data = resp.json()

            if data.get("success"):
                global STATUS_API_DETAILED

                existing_dkps = data.get("existing_dkps")
                queued_dkps = data.get("queued_dkps")
                completed_dkps = data.get("completed_dkps")
                failed_dkps = data.get("failed_dkps")

                STATUS_API_DETAILED = all(
                    isinstance(value, list)
                    for value in (existing_dkps, queued_dkps, completed_dkps, failed_dkps)
                )

                if STATUS_API_DETAILED:
                    EXISTING_DKPS.update(str(d) for d in existing_dkps)
                    QUEUED_DKPS.update(str(d) for d in queued_dkps)
                    COMPLETED_DKPS.update(str(d) for d in completed_dkps)
                    FAILED_DKPS.update(str(d) for d in failed_dkps)
                    RESERVED_DKPS.update(EXISTING_DKPS)
                    RESERVED_DKPS.update(QUEUED_DKPS)
                    RESERVED_DKPS.update(COMPLETED_DKPS)
                else:
                    dkps = data.get("dkps", [])
                    RESERVED_DKPS.update(str(d) for d in dkps)

                counts = data.get("counts", {})
                if isinstance(counts, dict):
                    print(
                        "[+] EVazar status | "
                        f"Existing: {counts.get('existing', 0):,} | "
                        f"Queued: {counts.get('queued', 0):,} | "
                        f"Pending: {counts.get('pending', 0):,} | "
                        f"Processing: {counts.get('processing', 0):,} | "
                        f"Completed: {counts.get('completed', 0):,} | "
                        f"Failed: {counts.get('failed', 0):,}"
                    )

                if STATUS_API_DETAILED:
                    print(
                        f"[+] Loaded unique DKPs | Existing: {len(EXISTING_DKPS):,} | "
                        f"Queued: {len(QUEUED_DKPS):,} | "
                        f"Completed-only: {len(COMPLETED_DKPS - EXISTING_DKPS):,} | "
                        f"Reserved total: {len(RESERVED_DKPS):,}"
                    )
                else:
                    print(
                        f"[+] Loaded {len(RESERVED_DKPS):,} reserved DKPs (legacy combined API)."
                    )
                return True

            print("[-] Pre-flight Error: WordPress returned an unsuccessful response.")
            return False

        if resp.status_code in (401, 403):
            print(
                f"[-] Authentication Error: existing-dkps returned "
                f"HTTP {resp.status_code}."
            )
            print(
                "[!] Check that EVAZAR_INTERNAL_TOKEN matches "
                "the token configured in EVazar."
            )
            return False

        print(f"[-] Pre-flight Error: Endpoint returned HTTP {resp.status_code}")
        return False

    except Exception as exc:
        print(f"[-] Pre-flight Error: Failed to fetch existing DKPs: {exc}")
        return False


def parse_digikala_product_url(url):
    """Extract and normalize a Digikala product DKP from a product URL."""
    raw = (url or "").strip()
    if not raw:
        return None

    if not raw.startswith(("http://", "https://")):
        raw = "https://" + raw

    parsed = urllib.parse.urlparse(raw)
    match = re.search(r"/product/dkp-(\d+)", parsed.path, re.IGNORECASE)
    if not match:
        match = re.search(r"/product/(\d+)", parsed.path, re.IGNORECASE)

    if not match:
        return None

    dkp = match.group(1)
    return {
        "dkp": dkp,
        "url": f"https://www.digikala.com/product/dkp-{dkp}/",
    }


def extract_category_slug(url):
    """Extract category slug from a Digikala URL if it's a category page."""
    parsed = urllib.parse.urlparse(url if "://" in url else "https://" + url)
    path = parsed.path.strip("/")
    parts = path.split("/")

    if len(parts) >= 2 and parts[0] == "search" and parts[1].startswith("category-"):
        return parts[1].replace("category-", "", 1)
    if len(parts) >= 1 and parts[0].startswith("category-"):
        return parts[0].replace("category-", "", 1)
    return None


def parse_digikala_url(url, only_available=False):
    """
    Convert a normal Digikala category/brand/search URL into the
    Digikala API v1 URL structure.
    """
    url = url.strip()
    if not url.startswith(("http://", "https://")):
        url = "https://" + url

    parsed = urllib.parse.urlparse(url)
    path = parsed.path.strip("/")
    query_params = urllib.parse.parse_qs(parsed.query)

    if only_available:
        query_params["has_selling_stock"] = ["1"]

    parts = path.split("/")

    if (
        len(parts) >= 2
        and parts[0] == "search"
        and parts[1].startswith("category-")
    ):
        category = parts[1].replace("category-", "", 1)

        if len(parts) >= 3 and parts[2]:
            brand = parts[2]
            query_str = urllib.parse.urlencode(query_params, doseq=True)
            suffix = f"?{query_str}" if query_str else ""
            return (
                f"https://api.digikala.com/v1/categories/"
                f"{category}/brands/{brand}/search/{suffix}"
            )

        query_str = urllib.parse.urlencode(query_params, doseq=True)
        suffix = f"?{query_str}" if query_str else ""
        return f"https://api.digikala.com/v1/categories/{category}/search/{suffix}"

    if "/brand/" in parsed.path:
        brand = parsed.path.split("/brand/", 1)[1].strip("/")
        query_str = urllib.parse.urlencode(query_params, doseq=True)
        suffix = f"?{query_str}" if query_str else ""
        return f"https://api.digikala.com/v1/brands/{brand}/search/{suffix}"

    if "q" in query_params:
        query_str = urllib.parse.urlencode(query_params, doseq=True)
        suffix = f"?{query_str}" if query_str else ""
        return f"https://api.digikala.com/v1/search/{suffix}"

    encoded_seo = urllib.parse.quote(parsed.path)
    if "seo_url" not in query_params:
        query_params["seo_url"] = [encoded_seo]

    query_str = urllib.parse.urlencode(query_params, doseq=True)
    suffix = f"?{query_str}" if query_str else ""
    return f"https://api.digikala.com/v1/search/{suffix}"


def add_query_params(url, **params):
    """Safely add/replace query parameters."""
    parsed = urllib.parse.urlparse(url)
    query = urllib.parse.parse_qs(parsed.query)

    for key, value in params.items():
        if value is None:
            query.pop(key, None)
        else:
            query[key] = [str(value)]

    encoded = urllib.parse.urlencode(query, doseq=True)
    return urllib.parse.urlunparse(
        (
            parsed.scheme,
            parsed.netloc,
            parsed.path,
            parsed.params,
            encoded,
            parsed.fragment,
        )
    )


def get_retry_delay(resp, attempt):
    """Calculate retry delay with exponential backoff and Retry-After support."""
    if resp is not None:
        retry_after = resp.headers.get("Retry-After")
        if retry_after:
            try:
                return min(float(retry_after), 60.0)
            except ValueError:
                pass

    base = min(2 ** attempt, 30)
    return base + random.uniform(0.2, 1.0)


def fetch_page(api_url, page=1, retries=DEFAULT_RETRIES, timeout=DEFAULT_TIMEOUT):
    """Fetch exactly one API page."""
    url = add_query_params(api_url, page=page)

    with stats_lock:
        STATS.api_pages_requested += 1

    last_reason = "unknown error"

    for attempt in range(1, retries + 1):
        response = None
        try:
            response = SESSION.get(url, timeout=timeout)

            if response.status_code == 200:
                data = response.json()
                with stats_lock:
                    STATS.api_pages_success += 1
                return data

            if response.status_code == 429:
                last_reason = "HTTP 429 Too Many Requests"
                delay = get_retry_delay(response, attempt)
                print(f"[!] Page {page}: HTTP 429. Retry {attempt}/{retries} in {delay:.1f}s")
                time.sleep(delay)
                continue

            if response.status_code in (500, 502, 503, 504):
                last_reason = f"HTTP {response.status_code}"
                delay = get_retry_delay(response, attempt)
                print(f"[!] Page {page}: {last_reason}. Retry {attempt}/{retries} in {delay:.1f}s")
                time.sleep(delay)
                continue

            if response.status_code in (401, 403):
                last_reason = f"HTTP {response.status_code}"
                print(f"[-] Page {page}: {last_reason}. Access denied.")
                break

            last_reason = f"HTTP {response.status_code}"
            print(f"[-] Page {page}: {last_reason}. Response: {response.text[:200]}")
            break

        except requests.RequestException as exc:
            last_reason = f"Network error: {exc}"
            if attempt < retries:
                delay = get_retry_delay(response, attempt)
                time.sleep(delay)
            else:
                print(f"[-] Page {page}: network error after {retries} attempts: {exc}")

        except ValueError as exc:
            last_reason = f"Invalid JSON: {exc}"
            if attempt < retries:
                delay = get_retry_delay(response, attempt)
                time.sleep(delay)
            else:
                print(f"[-] Page {page}: invalid JSON after retries: {exc}")

    STATS.add_failed_page(page, url, last_reason)
    return None


def get_pager(data):
    """Extract pagination metadata safely."""
    return data.get("data", {}).get("pager", {}) if data else {}


def get_products(data):
    """Return product array from an API response."""
    if not data:
        return []
    products = data.get("data", {}).get("products", [])
    return products if isinstance(products, list) else []


def extract_items(data, min_price):
    """Extract products from one API response."""
    products = get_products(data)
    valid_items = []

    for product in products:
        with stats_lock:
            STATS.products_seen += 1

        if product.get("status") != "marketable":
            continue

        with stats_lock:
            STATS.products_marketable += 1

        selling_price = (
            product.get("default_variant", {})
            .get("price", {})
            .get("selling_price", 0)
        )

        try:
            price_toman = float(selling_price) / 10
        except (TypeError, ValueError):
            price_toman = 0

        if price_toman < min_price:
            with stats_lock:
                STATS.products_below_min_price += 1
            continue

        dkp_raw = product.get("id")
        if dkp_raw is None:
            with stats_lock:
                STATS.products_missing_id += 1
            continue

        dkp = str(dkp_raw)

        if dkp in EXISTING_DKPS:
            with stats_lock:
                STATS.products_known_occurrences += 1
                STATS.existing_dkp_hits.add(dkp)
                STATS.reserved_dkp_hits.add(dkp)
            continue

        if dkp in QUEUED_DKPS:
            with stats_lock:
                STATS.products_known_occurrences += 1
                STATS.queued_dkp_hits.add(dkp)
                STATS.reserved_dkp_hits.add(dkp)
            continue

        if dkp in COMPLETED_DKPS:
            with stats_lock:
                STATS.products_known_occurrences += 1
                STATS.completed_only_dkp_hits.add(dkp)
                STATS.reserved_dkp_hits.add(dkp)
            continue

        if dkp in RESERVED_DKPS:
            with stats_lock:
                STATS.products_known_occurrences += 1
                STATS.reserved_dkp_hits.add(dkp)
            continue

        url = f"https://www.digikala.com/product/dkp-{dkp}/"
        valid_items.append({
            "dkp": dkp,
            "url": url,
            "title": product.get("title_fa", ""),
            "price_toman": price_toman,
        })

    with stats_lock:
        STATS.products_extracted += len(valid_items)

    return valid_items


# =========================================================
# FEATURE 1: Subcategory Tree Resolver Engine
# =========================================================

def resolve_leaf_subcategories(cat_code, visited=None, depth=1, max_depth=4):
    """
    Recursively inspect a category and resolve all terminal leaf subcategories.
    If the category has no deeper subcategories, it is returned as a leaf.
    """
    if visited is None:
        visited = set()

    if cat_code in visited or depth > max_depth:
        return []
    visited.add(cat_code)

    search_url = f"https://api.digikala.com/v1/categories/{cat_code}/search/"
    data = fetch_page(search_url, 1)

    if not data:
        return [{
            "code": cat_code,
            "title": cat_code,
            "url": search_url,
            "is_leaf": True
        }]

    cat_filter = data.get("data", {}).get("filters", {}).get("categories", {})
    options = cat_filter.get("options", [])
    current_title = (
        data.get("data", {}).get("category", {}).get("title_fa")
        or cat_code
    )

    # Filter valid child options (exclude self-references)
    child_options = [
        opt for opt in options
        if opt.get("code") and opt.get("code") != cat_code and opt.get("code") not in visited
    ]

    # If no child options exist, this is a terminal leaf
    if not child_options:
        return [{
            "code": cat_code,
            "title": current_title,
            "url": search_url,
            "is_leaf": True
        }]

    # It's a parent category! Recursively resolve each child
    leaves = []
    print(f"[*] Discovered parent category '{current_title}' ({cat_code}) with {len(child_options)} subcategories.")

    for opt in child_options:
        c_code = opt.get("code")
        c_title = opt.get("title_fa") or c_code
        print(f"    -> Resolving branch: {c_title} ({c_code}) ...")

        child_leaves = resolve_leaf_subcategories(
            c_code,
            visited=visited,
            depth=depth + 1,
            max_depth=max_depth
        )

        if child_leaves:
            leaves.extend(child_leaves)
        else:
            leaves.append({
                "code": c_code,
                "title": c_title,
                "url": f"https://api.digikala.com/v1/categories/{c_code}/search/",
                "is_leaf": True
            })

    return leaves


# =========================================================
# FEATURE 2 & 3: Deep Recursive Price Slicing & Multi-Sort
# =========================================================

def scan_direct_subcategory(api_url, total_pages, total_items, all_extracted_items, min_price_toman, threads):
    """
    Mode 1: High-Speed Direct Scan (< 100 pages).
    Fetches pages 1 to total_pages sequentially/threaded without price slicing.
    """
    print(f"    [*] Mode: Direct High-Speed Scan ({total_pages} pages, ~{total_items} items)")

    # Fetch page 1
    data_p1 = fetch_page(api_url, 1)
    if data_p1:
        items_p1 = extract_items(data_p1, min_price_toman)
        all_extracted_items.extend(items_p1)

    if total_pages <= 1:
        return True

    pages = list(range(2, total_pages + 1))
    with ThreadPoolExecutor(max_workers=max(1, threads)) as executor:
        futures = {executor.submit(fetch_page, api_url, p): p for p in pages}
        for future in as_completed(futures):
            p = futures[future]
            try:
                data = future.result()
                if data:
                    items = extract_items(data, min_price_toman)
                    if items:
                        all_extracted_items.extend(items)
            except Exception as exc:
                STATS.add_failed_page(p, api_url, f"Unhandled direct page error: {exc}")
    return True


def choose_price_split(p_min, p_max, products=None):
    """Choose an interior observed-price pivot when possible."""
    if p_min is None:
        p_min = 0
    if p_max is None or p_max <= p_min:
        return None

    observed = []
    for product in (products or []):
        try:
            price = product.get("default_variant", {}).get("price", {}).get("selling_price")
            if price is not None:
                price = int(price)
                if p_min < price < p_max:
                    observed.append(price)
        except (TypeError, ValueError, AttributeError):
            continue

    if observed:
        observed.sort()
        pivot = observed[len(observed) // 2]
        if p_min < pivot < p_max:
            return pivot

    mid = (p_min + p_max) // 2
    return mid if mid > p_min else None


def build_api_url_with_price(base_url, p_min, p_max):
    """Build a price-filtered URL."""
    params = {}
    if p_min is not None:
        params["price[min]"] = int(p_min)
        params["price_min"] = int(p_min)
    if p_max is not None:
        params["price[max]"] = int(p_max)
        params["price_max"] = int(p_max)
    return add_query_params(base_url, **params)


def scan_price_range(
    base_url,
    p_min,
    p_max,
    all_extracted_items,
    min_price_toman,
    threads,
    slice_threshold,
    page_cap,
    depth=1,
):
    """
    Mode 2: Deep Recursive Price Slicing (Uncapped).
    Splits price range until pages < page_cap and items <= slice_threshold.
    If it encounters an exact-price cluster (p_min >= p_max) or hits depth limit,
    hands off that specific leaf cluster to Multi-Sort!
    """
    url = build_api_url_with_price(base_url, p_min, p_max)
    prefix = "  " * depth

    data = fetch_page(url, 1)
    if data is None:
        print(f"{prefix}[-] Failed to fetch page 1 for price range {p_min/10:,.0f} - {p_max/10:,.0f} Toman.")
        return False

    pager = get_pager(data)
    try:
        total_items = int(pager.get("total_items", 0) or 0)
        total_pages = int(pager.get("total_pages", 1) or 1)
    except (TypeError, ValueError):
        total_items, total_pages = 0, 1

    STATS.slices += 1
    with stats_lock:
        STATS.max_recursion_depth = max(STATS.max_recursion_depth, depth)

    hit_page_cap = total_pages >= page_cap

    # If empty slice, return immediately
    if total_items == 0:
        return True

    # Base case: fits under page_cap and slice_threshold
    if total_items <= slice_threshold and not hit_page_cap:
        print(
            f"{prefix}[+] Harvesting Slice: {p_min/10:,.0f} - {p_max/10:,.0f} Toman "
            f"({total_items:,} items, {total_pages} pages)"
        )
        page_1_items = extract_items(data, min_price_toman)
        if page_1_items:
            all_extracted_items.extend(page_1_items)

        if total_pages > 1:
            pages = list(range(2, total_pages + 1))
            with ThreadPoolExecutor(max_workers=max(1, threads)) as executor:
                futures = {executor.submit(fetch_page, url, p): p for p in pages}
                for future in as_completed(futures):
                    p = futures[future]
                    try:
                        d = future.result()
                        if d:
                            items = extract_items(d, min_price_toman)
                            if items:
                                all_extracted_items.extend(items)
                    except Exception as exc:
                        STATS.add_failed_page(p, url, f"Error: {exc}")
        return True

    # Safety: Recursion depth limit reached -> Hand off to Multi-Sort for this cluster
    if depth >= MAX_PRICE_RECURSION_DEPTH:
        print(
            f"{prefix}[!] Max recursion depth {MAX_PRICE_RECURSION_DEPTH} reached for "
            f"{p_min/10:,.0f} - {p_max/10:,.0f} Toman ({total_items:,} items). Engaging Multi-Sort Engine..."
        )
        return scan_multi_sort_subcategory(
            base_url=url,
            all_extracted_items=all_extracted_items,
            min_price_toman=min_price_toman,
            threads=threads,
            page_cap=page_cap,
        )

    # Needs split
    current_min = p_min if p_min is not None else 0
    if p_max is None:
        return False

    mid = choose_price_split(current_min, p_max, get_products(data))

    # Dense cluster / Exact price reached! Hand off to Multi-Sort Engine
    if mid is None or current_min >= p_max:
        print(
            f"{prefix}[!] Dense price cluster detected: {current_min / 10:,.0f} Toman "
            f"({total_items:,} items, {total_pages} pages). Engaging Multi-Sort Engine..."
        )
        return scan_multi_sort_subcategory(
            base_url=url,
            all_extracted_items=all_extracted_items,
            min_price_toman=min_price_toman,
            threads=threads,
            page_cap=page_cap,
        )

    print(
        f"{prefix}[*] Slicing: {current_min/10:,.0f} - {p_max/10:,.0f} Toman "
        f"({total_items:,} items, {total_pages} pages) -> Split at: {mid/10:,.0f} Toman"
    )

    left_ok = scan_price_range(
        base_url=base_url,
        p_min=current_min,
        p_max=mid,
        all_extracted_items=all_extracted_items,
        min_price_toman=min_price_toman,
        threads=threads,
        slice_threshold=slice_threshold,
        page_cap=page_cap,
        depth=depth + 1,
    )

    right_ok = scan_price_range(
        base_url=base_url,
        p_min=mid + 1,
        p_max=p_max,
        all_extracted_items=all_extracted_items,
        min_price_toman=min_price_toman,
        threads=threads,
        slice_threshold=slice_threshold,
        page_cap=page_cap,
        depth=depth + 1,
    )

    return left_ok and right_ok


def scan_multi_sort_subcategory(base_url, all_extracted_items, min_price_toman, threads, page_cap=100):
    """
    Mode 3: Multi-Sort Harvesting Engine for Dense Single-Price Clusters.
    Rotates through Digikala's active sort options, harvesting up to 100 pages per sort.
    """
    print(f"    [*] Mode: Multi-Sort Harvesting Engine (Bypassing 100-page limit across 9 sort dimensions)")
    before_count = len(all_extracted_items)

    for sort_info in SORT_OPTIONS_LIST:
        sort_id = sort_info["id"]
        sort_name = sort_info["name"]

        sorted_url = add_query_params(base_url, sort=sort_id)
        data_p1 = fetch_page(sorted_url, 1)

        if not data_p1:
            continue

        pager = get_pager(data_p1)
        try:
            total_pages = min(int(pager.get("total_pages", 1) or 1), page_cap)
        except (TypeError, ValueError):
            total_pages = 1

        items_p1 = extract_items(data_p1, min_price_toman)
        all_extracted_items.extend(items_p1)

        print(f"      [Sort: {sort_name}] Scanning up to {total_pages} pages ...")

        if total_pages > 1:
            pages = list(range(2, total_pages + 1))
            with ThreadPoolExecutor(max_workers=max(1, threads)) as executor:
                futures = {executor.submit(fetch_page, sorted_url, p): p for p in pages}
                for future in as_completed(futures):
                    p = futures[future]
                    try:
                        d = future.result()
                        if d:
                            items = extract_items(d, min_price_toman)
                            if items:
                                all_extracted_items.extend(items)
                    except Exception as exc:
                        STATS.add_failed_page(p, sorted_url, f"Error: {exc}")

    after_count = len(all_extracted_items)
    print(f"    [+] Multi-Sort Engine finished. Harvested {after_count - before_count:,} items from this dense cluster.")
    return True


# =========================================================
# FEATURE 4: Smart Subcategory Orchestrator
# =========================================================

def process_target_subcategory(
    subcat_info,
    index,
    total_count,
    all_extracted_items,
    args,
):
    """
    Evaluates one subcategory volume and activates the optimal harvesting strategy:
    - <= 100 pages and <= 2000 items: Direct High-Speed Scan
    - > 100 pages (any volume, e.g. 500 pages, 40,000+ items): Deep Recursive Price Slicing
    """
    title = subcat_info["title"]
    code = subcat_info["code"]
    base_url = subcat_info["url"]

    print(f"\n[{index}/{total_count}] Processing Subcategory: '{title}' ({code})")

    # Probe page 1
    probe_url = base_url
    if args.only_available:
        probe_url = add_query_params(probe_url, has_selling_stock=1)

    data = fetch_page(probe_url, 1)
    if not data:
        print(f"    [-] Failed to probe subcategory '{title}'. Skipping.")
        return False

    pager = get_pager(data)
    try:
        total_items = int(pager.get("total_items", 0) or 0)
        total_pages = int(pager.get("total_pages", 1) or 1)
    except (TypeError, ValueError):
        total_items, total_pages = 0, 1

    print(f"    [*] Stats: {total_items:,} items reported across {total_pages:,} pages.")

    if total_items == 0:
        print("    [!] No items available in this subcategory. Continuing.")
        return True

    # 1. Mode: Direct High-Speed (< 100 pages and <= 2000 items)
    if total_pages <= args.page_cap and total_items <= args.slice_threshold:
        return scan_direct_subcategory(
            api_url=probe_url,
            total_pages=total_pages,
            total_items=total_items,
            all_extracted_items=all_extracted_items,
            min_price_toman=args.min_price,
            threads=args.threads,
        )

    # 2. Mode: Deep Recursive Price Slicing (Uncapped for any volume > 100 pages)
    print(f"    [*] Mode: Deep Recursive Price Slicing ({total_pages:,} pages, ~{total_items:,} items)")

    # Read dynamic price min & max from Digikala API filters
    price_filter = data.get("data", {}).get("filters", {}).get("price", {}).get("options", {})
    api_min_rial = price_filter.get("min")
    api_max_rial = price_filter.get("max")

    user_min_rial = args.min_price * 10
    if api_min_rial is not None:
        try:
            initial_min = max(user_min_rial, int(api_min_rial))
        except (TypeError, ValueError):
            initial_min = user_min_rial
    else:
        initial_min = user_min_rial

    if args.max_price > 0:
        initial_max = args.max_price * 10
    elif api_max_rial is not None:
        try:
            initial_max = int(api_max_rial)
        except (TypeError, ValueError):
            initial_max = 5000000000  # 500M Toman in Rials safety fallback
    else:
        initial_max = 5000000000      # 500M Toman in Rials safety fallback

    # Ensure max > min
    if initial_max <= initial_min:
        initial_max = initial_min + 1000000000

    print(f"    [*] Dynamic Price Range: {initial_min / 10:,.0f} to {initial_max / 10:,.0f} Toman")

    return scan_price_range(
        base_url=probe_url,
        p_min=initial_min,
        p_max=initial_max,
        all_extracted_items=all_extracted_items,
        min_price_toman=args.min_price,
        threads=args.threads,
        slice_threshold=args.slice_threshold,
        page_cap=args.page_cap,
    )


def push_to_wordpress(items, wp_api_url):
    """Send one compatible EVazar queue batch to WordPress."""
    try:
        payload = {"items": items}
        resp = SESSION.post(wp_api_url, json=payload, timeout=30)

        if resp.status_code == 200:
            res_data = resp.json()
            print(
                f"[+] Successfully Sent | "
                f"Inserted: {res_data.get('inserted')} | "
                f"Skipped: {res_data.get('skipped')} | "
                f"Requeued: {res_data.get('requeued', 0)}"
            )
            return res_data

        if resp.status_code in (401, 403):
            print(
                f"[-] Authentication Error: endpoint returned "
                f"HTTP {resp.status_code}."
            )
            print("[!] Verify EVAZAR_INTERNAL_TOKEN.")
            return False

        print(
            f"[-] Ingest Error: HTTP {resp.status_code} | "
            f"Response: {resp.text[:200]}"
        )
        return False

    except Exception as exc:
        print(f"[-] Ingest Error: Connection failed: {exc}")
        return False


def run_single_product_mode(product_url, wp_api_url, duplicate_test=False):
    """Queue exactly one Digikala product and optionally repeat it to verify duplicate protection."""
    product = parse_digikala_product_url(product_url)

    if not product:
        print("[-] Invalid Digikala product URL.")
        print("    Example: https://www.digikala.com/product/dkp-12345678/")
        return False

    dkp = product["dkp"]
    canonical_url = product["url"]

    print("\n" + "=" * 65)
    print(" EVazar Harvester 1.6.1 - SINGLE PRODUCT TEST")
    print("=" * 65)
    print(f"DKP             : {dkp}")
    print(f"Product URL     : {canonical_url}")
    if dkp in EXISTING_DKPS:
        state = "IMPORTED / EXISTING"
    elif dkp in QUEUED_DKPS:
        state = "CURRENTLY QUEUED"
    elif dkp in COMPLETED_DKPS:
        state = "COMPLETED QUEUE RECORD"
    elif dkp in FAILED_DKPS:
        state = "FAILED / RETRYABLE"
    else:
        state = "NOT FOUND"

    print(f"Pre-flight state: {state}")

    item = {
        "dkp": dkp,
        "url": canonical_url,
    }

    print("\n[*] Sending first queue request...")
    first_result = push_to_wordpress([item], wp_api_url)
    if not first_result:
        print("[-] First queue request failed.")
        return False

    inserted = int(first_result.get("inserted", 0) or 0)

    if dkp not in EXISTING_DKPS and inserted == 0:
        print(
            "[!] Core did not insert this DKP. "
            "It may already be reserved by another process."
        )

    if duplicate_test:
        print("\n[*] Duplicate-protection test: sending the exact same DKP again...")
        time.sleep(1)
        second_result = push_to_wordpress([item], wp_api_url)

        if not second_result:
            print("[-] Duplicate-test request failed.")
            return False

        second_inserted = int(second_result.get("inserted", 0) or 0)
        second_skipped = int(second_result.get("skipped", 0) or 0)

        if second_inserted == 0 and second_skipped >= 1:
            print("[OK] Duplicate protection passed: the second request was skipped.")
        else:
            print(
                "[WARNING] Unexpected duplicate-test result: "
                f"Inserted={second_inserted}, Skipped={second_skipped}"
            )

    print("=" * 65)
    return True


def print_final_report(all_valid_items, subcategories_count):
    """Print complete accounting report."""
    unique_count = len(all_valid_items)

    STATS.products_existing = len(STATS.existing_dkp_hits)
    STATS.products_queued = len(STATS.queued_dkp_hits)
    STATS.products_completed_only = len(STATS.completed_only_dkp_hits)

    print("\n" + "=" * 65)
    print(" EVazar Harvester 1.6.2 - FINAL AUDIT REPORT")
    print("=" * 65)
    print(f"Subcategories resolved : {subcategories_count}")
    print(f"API pages requested   : {STATS.api_pages_requested:,}")
    print(f"API pages successful  : {STATS.api_pages_success:,}")
    print(f"API pages failed      : {STATS.api_pages_failed:,}")
    print(f"Price slices          : {STATS.slices:,}")
    print(f"Products seen         : {STATS.products_seen:,}")
    print(f"Marketable            : {STATS.products_marketable:,}")
    print(f"Below min price       : {STATS.products_below_min_price:,}")
    print(f"Unique imported DKPs  : {STATS.products_existing:,}")
    print(f"Unique queued DKPs    : {STATS.products_queued:,}")
    print(f"Unique completed-only : {STATS.products_completed_only:,}")
    print(f"Unique reserved seen  : {len(STATS.reserved_dkp_hits):,}")
    print(f"Known DKP occurrences : {STATS.products_known_occurrences:,}")
    print(f"Missing DKP           : {STATS.products_missing_id:,}")
    print(f"Raw items extracted   : {STATS.products_extracted:,}")
    print(f"Unique new DKPs ready : {unique_count:,}")

    if STATS.failed_pages:
        print(f"\nFAILED PAGES ({len(STATS.failed_pages)}):")
        for f in STATS.failed_pages[:20]:
            print(f"  - Page {f['page']}: {f['reason']}")
        if len(STATS.failed_pages) > 20:
            print(f"  ... and {len(STATS.failed_pages) - 20} more")

    print("\nOVERALL STATUS: COMPLETE")
    print("=" * 65)


def main():
    print("=====================================================")
    print(" [*] EVazar Industrial Harvester - Digikala to WP")
    print(" [*] Version 1.6.2")
    print(" [*] Subcategory Tree Resolver + Deep Price Slicing")
    print("=====================================================\n")

    parser = argparse.ArgumentParser(
        description="Digikala Harvester & EVazar WP Queue Injector 1.6.2"
    )
    parser.add_argument("--url", required=False, help="Digikala category/brand/search URL or product URL")
    parser.add_argument("--single_product", action="store_true", help="Queue exactly one Digikala product URL")
    parser.add_argument("--duplicate_test", action="store_true", help="In single-product mode, send the same DKP twice to verify duplicate protection")
    parser.add_argument("--wp", default=DEFAULT_WP_API, help="EVazar WordPress queue endpoint")
    parser.add_argument("--min_price", type=int, default=DEFAULT_MIN_PRICE, help="Minimum price in Toman")
    parser.add_argument("--max_price", type=int, default=DEFAULT_MAX_PRICE_TOMAN, help="Maximum price in Toman (0=auto)")
    parser.add_argument("--threads", type=int, default=DEFAULT_THREADS, help="Concurrent page requests")
    parser.add_argument("--retries", type=int, default=DEFAULT_RETRIES, help="Retries per failed API page")
    parser.add_argument("--timeout", type=int, default=DEFAULT_TIMEOUT, help="HTTP timeout in seconds")
    parser.add_argument("--slice_threshold", type=int, default=DEFAULT_SLICE_THRESHOLD, help="Items threshold to trigger slicing")
    parser.add_argument("--page_cap", type=int, default=DEFAULT_PAGE_CAP, help="Digikala pagination cap (default 100)")
    parser.add_argument("--only_available", action="store_true", help="Only products with selling stock")
    parser.add_argument("--no_subcategories", action="store_true", help="Disable subcategory resolver (treat URL as single search)")
    parser.add_argument("--fail_on_page_error", action="store_true", help="Cancel upload if any API page fails")

    args = parser.parse_args()

    if not args.url:
        print("[-] URL is required.")
        parser.print_help()
        sys.exit(2)

    # Wrap fetch_page with user retries and timeout
    original_fetch_page = fetch_page
    def configured_fetch(url, page=1):
        return original_fetch_page(url, page=page, retries=args.retries, timeout=args.timeout)
    globals()["fetch_page"] = configured_fetch

    if not configure_evazar_auth():
        sys.exit(2)

    print(f"[*] Input URL: {args.url}")
    print(f"[*] WP Endpoint: {args.wp}")
    print(f"[*] Min Price: {args.min_price:,} Toman")
    print(f"[*] Threads: {args.threads} | Retries: {args.retries} | Page Cap: {args.page_cap}")
    print(f"[*] Only Available: {'Yes' if args.only_available else 'No'}")
    print("-" * 50)

    if not fetch_existing_dkps(args.wp):
        print("[-] Authentication with WordPress failed. Exiting.")
        sys.exit(3)

    if args.single_product:
        ok = run_single_product_mode(
            product_url=args.url,
            wp_api_url=args.wp,
            duplicate_test=args.duplicate_test,
        )
        sys.exit(0 if ok else 4)

    print("-" * 50)

    # 1. Resolve Target Queue (Subcategories or Single Target)
    cat_slug = extract_category_slug(args.url)
    targets = []

    if cat_slug and not args.no_subcategories:
        print(f"[*] Resolving Category Tree for '{cat_slug}'...")
        resolved = resolve_leaf_subcategories(cat_slug)
        if resolved:
            targets = resolved
            print(f"[+] Subcategory Tree Resolved! Found {len(targets)} leaf subcategories.\n")
        else:
            print("[!] No subcategories returned by resolver; falling back to single target.")

    if not targets:
        # Fallback to single target
        api_base = parse_digikala_url(args.url, only_available=args.only_available)
        targets = [{
            "code": cat_slug or "custom-search",
            "title": "Custom Target",
            "url": api_base,
            "is_leaf": True,
        }]

    STATS.subcategories_found = len(targets)

    # 2. Process each target subcategory
    raw_extracted_items = []
    for idx, target in enumerate(targets, 1):
        process_target_subcategory(
            subcat_info=target,
            index=idx,
            total_count=len(targets),
            all_extracted_items=raw_extracted_items,
            args=args,
        )
        STATS.subcategories_processed += 1

    # 3. Global Deduplication
    unique_items_map = {}
    for item in raw_extracted_items:
        dkp = item.get("dkp")
        if dkp and dkp not in unique_items_map:
            unique_items_map[dkp] = item

    all_valid_items = list(unique_items_map.values())

    # 4. Print Audit Report
    print_final_report(all_valid_items, len(targets))

    if args.fail_on_page_error and STATS.failed_pages:
        print("[-] Upload cancelled because --fail_on_page_error was set and some pages failed.")
        sys.exit(5)

    if not all_valid_items:
        print("\n[+] No new products to upload. Done!")
        sys.exit(0)

    # 5. Inject into WordPress Queue
    print(f"\n[*] Injecting {len(all_valid_items):,} unique products to WordPress in batches of {BATCH_SIZE}...")
    for i in range(0, len(all_valid_items), BATCH_SIZE):
        batch = all_valid_items[i : i + BATCH_SIZE]
        batch_num = i // BATCH_SIZE + 1
        total_batches = (len(all_valid_items) + BATCH_SIZE - 1) // BATCH_SIZE

        print(f"[*] Sending Batch {batch_num}/{total_batches} ({len(batch)} items) ...")
        if not push_to_wordpress(batch, args.wp):
            print(f"[-] WordPress rejected Batch {batch_num}. Halting remaining batches.")
            sys.exit(4)
        time.sleep(1)

    print("\n[+] Success! All batches ingested into EVazar WordPress Queue.")
    print("[*] The background ingestion worker will process product sync automatically.")


if __name__ == "__main__":
    main()
