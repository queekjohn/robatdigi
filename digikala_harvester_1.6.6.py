import os
import sys
import time
import json
import random
import re
import argparse
import csv
import urllib.parse
from datetime import datetime
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
# Version: 1.6.6
#
# Key Features in 1.6.4:
# 0. Coverage Audit Fix:
#    - When max_price=0, the audit pairs the minimum with Digikala's reported upper price bound.
#    - A zero reported denominator is never treated as 100% coverage.
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

BATCH_SIZE = 100
DEFAULT_INGEST_RETRIES = 5
DEFAULT_INGEST_TIMEOUT = 60
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
        self.target_audits = []
        self.unique_eligible_dkps = set()
        self.root_reported_items = None
        self.root_reported_pages = None
        self.scan_audits = []
        self.coverage_recovery_attempts = 0
        self.coverage_unresolved = False

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
CURRENT_AUDIT = None
LAST_INGEST_UNCERTAIN = False
LAST_INGEST_FATAL = False
LAST_INGEST_HTTP_STATUS = None
LAST_INGEST_ERROR = ""
LAST_ADAPTIVE_BATCH_SIZE = BATCH_SIZE


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

        # Coverage audit records each eligible DKP once per global scan and
        # once per current leaf. This is independent from queue/new-item logic.
        with stats_lock:
            STATS.unique_eligible_dkps.add(dkp)
            if CURRENT_AUDIT is not None:
                CURRENT_AUDIT["eligible_dkps"].add(dkp)

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

def get_eligible_dkps(data, min_price_toman):
    """Return all eligible product DKPs present in one API response page."""
    eligible = set()

    for product in get_products(data):
        if product.get("status") != "marketable":
            continue

        selling_price = (
            product.get("default_variant", {})
            .get("price", {})
            .get("selling_price", 0)
        )

        try:
            price_toman = float(selling_price) / 10
        except (TypeError, ValueError):
            price_toman = 0

        if price_toman < min_price_toman:
            continue

        dkp_raw = product.get("id")
        if dkp_raw is not None:
            eligible.add(str(dkp_raw))

    return eligible


def record_scan_audit(
    kind,
    label,
    url,
    reported_items,
    reported_pages,
    fetched_pages,
    unique_dkps,
    duplicate_occurrences=0,
    extra=None,
):
    """Record one concrete scan unit so under-coverage can be traced later."""
    expected = int(reported_items or 0)
    unique_count = len(unique_dkps)
    coverage = (unique_count / expected * 100) if expected > 0 else None

    audit = {
        "kind": kind,
        "label": label,
        "url": url,
        "reported_items": expected,
        "reported_pages": int(reported_pages or 0),
        "fetched_pages": int(fetched_pages or 0),
        "unique_dkps": set(unique_dkps),
        "unique_count": unique_count,
        "duplicate_occurrences": int(duplicate_occurrences or 0),
        "coverage": coverage,
    }
    if isinstance(extra, dict):
        audit.update(extra)

    with stats_lock:
        STATS.scan_audits.append(audit)

    return audit


def extract_brand_options(data):
    """
    Extract upstream brand facet options defensively.

    Digikala has used slightly different filter object shapes over time, so
    accept the common forms without assuming one exact payload shape.
    """
    filters = data.get("data", {}).get("filters", {}) if data else {}
    candidates = []

    for key in ("brands", "brand"):
        raw = filters.get(key)
        if isinstance(raw, dict):
            options = raw.get("options", [])
        elif isinstance(raw, list):
            options = raw
        else:
            options = []

        if isinstance(options, list):
            candidates.extend(options)

    found = []
    seen = set()

    for option in candidates:
        if not isinstance(option, dict):
            continue

        raw_id = (
            option.get("id")
            or option.get("brand_id")
            or option.get("value")
            or option.get("code")
        )

        if isinstance(raw_id, dict):
            raw_id = (
                raw_id.get("id")
                or raw_id.get("value")
                or raw_id.get("code")
            )

        if raw_id is None:
            continue

        raw_id = str(raw_id).strip()
        if not raw_id or raw_id in seen:
            continue

        seen.add(raw_id)
        found.append({
            "id": raw_id,
            "title": option.get("title_fa")
                or option.get("title")
                or option.get("name")
                or raw_id,
        })

    return found


def build_api_url_with_brand(base_url, brand_id):
    """Apply one Digikala brand facet as an upstream filter."""
    return add_query_params(base_url, **{"brands[0]": str(brand_id)})

def get_price_bounds_from_url(url):
    """Read existing Digikala price bounds from a query URL."""
    parsed = urllib.parse.urlparse(url)
    query = urllib.parse.parse_qs(parsed.query)

    def first_int(keys):
        for key in keys:
            values = query.get(key)
            if not values:
                continue
            try:
                return int(values[0])
            except (TypeError, ValueError):
                continue
        return None

    return (
        first_int(("price[min]", "price_min")),
        first_int(("price[max]", "price_max")),
    )





def print_scan_diagnostics():
    """Print the most important scan-unit coverage diagnostics."""
    audits = list(STATS.scan_audits)
    if not audits:
        return

    suspicious = [
        a for a in audits
        if a.get("reported_items", 0) > 0
        and a.get("coverage") is not None
        and a.get("coverage") < 99.5
    ]
    suspicious.sort(key=lambda a: a.get("coverage", 100))

    print("\n" + "=" * 65)
    print(" SCAN-UNIT DIAGNOSTICS")
    print("=" * 65)
    print(f"Scan units recorded : {len(audits):,}")
    print(f"Coverage issues     : {len(suspicious):,}")

    for idx, audit in enumerate(suspicious[:20], 1):
        print(
            f"[{idx}] {audit.get('kind')} | {audit.get('label')} | "
            f"Reported: {audit.get('reported_items'):,} | "
            f"Unique: {audit.get('unique_count'):,} | "
            f"Coverage: {audit.get('coverage', 0):.2f}% | "
            f"Pages: {audit.get('fetched_pages'):,}/{audit.get('reported_pages'):,} | "
            f"Duplicates: {audit.get('duplicate_occurrences', 0):,}"
        )
        if audit.get("sort_id") is not None:
            print(f"    Sort: {audit.get('sort_id')}")
        if audit.get("brand_id") is not None:
            print(f"    Brand ID: {audit.get('brand_id')}")

    if len(suspicious) > 20:
        print(f"... and {len(suspicious) - 20:,} more coverage issues")

    print("=" * 65)


def fetch_coverage_report(
    api_url,
    min_price_toman=0,
    max_price_toman=0,
    auto_max_price_toman=0,
):
    """
    Fetch filtered page-1 metadata to establish a coverage denominator.

    Digikala does not reliably use a lone minimum-price filter as an upstream
    market-wide filter. When the user leaves max_price at 0, pair the minimum
    with the category/search endpoint's own reported maximum price.
    """
    report_url = api_url

    effective_max_price_toman = int(max_price_toman or 0)
    if effective_max_price_toman <= 0:
        effective_max_price_toman = int(auto_max_price_toman or 0)

    if min_price_toman > 0:
        report_url = add_query_params(
            report_url,
            **{
                "price[min]": int(min_price_toman * 10),
                "price_min": int(min_price_toman * 10),
            },
        )

    if effective_max_price_toman > 0:
        report_url = add_query_params(
            report_url,
            **{
                "price[max]": int(effective_max_price_toman * 10),
                "price_max": int(effective_max_price_toman * 10),
            },
        )
    elif min_price_toman > 0:
        print(
            "    [!] Coverage Audit: could not determine an upper price bound; "
            "reported eligible count is unavailable."
        )
        return None

    data = fetch_page(report_url, 1)
    if not data:
        return None

    pager = get_pager(data)
    try:
        total_items = int(pager.get("total_items", 0) or 0)
        total_pages = int(pager.get("total_pages", 1) or 1)
    except (TypeError, ValueError):
        return None

    return {
        "reported_items": max(0, total_items),
        "reported_pages": max(1, total_pages),
        "url": report_url,
        "min_price_toman": int(min_price_toman or 0),
        "max_price_toman": effective_max_price_toman,
    }


def print_coverage_report():
    """Print overall and per-leaf unique-DKP coverage diagnostics."""
    print("\n" + "=" * 65)
    print(" COVERAGE AUDIT - UNIQUE DKP DISCOVERY")
    print("=" * 65)

    root_items = STATS.root_reported_items
    overall_unique = len(STATS.unique_eligible_dkps)

    print(
        "Input target reported items : "
        f"{root_items:,}" if root_items is not None else
        "Input target reported items : N/A"
    )
    print(f"Unique eligible DKPs seen   : {overall_unique:,}")

    if root_items is not None and root_items > 0:
        coverage = (overall_unique / root_items) * 100
        gap = root_items - overall_unique
        print(f"Reference coverage          : {coverage:.2f}%")
        if gap >= 0:
            print(f"Reference gap               : {gap:,}")
        else:
            print(f"Reference overage           : {abs(gap):,}")
        print(
            "Note: this is a reference ratio. "
            "It is not a proof of exact completeness when Digikala counts change."
        )
    else:
        print("Reference coverage          : N/A")

    print("\nPER-LEAF COVERAGE")
    print("-" * 65)

    warning_count = 0
    sum_reported = 0
    sum_seen = 0

    for idx, audit in enumerate(STATS.target_audits, 1):
        expected = audit.get("reported_eligible_items")
        seen = len(audit.get("eligible_dkps", set()))
        sum_seen += seen

        title = audit.get("title", "Unknown")
        code = audit.get("code", "")
        print(f"[{idx}/{len(STATS.target_audits)}] {title} ({code})")

        if expected is None:
            print(
                f"    Reported eligible: N/A | Unique seen: {seen:,} | "
                "Coverage: N/A | CHECK"
            )
            warning_count += 1
            continue

        if expected <= 0:
            print(
                f"    Reported eligible: {expected:,} | Unique seen: {seen:,} | "
                "Coverage: N/A | CHECK"
            )
            warning_count += 1
            continue

        sum_reported += expected
        coverage = seen / expected * 100
        gap = expected - seen

        if coverage >= 99.5:
            status = "OK"
        elif coverage >= 95.0:
            status = "CHECK"
        else:
            status = "WARNING"

        if status != "OK":
            warning_count += 1

        gap_text = f"{gap:,}" if gap >= 0 else f"-{abs(gap):,}"
        print(
            f"    Reported eligible: {expected:,} | Unique seen: {seen:,} | "
            f"Coverage: {coverage:.2f}% | Gap: {gap_text} | {status}"
        )

    if STATS.target_audits:
        print("\nLeaf reported total (sum) : " f"{sum_reported:,}")
        print("Leaf unique seen (sum)    : " f"{sum_seen:,}")
        print(
            "Note: leaf totals may overlap. "
            "Overall coverage above uses the union of unique DKPs."
        )

    if warning_count:
        STATS.coverage_unresolved = True
        print(f"[!] Coverage review needed for {warning_count} leaf target(s).")
    else:
        STATS.coverage_unresolved = False
        print("[+] All leaf targets are within the reference coverage threshold.")

    print("=" * 65)

    print_scan_diagnostics()


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
    Fetches every advertised page and audits unique DKPs against total_items.
    """
    print(f"    [*] Mode: Direct High-Speed Scan ({total_pages} pages, ~{total_items} items)")

    unit_dkps = set()
    fetched_pages = 0

    data_p1 = fetch_page(api_url, 1)
    if data_p1:
        fetched_pages += 1
        unit_dkps.update(get_eligible_dkps(data_p1, min_price_toman))
        items_p1 = extract_items(data_p1, min_price_toman)
        all_extracted_items.extend(items_p1)

    if total_pages > 1:
        pages = list(range(2, total_pages + 1))
        with ThreadPoolExecutor(max_workers=max(1, threads)) as executor:
            futures = {executor.submit(fetch_page, api_url, p): p for p in pages}
            for future in as_completed(futures):
                p = futures[future]
                try:
                    data = future.result()
                    if data:
                        fetched_pages += 1
                        unit_dkps.update(get_eligible_dkps(data, min_price_toman))
                        items = extract_items(data, min_price_toman)
                        if items:
                            all_extracted_items.extend(items)
                except Exception as exc:
                    STATS.add_failed_page(p, api_url, f"Unhandled direct page error: {exc}")

    record_scan_audit(
        kind="DIRECT",
        label=api_url,
        url=api_url,
        reported_items=total_items,
        reported_pages=total_pages,
        fetched_pages=fetched_pages,
        unique_dkps=unit_dkps,
        duplicate_occurrences=max(0, fetched_pages * 20 - len(unit_dkps)),
    )

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
    allow_brand_fallback=True,
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

        unit_dkps = set(get_eligible_dkps(data, min_price_toman))
        fetched_pages = 1

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
                            fetched_pages += 1
                            unit_dkps.update(get_eligible_dkps(d, min_price_toman))
                            items = extract_items(d, min_price_toman)
                            if items:
                                all_extracted_items.extend(items)
                    except Exception as exc:
                        STATS.add_failed_page(p, url, f"Error: {exc}")

        record_scan_audit(
            kind="PRICE_SLICE",
            label=f"{p_min/10:,.0f}-{p_max/10:,.0f} Toman",
            url=url,
            reported_items=total_items,
            reported_pages=total_pages,
            fetched_pages=fetched_pages,
            unique_dkps=unit_dkps,
            duplicate_occurrences=max(0, fetched_pages * 20 - len(unit_dkps)),
            extra={"depth": depth, "p_min": p_min, "p_max": p_max},
        )
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
            expected_items=total_items,
            fallback_data=data,
            allow_brand_fallback=allow_brand_fallback,
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
            expected_items=total_items,
            fallback_data=data,
            allow_brand_fallback=allow_brand_fallback,
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
        allow_brand_fallback=allow_brand_fallback,
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
        allow_brand_fallback=allow_brand_fallback,
    )

    return left_ok and right_ok


def scan_multi_sort_subcategory(
    base_url,
    all_extracted_items,
    min_price_toman,
    threads,
    page_cap=100,
    expected_items=None,
    fallback_data=None,
    allow_brand_fallback=True,
):
    """
    Mode 3: Multi-Sort Harvesting Engine for Dense Single-Price Clusters.
    Rotates through Digikala's active sort options, harvesting up to 100 pages per sort.
    """
    print(
        "    [*] Mode: Multi-Sort Harvesting Engine "
        "(Bypassing 100-page limit across 9 sort dimensions)"
    )
    before_count = len(all_extracted_items)
    sort_union = set()
    sort_signature_map = {}

    for sort_info in SORT_OPTIONS_LIST:
        sort_id = sort_info["id"]
        sort_name = sort_info["name"]
        sorted_url = add_query_params(base_url, sort=sort_id)
        data_p1 = fetch_page(sorted_url, 1)

        if not data_p1:
            continue

        pager = get_pager(data_p1)
        try:
            reported_items = int(pager.get("total_items", 0) or 0)
            total_pages = min(int(pager.get("total_pages", 1) or 1), page_cap)
        except (TypeError, ValueError):
            reported_items, total_pages = 0, 1

        sort_dkps = set(get_eligible_dkps(data_p1, min_price_toman))
        sort_union.update(sort_dkps)
        sort_signature_map[sort_id] = tuple(sorted(sort_dkps))

        items_p1 = extract_items(data_p1, min_price_toman)
        all_extracted_items.extend(items_p1)

        fetched_pages = 1
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
                            fetched_pages += 1
                            sort_dkps.update(get_eligible_dkps(d, min_price_toman))
                            sort_union.update(get_eligible_dkps(d, min_price_toman))
                            items = extract_items(d, min_price_toman)
                            if items:
                                all_extracted_items.extend(items)
                    except Exception as exc:
                        STATS.add_failed_page(p, sorted_url, f"Error: {exc}")

        record_scan_audit(
            kind="SORT",
            label=sort_name,
            url=sorted_url,
            reported_items=reported_items,
            reported_pages=total_pages,
            fetched_pages=fetched_pages,
            unique_dkps=sort_dkps,
            duplicate_occurrences=max(0, fetched_pages * 20 - len(sort_dkps)),
            extra={"sort_id": sort_id},
        )

    overall_expected = int(expected_items or 0)
    overall_coverage = (
        len(sort_union) / overall_expected * 100
        if overall_expected > 0 else None
    )

    after_count = len(all_extracted_items)
    print(
        f"    [+] Multi-Sort Engine finished. Harvested "
        f"{after_count - before_count:,} raw new items from this dense cluster."
    )
    if overall_expected > 0:
        print(
            f"    [*] Dense cluster unique coverage: "
            f"{len(sort_union):,}/{overall_expected:,} "
            f"({overall_coverage:.2f}%)"
        )

    # If sorting does not provide enough new DKPs, partition this exact cluster
    # by available brand facets before accepting the scan as complete.
    if (
        allow_brand_fallback
        and overall_expected > 0
        and overall_coverage < 99.5
        and fallback_data
    ):
        brand_options = extract_brand_options(fallback_data)
        if len(brand_options) > 1:
            return scan_brand_partitions(
                base_url=base_url,
                all_extracted_items=all_extracted_items,
                min_price_toman=min_price_toman,
                threads=threads,
                page_cap=page_cap,
                expected_items=overall_expected,
                brand_options=brand_options,
            )

    return True


def scan_brand_partitions(
    base_url,
    all_extracted_items,
    min_price_toman,
    threads,
    page_cap,
    expected_items,
    brand_options,
):
    """Partition a stubborn dense cluster by Digikala's brand facet."""
    print(
        f"    [!] Coverage below threshold. Trying brand partition fallback "
        f"with {len(brand_options):,} available brands..."
    )

    seen_union = set()
    processed = 0
    for brand in brand_options:
        brand_url = build_api_url_with_brand(base_url, brand["id"])

        data = fetch_page(brand_url, 1)
        if not data:
            continue

        pager = get_pager(data)
        try:
            total_items = int(pager.get("total_items", 0) or 0)
            total_pages = int(pager.get("total_pages", 1) or 1)
        except (TypeError, ValueError):
            total_items, total_pages = 0, 1

        if total_items <= 0:
            continue

        # Safety: if the brand filter does not narrow the upstream result at all,
        # do not repeat the same huge scan once per facet.
        if total_items >= expected_items and expected_items > 0:
            print(
                f"    [!] Brand filter '{brand['title']}' did not narrow the "
                f"reference set ({total_items:,} items). Assuming this facet "
                "is not an effective upstream partition and stopping fallback."
            )
            break

        processed += 1
        brand_dkps = set()

        if total_pages <= page_cap and total_items <= DEFAULT_SLICE_THRESHOLD:
            brand_dkps.update(get_eligible_dkps(data, min_price_toman))
            items = extract_items(data, min_price_toman)
            if items:
                all_extracted_items.extend(items)

            if total_pages > 1:
                pages = list(range(2, total_pages + 1))
                with ThreadPoolExecutor(max_workers=max(1, threads)) as executor:
                    futures = {
                        executor.submit(fetch_page, brand_url, p): p
                        for p in pages
                    }
                    for future in as_completed(futures):
                        p = futures[future]
                        try:
                            d = future.result()
                            if d:
                                brand_dkps.update(get_eligible_dkps(d, min_price_toman))
                                items = extract_items(d, min_price_toman)
                                if items:
                                    all_extracted_items.extend(items)
                        except Exception as exc:
                            STATS.add_failed_page(
                                p, brand_url, f"Brand partition error: {exc}"
                            )
        else:
            # Preserve the exact price-range of the stubborn cluster. Do not
            # replace it with price=0..0, otherwise the fallback would scan the
            # entire brand instead of only the unresolved dense cluster.
            existing_p_min, existing_p_max = get_price_bounds_from_url(brand_url)
            if existing_p_min is None:
                existing_p_min = 0
            if existing_p_max is None:
                existing_p_max = 0

            scan_price_range(
                base_url=brand_url,
                p_min=existing_p_min,
                p_max=existing_p_max,
                all_extracted_items=all_extracted_items,
                min_price_toman=min_price_toman,
                threads=threads,
                slice_threshold=DEFAULT_SLICE_THRESHOLD,
                page_cap=page_cap,
                depth=1,
                allow_brand_fallback=False,
            )

            # The recursive scan above owns the detailed page harvesting.
            # Refresh only page 1 here to collect the brand's local DKP sample
            # for the fallback union; global/leaf audit is already populated.
            brand_page = fetch_page(brand_url, 1)
            if brand_page:
                brand_dkps.update(
                    get_eligible_dkps(brand_page, min_price_toman)
                )

        seen_union.update(brand_dkps)

        record_scan_audit(
            kind="BRAND",
            label=str(brand["title"]),
            url=brand_url,
            reported_items=total_items,
            reported_pages=total_pages,
            fetched_pages=min(total_pages, page_cap),
            unique_dkps=brand_dkps,
            duplicate_occurrences=max(
                0, min(total_pages, page_cap) * 20 - len(brand_dkps)
            ),
            extra={"brand_id": brand["id"]},
        )

    STATS.coverage_recovery_attempts += 1
    coverage = (
        len(seen_union) / expected_items * 100
        if expected_items > 0 else 0
    )
    print(
        f"    [+] Brand fallback finished: {len(seen_union):,}/"
        f"{expected_items:,} unique DKPs ({coverage:.2f}%) "
        f"across {processed:,} non-empty brands."
    )
    if coverage < 99.5:
        STATS.coverage_unresolved = True
        print(
            "    [WARNING] Brand fallback did not reach the reference threshold. "
            "The cluster remains unresolved and must not be called complete."
        )

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

    # Separate coverage probe using the exact availability/price constraints
    # that define the eligible harvest population. This does not alter harvest logic.
    # When max_price is 0, use the API's own reported maximum for this leaf so
    # the upstream filter is a proper min/max pair.
    leaf_price_filter = (
        data.get("data", {})
        .get("filters", {})
        .get("price", {})
        .get("options", {})
    )
    leaf_api_max_rial = leaf_price_filter.get("max")
    try:
        leaf_auto_max_price_toman = int(leaf_api_max_rial) // 10 if leaf_api_max_rial is not None else 0
    except (TypeError, ValueError):
        leaf_auto_max_price_toman = 0

    coverage_report = fetch_coverage_report(
        probe_url,
        min_price_toman=args.min_price,
        max_price_toman=args.max_price,
        auto_max_price_toman=leaf_auto_max_price_toman,
    )

    audit = {
        "title": title,
        "code": code,
        "reported_raw_items": total_items,
        "reported_raw_pages": total_pages,
        "reported_eligible_items": (
            coverage_report["reported_items"] if coverage_report else None
        ),
        "reported_eligible_pages": (
            coverage_report["reported_pages"] if coverage_report else None
        ),
        "eligible_dkps": set(),
    }

    with stats_lock:
        STATS.target_audits.append(audit)

    global CURRENT_AUDIT
    CURRENT_AUDIT = audit

    try:
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
    
    
    finally:
        CURRENT_AUDIT = None

def fetch_reserved_dkps(wp_api_url, timeout=DEFAULT_INGEST_TIMEOUT):
    """Fetch a fresh, authoritative set of products already queued or imported."""
    endpoint = wp_api_url.replace("/queue-ingest", "/existing-dkps")
    if endpoint == wp_api_url:
        return None

    try:
        response = SESSION.get(endpoint, timeout=timeout)
        if response.status_code != 200:
            return None
        data = response.json()
        if not isinstance(data, dict) or data.get("success") is False:
            return None

        reserved = set()
        found_list = False
        for field in ("dkps", "existing_dkps", "queued_dkps", "completed_dkps", "failed_dkps"):
            values = data.get(field)
            if isinstance(values, list):
                found_list = True
                reserved.update(str(value) for value in values if value is not None)
        return reserved if found_list else None
    except (requests.RequestException, ValueError, TypeError):
        return None


def push_to_wordpress(
    items,
    wp_api_url,
    retries=DEFAULT_INGEST_RETRIES,
    timeout=DEFAULT_INGEST_TIMEOUT,
    reconcile_first=False,
    batch_label=None,
):
    """Send one EVazar batch with retry/backoff and safe queue reconciliation."""
    global LAST_INGEST_UNCERTAIN, LAST_INGEST_FATAL
    global LAST_INGEST_HTTP_STATUS, LAST_INGEST_ERROR

    LAST_INGEST_UNCERTAIN = False
    LAST_INGEST_FATAL = False
    LAST_INGEST_HTTP_STATUS = None
    LAST_INGEST_ERROR = ""

    original_items = list(items or [])
    if not original_items:
        return {"success": True, "inserted": 0, "skipped": 0, "requeued": 0}

    max_attempts = max(1, int(retries or 1))
    pending_items = original_items
    uncertain = bool(reconcile_first)
    reconciled_count = 0
    last_error = "unknown error"
    response = None
    label = f" | Batch {batch_label}" if batch_label is not None else ""

    for attempt in range(1, max_attempts + 1):
        if uncertain:
            reserved = fetch_reserved_dkps(wp_api_url, timeout=timeout)
            if reserved is None:
                last_error = "delivery status is unknown and EVazar queue status could not be fetched"
                LAST_INGEST_ERROR = last_error
                print(
                    f"[!] Queue reconciliation unavailable{label} | "
                    f"items={len(original_items)} | attempt={attempt}/{max_attempts}; "
                    "the request will not be blindly resent."
                )
                if attempt < max_attempts:
                    time.sleep(get_retry_delay(None, attempt))
                continue

            pending_items = [
                item for item in original_items
                if str(item.get("dkp", "")) not in reserved
            ]
            reconciled_count = len(original_items) - len(pending_items)
            uncertain = False

            if not pending_items:
                print(
                    f"[+] EVazar reconciliation{label} | all {len(original_items)} items "
                    "already queued/imported; no duplicate POST needed."
                )
                return {
                    "success": True, "inserted": 0, "skipped": len(original_items),
                    "requeued": 0, "reconciled": True,
                }

            if reconciled_count:
                print(
                    f"[!] Reconciled uncertain request{label} | "
                    f"already reserved={reconciled_count} | retrying missing={len(pending_items)}"
                )

        response = None
        try:
            print(
                f"[*] Queue POST{label} | items={len(pending_items)} | "
                f"attempt={attempt}/{max_attempts}"
            )
            response = SESSION.post(wp_api_url, json={"items": pending_items}, timeout=timeout)
            LAST_INGEST_HTTP_STATUS = response.status_code

            if response.status_code == 200:
                try:
                    res_data = response.json()
                except ValueError as exc:
                    last_error = f"HTTP 200 with invalid JSON: {exc}; body={response.text[:300]}"
                    uncertain = True
                else:
                    if not isinstance(res_data, dict) or res_data.get("success") is False:
                        last_error = f"EVazar rejected payload at HTTP 200; body={response.text[:300]}"
                        LAST_INGEST_ERROR = last_error
                        LAST_INGEST_FATAL = True
                        print(f"[-] Explicit ingest rejection{label} | HTTP 200 | items={len(pending_items)} | {last_error}")
                        return False

                    if reconciled_count:
                        try:
                            res_data["skipped"] = int(res_data.get("skipped", 0) or 0) + reconciled_count
                        except (TypeError, ValueError):
                            pass

                    print(
                        f"[+] Successfully Sent{label} | items={len(pending_items)} | "
                        f"Inserted: {res_data.get('inserted', 0)} | "
                        f"Skipped: {res_data.get('skipped', 0)} | "
                        f"Requeued: {res_data.get('requeued', 0)}"
                    )
                    LAST_INGEST_UNCERTAIN = False
                    LAST_INGEST_ERROR = ""
                    return res_data

            elif response.status_code in (408, 429, 500, 502, 503, 504):
                last_error = f"HTTP {response.status_code}: {response.text[:300]}"
                uncertain = True
            elif response.status_code == 413:
                last_error = f"HTTP 413 Payload Too Large: {response.text[:300]}"
                uncertain = False
            else:
                last_error = f"HTTP {response.status_code}: {response.text[:300]}"
                LAST_INGEST_ERROR = last_error
                LAST_INGEST_FATAL = response.status_code in (400, 401, 403, 404, 405, 422)
                LAST_INGEST_UNCERTAIN = False
                print(
                    f"[-] Explicit ingest rejection{label} | HTTP {response.status_code} | "
                    f"items={len(pending_items)} | {response.text[:300]}"
                )
                return False

        except requests.RequestException as exc:
            last_error = f"Connection failed without a confirmed HTTP response: {exc}"
            uncertain = True

        LAST_INGEST_ERROR = last_error
        if attempt < max_attempts:
            delay = get_retry_delay(response, attempt)
            print(
                f"[!] Temporary/ambiguous ingest error{label} | items={len(pending_items)} | "
                f"HTTP={LAST_INGEST_HTTP_STATUS or 'no response'} | "
                f"attempt={attempt}/{max_attempts} | delay={delay:.1f}s | {last_error}"
            )
            time.sleep(delay)

    LAST_INGEST_UNCERTAIN = uncertain
    LAST_INGEST_ERROR = last_error
    print(
        f"[-] Ingest retries exhausted{label} | items={len(pending_items)} | "
        f"HTTP={LAST_INGEST_HTTP_STATUS or 'no response'} | attempts={max_attempts} | "
        f"classification={'AMBIGUOUS: reconcile queue before retry' if uncertain else 'EXPLICIT REJECTION'} | "
        f"error={last_error}"
    )
    return False


def next_smaller_batch_size(current_size):
    """Fallback sizes for repeatedly failing requests."""
    current_size = max(1, int(current_size or 1))
    for candidate in (50, 25, 10):
        if candidate < current_size:
            return candidate
    return None


def push_batch_adaptive(
    items, wp_api_url, initial_batch_size, retries, timeout,
    batch_num, total_batches, reconcile_first=False,
):
    """Retry a batch, shrinking 100 -> 50 -> 25 -> 10 only when safe to do so."""
    global LAST_INGEST_UNCERTAIN, LAST_INGEST_FATAL, LAST_INGEST_ERROR
    global LAST_ADAPTIVE_BATCH_SIZE

    original = list(items or [])
    current_size = max(1, min(100, int(initial_batch_size or BATCH_SIZE)))
    LAST_ADAPTIVE_BATCH_SIZE = current_size
    if not original:
        return {"success": True, "inserted": 0, "skipped": 0, "requeued": 0,
                "batch_size_used": current_size}

    label = f"{batch_num}/{total_batches}"
    inserted_total = 0
    skipped_total = 0
    requeued_total = 0
    reconciled_any = False

    first_result = push_to_wordpress(
        original, wp_api_url, retries=retries, timeout=timeout,
        reconcile_first=reconcile_first, batch_label=label,
    )
    if first_result:
        first_result["batch_size_used"] = current_size
        return first_result
    if LAST_INGEST_FATAL:
        return False

    if LAST_INGEST_UNCERTAIN:
        reserved = fetch_reserved_dkps(wp_api_url, timeout=timeout)
        if reserved is None:
            LAST_INGEST_UNCERTAIN = True
            print(f"[-] Cannot safely retry batch {label}: EVazar queue status is unavailable.")
            return False
        pending = [item for item in original if str(item.get("dkp", "")) not in reserved]
        already = len(original) - len(pending)
        if already:
            skipped_total += already
            reconciled_any = True
            print(f"[+] Reconciliation batch {label} | already reserved={already} | still missing={len(pending)}")
        if not pending:
            LAST_INGEST_UNCERTAIN = False
            return {"success": True, "inserted": 0, "skipped": skipped_total,
                    "requeued": 0, "reconciled": True, "batch_size_used": current_size}
    else:
        pending = list(original)

    smaller = next_smaller_batch_size(current_size)
    if smaller is None:
        LAST_INGEST_UNCERTAIN = bool(reconciled_any)
        print(f"[-] Batch {label} failed at minimum size {current_size}.")
        return False

    current_size = smaller
    LAST_ADAPTIVE_BATCH_SIZE = current_size
    print(f"[!] Adaptive batch fallback | parent={label} | new size={current_size} | remaining={len(pending)}")

    while pending:
        chunk = pending[:current_size]
        tail = pending[len(chunk):]
        chunk_result = push_to_wordpress(
            chunk, wp_api_url, retries=retries, timeout=timeout,
            reconcile_first=False, batch_label=f"{label}, sub-batch {len(chunk)}",
        )
        if chunk_result:
            try:
                inserted_total += int(chunk_result.get("inserted", 0) or 0)
                skipped_total += int(chunk_result.get("skipped", 0) or 0)
                requeued_total += int(chunk_result.get("requeued", 0) or 0)
            except (TypeError, ValueError):
                pass
            pending = tail
            continue

        if LAST_INGEST_FATAL:
            LAST_INGEST_UNCERTAIN = bool(reconciled_any or len(pending) < len(original))
            return False

        if LAST_INGEST_UNCERTAIN:
            reserved = fetch_reserved_dkps(wp_api_url, timeout=timeout)
            if reserved is None:
                LAST_INGEST_UNCERTAIN = True
                print(f"[-] Reconciliation failed for batch {label}; checkpoint remains uncertain.")
                return False
            missing_chunk = [item for item in chunk if str(item.get("dkp", "")) not in reserved]
            already_chunk = len(chunk) - len(missing_chunk)
            if already_chunk:
                skipped_total += already_chunk
                reconciled_any = True
            pending = missing_chunk + tail
            if not missing_chunk:
                continue

        smaller = next_smaller_batch_size(current_size)
        if smaller is None:
            LAST_INGEST_UNCERTAIN = bool(
                LAST_INGEST_UNCERTAIN or reconciled_any or len(pending) < len(original)
            )
            print(
                f"[-] Batch {label} still failing at minimum size 10 | "
                f"HTTP={LAST_INGEST_HTTP_STATUS or 'no response'} | error={LAST_INGEST_ERROR}"
            )
            return False

        current_size = smaller
        LAST_ADAPTIVE_BATCH_SIZE = current_size
        print(
            f"[!] Adaptive batch fallback | parent={label} | new size={current_size} | "
            f"remaining={len(pending)} | previous error={LAST_INGEST_ERROR}"
        )

    LAST_INGEST_UNCERTAIN = False
    return {
        "success": True, "inserted": inserted_total, "skipped": skipped_total,
        "requeued": requeued_total, "adaptive": True, "reconciled": reconciled_any,
        "batch_size_used": current_size,
    }



def upload_checkpoint_paths():
    """Return stable paths for resumable queue upload state."""
    base_dir = os.path.dirname(os.path.abspath(__file__))
    return (
        os.path.join(base_dir, "digikala_harvester_upload_items.json"),
        os.path.join(base_dir, "digikala_harvester_upload_state.json"),
    )


def atomic_write_json(path, payload):
    """Write JSON atomically so an interrupted write does not corrupt the checkpoint."""
    temp_path = path + ".tmp"
    with open(temp_path, "w", encoding="utf-8") as handle:
        json.dump(payload, handle, ensure_ascii=False, separators=(",", ":"))
        handle.flush()
        os.fsync(handle.fileno())
    os.replace(temp_path, path)



def load_items_from_csv(csv_path):
    """Load unique Digikala products from a harvester products CSV."""
    if not csv_path:
        raise ValueError("CSV path is empty.")

    path = os.path.abspath(os.path.expanduser(os.path.expandvars(csv_path.strip().strip('"'))))
    if not os.path.isfile(path):
        raise FileNotFoundError(f"Products CSV was not found: {path}")

    def normalized_key(value):
        return re.sub(r"[^a-z0-9_]", "", str(value or "").strip().lower().replace(" ", "_"))

    dkp_keys = (
        "dkp", "dkp_id", "dkpid", "digikala_id", "digikala_product_id",
        "product_id", "productid", "id", "sku", "_sku", "_evazar_sku"
    )
    url_keys = ("url", "product_url", "producturl", "link", "canonical_url")

    items_by_dkp = {}
    rows_read = 0
    header_keys = set()

    try:
        with open(path, "r", encoding="utf-8-sig", newline="") as handle:
            reader = csv.DictReader(handle)
            if not reader.fieldnames:
                raise ValueError("CSV has no header row.")

            header_map = {normalized_key(name): name for name in reader.fieldnames if name}
            header_keys = set(header_map)

            for row in reader:
                rows_read += 1
                if not isinstance(row, dict):
                    continue

                raw_url = ""
                for key in url_keys:
                    column = header_map.get(key)
                    if column and row.get(column):
                        raw_url = str(row.get(column)).strip()
                        if raw_url:
                            break

                raw_dkp = ""
                for key in dkp_keys:
                    column = header_map.get(key)
                    if column and row.get(column) not in (None, ""):
                        candidate = str(row.get(column)).strip()
                        match = re.fullmatch(r"(\d+)(?:\.0+)?", candidate)
                        if match:
                            raw_dkp = match.group(1)
                            break

                if not raw_dkp and raw_url:
                    parsed = parse_digikala_product_url(raw_url)
                    if parsed:
                        raw_dkp = parsed["dkp"]

                if not raw_dkp:
                    continue

                if raw_url:
                    parsed = parse_digikala_product_url(raw_url)
                    canonical_url = parsed["url"] if parsed else (
                        f"https://www.digikala.com/product/dkp-{raw_dkp}/"
                    )
                else:
                    canonical_url = f"https://www.digikala.com/product/dkp-{raw_dkp}/"

                items_by_dkp.setdefault(raw_dkp, {
                    "dkp": raw_dkp,
                    "url": canonical_url,
                })
    except UnicodeDecodeError as exc:
        raise ValueError(f"CSV encoding is not recognized: {exc}") from exc

    if not any(key in header_keys for key in dkp_keys) and not any(key in header_keys for key in url_keys):
        raise ValueError(
            "CSV headers do not contain a supported DKP/ID or product URL column. "
            f"Headers found: {', '.join(sorted(header_keys)) or '(none)'}"
        )

    if not items_by_dkp:
        raise ValueError(
            f"No valid Digikala product IDs were found in {rows_read} data rows. "
            "The report may use a different column layout."
        )

    print(
        f"[*] CSV loaded | Rows: {rows_read:,} | "
        f"Unique DKPs: {len(items_by_dkp):,} | Skipped/duplicate rows: "
        f"{max(0, rows_read - len(items_by_dkp)):,}"
    )
    return list(items_by_dkp.values())

def upload_items_with_checkpoint(
    items,
    wp_api_url,
    batch_size=BATCH_SIZE,
    retries=DEFAULT_INGEST_RETRIES,
    timeout=DEFAULT_INGEST_TIMEOUT,
    resume=False,
):
    """
    Send products in batches and checkpoint every acknowledged batch.
    On an ambiguous result, resume reconciles the current batch before sending.
    """
    items_path, state_path = upload_checkpoint_paths()
    batch_size = max(1, min(100, int(batch_size or BATCH_SIZE)))
    retries = max(1, int(retries or DEFAULT_INGEST_RETRIES))
    timeout = max(5, int(timeout or DEFAULT_INGEST_TIMEOUT))

    if resume:
        if not os.path.isfile(items_path):
            print(f"[-] Resume file not found: {items_path}")
            return False
        try:
            with open(items_path, "r", encoding="utf-8") as handle:
                upload_items = json.load(handle)
        except (OSError, ValueError) as exc:
            print(f"[-] Could not read upload checkpoint items: {exc}")
            return False

        if not isinstance(upload_items, list):
            print("[-] Upload checkpoint is invalid: expected a JSON list.")
            return False

        if os.path.isfile(state_path):
            try:
                with open(state_path, "r", encoding="utf-8") as handle:
                    state = json.load(handle)
            except (OSError, ValueError) as exc:
                print(f"[-] Could not read upload checkpoint state: {exc}")
                return False
        else:
            # If only the item file survived, start from zero and reconcile
            # before the first POST; this is slower but prevents blind duplicates.
            state = {
                "version": 1,
                "wp_api_url": wp_api_url,
                "batch_size": batch_size,
                "next_index": 0,
                "completed_batches": 0,
                "total_items": len(upload_items),
                "uncertain": True,
                "updated_at": time.time(),
            }
            atomic_write_json(state_path, state)

        if state.get("wp_api_url") != wp_api_url:
            print(
                "[-] Checkpoint endpoint does not match the current --wp value. "
                "Use the same endpoint that was used in the original run."
            )
            return False
        if int(state.get("total_items", -1)) != len(upload_items):
            print("[-] Checkpoint item count does not match its state file.")
            return False

        batch_size = max(1, min(100, int(state.get("batch_size", batch_size) or batch_size)))
        next_index = max(0, min(len(upload_items), int(state.get("next_index", 0) or 0)))
        print(
            f"[*] Resuming queued upload at item {next_index + 1:,}/{len(upload_items):,} "
            f"with batches of {batch_size}."
        )
    else:
        if os.path.exists(items_path) or os.path.exists(state_path):
            print(
                "[!] An interrupted-upload checkpoint already exists. "
                "Use --resume_upload before starting a new full scan."
            )
            return False

        upload_items = []
        seen = set()
        for item in (items or []):
            dkp = str(item.get("dkp", "")).strip()
            url = str(item.get("url", "")).strip()
            if not dkp or dkp in seen:
                continue
            seen.add(dkp)
            upload_items.append({"dkp": dkp, "url": url})

        if not upload_items:
            print("[+] No unique items remain to upload.")
            return True

        try:
            atomic_write_json(items_path, upload_items)
            state = {
                "version": 1,
                "wp_api_url": wp_api_url,
                "batch_size": batch_size,
                "next_index": 0,
                "completed_batches": 0,
                "total_items": len(upload_items),
                "uncertain": False,
                "updated_at": time.time(),
            }
            atomic_write_json(state_path, state)
        except OSError as exc:
            print(f"[-] Could not create resumable upload checkpoint: {exc}")
            return False
        next_index = 0
        print(
            f"\n[*] Injecting {len(upload_items):,} unique products into the EVazar queue "
            f"in batches of {batch_size}..."
        )

    while next_index < len(upload_items):
        batch = upload_items[next_index:next_index + batch_size]
        batch_num = int(state.get("completed_batches", 0) or 0) + 1
        total_batches = (len(upload_items) + batch_size - 1) // batch_size
        reconcile_before = bool(state.get("uncertain", False))

        # Mark the active batch as uncertain before network I/O. If the process
        # exits mid-request, resume will query EVazar before sending this batch.
        state["uncertain"] = True
        state["active_batch_start"] = next_index
        state["updated_at"] = time.time()
        atomic_write_json(state_path, state)

        print(
            f"[*] Sending Batch {batch_num}/{total_batches} "
            f"({len(batch)} items) ..."
        )
        result = push_batch_adaptive(
            batch,
            wp_api_url,
            initial_batch_size=batch_size,
            retries=retries,
            timeout=timeout,
            batch_num=batch_num,
            total_batches=total_batches,
            reconcile_first=reconcile_before,
        )

        if not result:
            if not LAST_INGEST_FATAL and LAST_ADAPTIVE_BATCH_SIZE < int(state.get("batch_size", batch_size) or batch_size):
                state["batch_size"] = LAST_ADAPTIVE_BATCH_SIZE
                batch_size = LAST_ADAPTIVE_BATCH_SIZE
            state["uncertain"] = bool(LAST_INGEST_UNCERTAIN)
            state["last_error"] = {
                "batch_number": batch_num,
                "item_start_index": next_index,
                "item_count": len(batch),
                "http_status": LAST_INGEST_HTTP_STATUS,
                "uncertain_delivery": bool(LAST_INGEST_UNCERTAIN),
                "fatal": bool(LAST_INGEST_FATAL),
                "error": LAST_INGEST_ERROR,
                "recorded_at": datetime.now().astimezone().isoformat(timespec="seconds"),
            }
            state["updated_at"] = time.time()
            atomic_write_json(state_path, state)
            print(
                f"[-] Upload paused at item {next_index + 1:,}. "
                "The checkpoint has been saved."
            )
            print(
                "    Resume with: python digikala_harvester_1.6.6.py --resume_upload"
            )
            return False

        next_index += len(batch)
        state["next_index"] = next_index
        state["completed_batches"] = batch_num
        used_size = int(result.get("batch_size_used", batch_size) or batch_size) if isinstance(result, dict) else batch_size
        if 1 <= used_size < int(state.get("batch_size", batch_size) or batch_size):
            state["batch_size"] = used_size
            batch_size = used_size
            print(f"[*] Persisting adaptive batch size: {batch_size}")
        state["uncertain"] = False
        state.pop("active_batch_start", None)
        state.pop("last_error", None)
        state["updated_at"] = time.time()
        atomic_write_json(state_path, state)

        if next_index < len(upload_items):
            time.sleep(1)

    # Remove state first. If interrupted during cleanup, the item file remains
    # and resume can safely reconstruct a conservative checkpoint from index 0.
    try:
        if os.path.exists(state_path):
            os.remove(state_path)
        if os.path.exists(items_path):
            os.remove(items_path)
    except OSError as exc:
        print(f"[!] Upload completed, but checkpoint cleanup failed: {exc}")
        print("    The remaining checkpoint can be safely reconciled with --resume_upload.")
        return False

    print("\n[+] Success! All products have been accepted or reconciled in the EVazar queue.")
    print("[*] The background EVazar worker will process the queue separately.")
    return True

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
    print(" EVazar Harvester 1.6.6 - SINGLE PRODUCT TEST")
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
    print(" EVazar Harvester 1.6.6 - FINAL AUDIT REPORT")
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

    if STATS.failed_pages:
        overall_status = "NOT VERIFIED - API PAGE FAILURES"
    elif STATS.coverage_unresolved:
        overall_status = "COMPLETE WITH COVERAGE WARNINGS"
    else:
        overall_status = "COMPLETE"

    print(f"\nOVERALL STATUS: {overall_status}")
    print("=" * 65)


def main():
    print("=====================================================")
    print(" [*] EVazar Industrial Harvester - Digikala to WP")
    print(" [*] Version 1.6.6")
    print(" [*] Subcategory Tree Resolver + Deep Price Slicing")
    print("=====================================================\n")

    parser = argparse.ArgumentParser(
        description="Digikala Harvester & EVazar WP Queue Injector 1.6.6"
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
    parser.add_argument("--batch_size", type=int, default=BATCH_SIZE, help="Products per EVazar queue POST (maximum 100)")
    parser.add_argument("--ingest_retries", type=int, default=DEFAULT_INGEST_RETRIES, help="Queue POST/reconciliation attempts")
    parser.add_argument("--ingest_timeout", type=int, default=DEFAULT_INGEST_TIMEOUT, help="Timeout for each queue request, in seconds")
    parser.add_argument("--resume_upload", action="store_true", help="Resume an interrupted upload checkpoint without rescanning Digikala")
    parser.add_argument("--resume_csv", help="Upload unique products from an existing harvester products CSV without rescanning Digikala")
    parser.add_argument("--slice_threshold", type=int, default=DEFAULT_SLICE_THRESHOLD, help="Items threshold to trigger slicing")
    parser.add_argument("--page_cap", type=int, default=DEFAULT_PAGE_CAP, help="Digikala pagination cap (default 100)")
    parser.add_argument("--only_available", action="store_true", help="Only products with selling stock")
    parser.add_argument("--no_subcategories", action="store_true", help="Disable subcategory resolver (treat URL as single search)")
    parser.add_argument("--fail_on_page_error", action="store_true", help="Cancel upload if any API page fails")

    args = parser.parse_args()

    if not args.url and not args.resume_upload and not args.resume_csv:
        print("[-] URL is required unless --resume_upload or --resume_csv is selected.")
        parser.print_help()
        sys.exit(2)

    args.batch_size = max(1, min(100, int(args.batch_size)))
    args.ingest_retries = max(1, int(args.ingest_retries))
    args.ingest_timeout = max(5, int(args.ingest_timeout))

    # Wrap fetch_page with user retries and timeout
    original_fetch_page = fetch_page
    def configured_fetch(url, page=1):
        return original_fetch_page(url, page=page, retries=args.retries, timeout=args.timeout)
    globals()["fetch_page"] = configured_fetch

    if not configure_evazar_auth():
        sys.exit(2)

    if args.resume_upload:
        print("[*] Resume mode selected; Digikala will not be rescanned.")
        ok = upload_items_with_checkpoint(
            items=[],
            wp_api_url=args.wp,
            batch_size=args.batch_size,
            retries=args.ingest_retries,
            timeout=args.ingest_timeout,
            resume=True,
        )
        sys.exit(0 if ok else 4)

    items_path, state_path = upload_checkpoint_paths()
    if os.path.exists(items_path) or os.path.exists(state_path):
        print(
            "[!] A saved upload checkpoint exists. Resume it first with --resume_upload; "
            "a new scan will not overwrite it."
        )
        sys.exit(6)


    if args.resume_csv:
        # Reuse the exact products exported by an earlier scan; never rescan Digikala.
        items_path, state_path = upload_checkpoint_paths()
        if os.path.exists(items_path) or os.path.exists(state_path):
            print(
                "[!] An upload checkpoint already exists. Resume it first with --resume_upload; "
                "the CSV import will not overwrite it."
            )
            sys.exit(6)

        try:
            csv_items = load_items_from_csv(args.resume_csv)
        except (OSError, ValueError) as exc:
            print(f"[-] CSV resume error: {exc}")
            sys.exit(2)

        reserved = fetch_reserved_dkps(args.wp, timeout=args.ingest_timeout)
        if reserved is None:
            print(
                "[-] Could not verify existing/queued EVazar DKPs. "
                "No CSV products were sent, to avoid duplicates."
            )
            sys.exit(3)

        pending_csv_items = [
            item for item in csv_items if item["dkp"] not in reserved
        ]
        already_reserved = len(csv_items) - len(pending_csv_items)
        print(
            f"[*] CSV recovery | Total unique: {len(csv_items):,} | "
            f"Already queued/imported: {already_reserved:,} | "
            f"Remaining to enqueue: {len(pending_csv_items):,}"
        )

        ok = upload_items_with_checkpoint(
            items=pending_csv_items,
            wp_api_url=args.wp,
            batch_size=args.batch_size,
            retries=args.ingest_retries,
            timeout=args.ingest_timeout,
            resume=False,
        )
        sys.exit(0 if ok else 4)

    print(f"[*] Input URL: {args.url}")
    print(f"[*] WP Endpoint: {args.wp}")
    print(f"[*] Min Price: {args.min_price:,} Toman")
    print(f"[*] Threads: {args.threads} | Retries: {args.retries} | Page Cap: {args.page_cap}")
    print(f"[*] Queue batch size: {args.batch_size} | Ingest attempts: {args.ingest_retries} | Ingest timeout: {args.ingest_timeout}s")
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

    # Coverage denominator for the exact input category/query with the same
    # availability and price constraints used by the harvester.
    if cat_slug:
        root_api = parse_digikala_url(args.url, only_available=args.only_available)

        # Probe the root once so an omitted max_price can reuse Digikala's own
        # reported upper bound instead of issuing a lone minimum-price filter.
        root_probe = fetch_page(root_api, 1)
        root_price_filter = (
            root_probe.get("data", {})
            .get("filters", {})
            .get("price", {})
            .get("options", {})
            if root_probe
            else {}
        )
        root_api_max_rial = root_price_filter.get("max")
        try:
            root_auto_max_price_toman = (
                int(root_api_max_rial) // 10 if root_api_max_rial is not None else 0
            )
        except (TypeError, ValueError):
            root_auto_max_price_toman = 0

        root_coverage = fetch_coverage_report(
            root_api,
            min_price_toman=args.min_price,
            max_price_toman=args.max_price,
            auto_max_price_toman=root_auto_max_price_toman,
        )
        if root_coverage:
            STATS.root_reported_items = root_coverage["reported_items"]
            STATS.root_reported_pages = root_coverage["reported_pages"]

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
    print_coverage_report()

    if args.fail_on_page_error and STATS.failed_pages:
        print("[-] Upload cancelled because --fail_on_page_error was set and some pages failed.")
        sys.exit(5)

    if not all_valid_items:
        print("\n[+] No new products to upload. Done!")
        sys.exit(0)

    # 5. Checkpointed upload to the EVazar queue (100 items max per request)
    if not upload_items_with_checkpoint(
        items=all_valid_items,
        wp_api_url=args.wp,
        batch_size=args.batch_size,
        retries=args.ingest_retries,
        timeout=args.ingest_timeout,
        resume=False,
    ):
        sys.exit(4)


if __name__ == "__main__":
    main()
