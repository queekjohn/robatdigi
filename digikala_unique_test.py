# Digikala category scanner with detailed local reports. No EVazar upload.
import argparse
import concurrent.futures
import csv
import json
import random
import time
import urllib.parse
from collections import Counter, defaultdict
from datetime import datetime
from pathlib import Path
from threading import Lock

import requests
from requests.adapters import HTTPAdapter

UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/140.0 Safari/537.36'
SORTS = [22, 1, 4, 7, 20, 21, 25, 27, 29]

class Scanner:
    def __init__(self, args):
        self.args = args
        self.s = requests.Session()
        self.s.headers.update({
            'User-Agent': UA,
            'Accept': 'application/json, text/plain, */*',
            'Referer': 'https://www.digikala.com/',
            'Accept-Language': 'fa-IR,fa;q=0.9,en;q=0.8',
        })
        adapter = HTTPAdapter(pool_connections=max(32, args.threads * 2),
                              pool_maxsize=max(32, args.threads * 2), max_retries=0)
        self.s.mount('https://', adapter)
        self.s.mount('http://', adapter)
        self.lock = Lock()
        self.pages = self.ok = self.fail = self.slices = self.retries_used = 0
        self.failed = []
        self.product_info = {}
        self.category_products = []
        self.category_errors = []

    def add(self, url, **params):
        parsed = urllib.parse.urlparse(url)
        query = urllib.parse.parse_qs(parsed.query)
        query.update({k: [str(v)] for k, v in params.items() if v is not None})
        return urllib.parse.urlunparse((parsed.scheme, parsed.netloc, parsed.path,
                                        parsed.params, urllib.parse.urlencode(query, doseq=True), parsed.fragment))

    def get(self, url, page=1):
        request_url = self.add(url, page=page)
        with self.lock:
            self.pages += 1
        for attempt in range(self.args.retries + 1):
            try:
                response = self.s.get(request_url, timeout=self.args.timeout)
                if response.status_code == 200:
                    try:
                        payload = response.json()
                    except ValueError as exc:
                        with self.lock:
                            self.fail += 1
                            self.failed.append({'url': request_url, 'page': page, 'error': f'Invalid JSON: {exc}'})
                        return None
                    with self.lock:
                        self.ok += 1
                    return payload
                if response.status_code in (429, 500, 502, 503, 504) and attempt < self.args.retries:
                    with self.lock:
                        self.retries_used += 1
                    retry_after = response.headers.get('Retry-After')
                    delay = float(retry_after) if retry_after and retry_after.replace('.', '', 1).isdigit() else min(2 ** attempt, 8) + random.random()
                    time.sleep(delay)
                    continue
                with self.lock:
                    self.fail += 1
                    self.failed.append({'url': request_url, 'page': page, 'status': response.status_code,
                                        'error': f'HTTP {response.status_code}'})
                return None
            except requests.RequestException as exc:
                if attempt < self.args.retries:
                    with self.lock:
                        self.retries_used += 1
                    time.sleep(min(2 ** attempt, 8) + random.random())
                    continue
                with self.lock:
                    self.fail += 1
                    self.failed.append({'url': request_url, 'page': page, 'error': str(exc)})
                return None
        return None

    @staticmethod
    def products(data):
        return (data or {}).get('data', {}).get('products', []) or []

    @staticmethod
    def pager(data):
        return (data or {}).get('data', {}).get('pager', {}) or {}

    def eligible(self, data):
        ids = set()
        for product in self.products(data):
            if product.get('status') != 'marketable':
                continue
            try:
                price_toman = int(float(product.get('default_variant', {}).get('price', {}).get('selling_price', 0)) / 10)
            except (TypeError, ValueError):
                price_toman = 0
            if price_toman < self.args.min_price or product.get('id') is None:
                continue
            pid = str(product['id'])
            ids.add(pid)
            title = product.get('title_fa') or product.get('title') or ''
            url = product.get('url') or product.get('web_url') or ''
            variant = product.get('default_variant') or {}
            brand = (product.get('brand') or {}).get('title_fa') or (product.get('brand') or {}).get('title') or ''
            row = {'id': pid, 'title': title, 'price_toman': price_toman, 'brand': brand, 'url': url,
                   'status': product.get('status', '')}
            with self.lock:
                old = self.product_info.get(pid)
                if old:
                    # Keep the lowest nonzero observed price; fill missing descriptive fields from later responses.
                    if price_toman and (not old.get('price_toman') or price_toman < old['price_toman']):
                        old['price_toman'] = price_toman
                    for key in ('title', 'brand', 'url'):
                        if not old.get(key) and row.get(key):
                            old[key] = row[key]
                else:
                    self.product_info[pid] = row
        return ids

    def scan_pages(self, url, count, unique):
        data = self.get(url, 1)
        if data:
            unique.update(self.eligible(data))
        if count <= 1:
            return
        with concurrent.futures.ThreadPoolExecutor(max_workers=self.args.threads) as pool:
            futures = [pool.submit(self.get, url, page) for page in range(2, count + 1)]
            for future in concurrent.futures.as_completed(futures):
                data = future.result()
                if data:
                    unique.update(self.eligible(data))

    def bounds(self, data):
        options = (data or {}).get('data', {}).get('filters', {}).get('price', {}).get('options', {}) or {}
        try:
            low = int(options.get('min') or self.args.min_price * 10)
        except (TypeError, ValueError):
            low = self.args.min_price * 10
        try:
            high = int(options.get('max') or low + 1_000_000_000)
        except (TypeError, ValueError):
            high = low + 1_000_000_000
        return max(low, self.args.min_price * 10), high

    def slice(self, url, low, high, unique, depth=0):
        if depth > 25:
            return self.sort_cluster(url, unique)
        sliced_url = self.add(url, **{'price[min]': low, 'price[max]': high,
                                     'price_min': low, 'price_max': high})
        data = self.get(sliced_url, 1)
        if not data:
            return False
        pager = self.pager(data)
        total_items = int(pager.get('total_items') or 0)
        pages = int(pager.get('total_pages') or 1)
        with self.lock:
            self.slices += 1
        if total_items == 0:
            return True
        if total_items <= self.args.slice_threshold and pages <= self.args.page_cap:
            self.scan_pages(sliced_url, pages, unique)
            return True
        prices = []
        for product in self.products(data):
            try:
                price = int(product.get('default_variant', {}).get('price', {}).get('selling_price'))
                if low < price < high:
                    prices.append(price)
            except (TypeError, ValueError):
                pass
        middle = sorted(prices)[len(prices) // 2] if prices else (low + high) // 2
        if not (low < middle < high):
            return self.sort_cluster(sliced_url, unique)
        left = self.slice(url, low, middle, unique, depth + 1)
        right = self.slice(url, middle + 1, high, unique, depth + 1)
        return left and right

    def sort_cluster(self, url, unique):
        success = True
        for sort_id in SORTS:
            sorted_url = self.add(url, sort=sort_id)
            data = self.get(sorted_url, 1)
            if not data:
                success = False
                continue
            page_count = min(int(self.pager(data).get('total_pages') or 1), self.args.page_cap)
            self.scan_pages(sorted_url, page_count, unique)
        return success

    @staticmethod
    def category_slug(url):
        parsed = urllib.parse.urlparse(url if '://' in url else 'https://' + url)
        for segment in parsed.path.strip('/').split('/'):
            if segment.startswith('category-'):
                return segment[9:]
        return None

    def resolve(self, code, seen=None, depth=1):
        if seen is None:
            seen = set()
        if code in seen or depth > self.args.max_depth:
            return []
        seen.add(code)
        api_url = f'https://api.digikala.com/v1/categories/{code}/search/'
        data = self.get(api_url, 1)
        if not data:
            return [{'code': code, 'title': code, 'url': api_url, 'resolve_failed': True}]
        options = ((data.get('data', {}).get('filters', {}).get('categories', {}) or {}).get('options', []) or [])
        kids = [item for item in options if item.get('code') and item.get('code') not in seen and item.get('code') != code]
        if not kids:
            return [{'code': code, 'title': data.get('data', {}).get('category', {}).get('title_fa') or code,
                     'url': api_url, 'resolve_failed': False}]
        targets = []
        for child in kids:
            targets.extend(self.resolve(child['code'], seen, depth + 1))
        return targets or [{'code': code, 'title': code, 'url': api_url, 'resolve_failed': False}]

    def scan_target(self, target):
        url = target['url']
        data = self.get(url, 1)
        if not data:
            return set(), {'error': 'Could not load category response', 'api_url': url}
        pager = self.pager(data)
        total_items = int(pager.get('total_items') or 0)
        pages = int(pager.get('total_pages') or 1)
        unique = set()
        if total_items == 0:
            return unique, None
        if pages <= self.args.page_cap and total_items <= self.args.slice_threshold:
            self.scan_pages(url, pages, unique)
        else:
            low, high = self.bounds(data)
            if not self.slice(url, low, high, unique):
                return unique, {'error': 'One or more price slices/sort clusters failed', 'api_url': url}
        return unique, None


def write_csv(path, rows, fieldnames):
    with path.open('w', newline='', encoding='utf-8-sig') as handle:
        writer = csv.DictWriter(handle, fieldnames=fieldnames, extrasaction='ignore')
        writer.writeheader()
        writer.writerows(rows)


def main():
    parser = argparse.ArgumentParser(description='Scan Digikala categories and create local reports; never uploads to EVazar.')
    parser.add_argument('--url', action='append')
    parser.add_argument('--url-file')
    parser.add_argument('--threads', type=int, default=16)
    parser.add_argument('--min-price', type=int, default=50000)
    parser.add_argument('--retries', type=int, default=2)
    parser.add_argument('--timeout', type=int, default=15)
    parser.add_argument('--page-cap', type=int, default=100)
    parser.add_argument('--slice-threshold', type=int, default=2000)
    parser.add_argument('--max-depth', type=int, default=4)
    parser.add_argument('--no-subcategories', action='store_true')
    parser.add_argument('--report-dir', default='digikala_reports', help='Folder for timestamped report files')
    args = parser.parse_args()
    urls = list(args.url or [])
    if args.url_file:
        with open(args.url_file, encoding='utf-8-sig') as handle:
            urls += [line.strip() for line in handle if line.strip() and not line.lstrip().startswith('#')]
    if not urls:
        parser.error('Use --url or --url-file')

    scanner = Scanner(args)
    started_at = datetime.now().astimezone()
    started = time.time()
    global_ids = set()
    url_reports = []
    target_reports = []
    membership = defaultdict(set)
    output_dir = Path(args.report_dir)
    output_dir.mkdir(parents=True, exist_ok=True)
    stamp = started_at.strftime('%Y%m%d_%H%M%S')
    print('=' * 76)
    print(' DIGIKALA UNIQUE PRODUCT SCAN — LOCAL REPORTS / NO EVAZAR UPLOAD')
    print('=' * 76)
    print(f'Threads: {args.threads} | Minimum price: {args.min_price:,} toman | Retries: {args.retries}')
    print('EVazar: DISABLED — no product data is sent to EVazar.')
    print(f'Started: {started_at.isoformat(timespec="seconds")}\n')

    try:
        for index, source_url in enumerate(urls, 1):
            category_started = time.time()
            slug = scanner.category_slug(source_url)
            targets = []
            if slug and not args.no_subcategories:
                targets = scanner.resolve(slug)
            if not targets:
                normalized = source_url if source_url.startswith('http') else 'https://' + source_url
                parsed = urllib.parse.urlparse(normalized)
                if 'api.digikala.com' in parsed.netloc:
                    api_url = normalized
                else:
                    fallback_slug = scanner.category_slug(normalized)
                    api_url = f'https://api.digikala.com/v1/categories/{fallback_slug}/search/' if fallback_slug else normalized
                targets = [{'code': slug or 'target', 'title': slug or 'Target', 'url': api_url, 'resolve_failed': False}]
            local_ids = set()
            global_before_input = set(global_ids)
            print(f'[{index}/{len(urls)}] {source_url} -> {len(targets)} target(s)')
            for target_index, target in enumerate(targets, 1):
                before_failures = scanner.fail
                ids, target_error = scanner.scan_target(target)
                local_ids.update(ids)
                global_ids.update(ids)
                membership_key = f'{index}:{target.get("code", target_index)}'
                for pid in ids:
                    membership[pid].add(f'{index}. {target.get("title", target.get("code", "Target"))}')
                target_row = {
                    'input_index': index, 'source_url': source_url,
                    'target_code': target.get('code', ''), 'target_title': target.get('title', ''),
                    'api_url': target.get('url', ''), 'unique_eligible_products': len(ids),
                    'api_failures_during_target': scanner.fail - before_failures,
                    'resolve_failed': bool(target.get('resolve_failed', False)),
                    'status': 'error' if target_error or target.get('resolve_failed') else ('empty' if not ids else 'ok'),
                    'error': (target_error or {}).get('error', 'Category resolution request failed' if target.get('resolve_failed') else ''),
                }
                target_reports.append(target_row)
                if target_error:
                    scanner.category_errors.append(target_row.copy())
                print(f'   {target_index:>3}/{len(targets)} {target.get("title", "Target")[:45]} : {len(ids):,} eligible unique' + (f' | WARNING: {target_row["error"]}' if target_row['error'] else ''))
            elapsed_category = time.time() - category_started
            url_row = {
                'input_index': index, 'source_url': source_url, 'targets_found': len(targets),
                'eligible_unique_products_in_input': len(local_ids),
                'new_global_unique_products': len(local_ids - global_before_input),
                'elapsed_seconds': round(elapsed_category, 2),
                'status': 'error' if any(r['input_index'] == index and r['status'] == 'error' for r in target_reports) else ('empty' if not local_ids else 'ok'),
            }
            url_reports.append(url_row)
            print(f'   RESULT: {len(local_ids):,} unique in this input | {url_row["new_global_unique_products"]:,} first-seen globally | {elapsed_category:.1f}s\n')
    except KeyboardInterrupt:
        print('\nInterrupted by user. Saving the data collected so far...')
    except Exception as exc:
        scanner.category_errors.append({'status': 'fatal', 'error': repr(exc)})
        print(f'\nUnexpected error: {exc!r}. Saving the data collected so far...')
    finally:
        ended_at = datetime.now().astimezone()
        elapsed = time.time() - started
        product_rows = []
        for pid in sorted(global_ids, key=lambda value: (not value.isdigit(), int(value) if value.isdigit() else value)):
            row = dict(scanner.product_info.get(pid, {'id': pid, 'title': '', 'price_toman': '', 'brand': '', 'url': '', 'status': ''}))
            row['category_count'] = len(membership.get(pid, set()))
            row['categories'] = ' | '.join(sorted(membership.get(pid, set())))
            product_rows.append(row)
        overlap_rows = [row for row in product_rows if row['category_count'] > 1]
        prices = [row['price_toman'] for row in product_rows if isinstance(row.get('price_toman'), int) and row['price_toman'] > 0]
        summary = {
            'started_at': started_at.isoformat(timespec='seconds'), 'ended_at': ended_at.isoformat(timespec='seconds'),
            'elapsed_seconds': round(elapsed, 2), 'input_urls_count': len(urls),
            'input_urls_completed': len(url_reports), 'targets_scanned': len(target_reports),
            'targets_ok': sum(row.get('status') == 'ok' for row in target_reports),
            'targets_empty': sum(row.get('status') == 'empty' for row in target_reports),
            'targets_with_errors': sum(row.get('status') == 'error' for row in target_reports),
            'globally_unique_eligible_products': len(global_ids),
            'products_found_in_multiple_targets': len(overlap_rows),
            'minimum_price_toman': args.min_price, 'product_price_min_toman': min(prices) if prices else None,
            'product_price_max_toman': max(prices) if prices else None,
            'product_price_average_toman': round(sum(prices) / len(prices)) if prices else None,
            'api_requests': scanner.pages, 'api_successes': scanner.ok, 'api_failures': scanner.fail,
            'retry_attempts': scanner.retries_used, 'price_slices': scanner.slices,
            'threads': args.threads, 'page_cap': args.page_cap, 'slice_threshold': args.slice_threshold,
            'evazar_uploads': 0, 'evazar_mode': 'DISABLED',
            'note': 'Global uniqueness is based on Digikala product IDs returned by the API and meeting the marketable/minimum-price filters. Category coverage may be incomplete if requests fail or fallback caps are reached.'
        }
        report = {'summary': summary, 'input_urls': url_reports, 'targets': target_reports,
                  'products': product_rows, 'products_in_multiple_targets': overlap_rows,
                  'api_failures': scanner.failed, 'category_errors': scanner.category_errors}
        json_path = output_dir / f'digikala_report_{stamp}.json'
        products_path = output_dir / f'digikala_products_{stamp}.csv'
        categories_path = output_dir / f'digikala_categories_{stamp}.csv'
        targets_path = output_dir / f'digikala_targets_{stamp}.csv'
        overlaps_path = output_dir / f'digikala_overlaps_{stamp}.csv'
        failures_path = output_dir / f'digikala_failures_{stamp}.csv'
        text_path = output_dir / f'digikala_summary_{stamp}.txt'
        try:
            json_path.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding='utf-8')
            write_csv(products_path, product_rows, ['id', 'title', 'brand', 'price_toman', 'status', 'url', 'category_count', 'categories'])
            write_csv(categories_path, url_reports, ['input_index', 'source_url', 'targets_found', 'eligible_unique_products', 'new_global_unique_products', 'elapsed_seconds', 'status'])
            write_csv(targets_path, target_reports, ['input_index', 'source_url', 'target_code', 'target_title', 'api_url', 'unique_eligible_products', 'api_failures_during_target', 'resolve_failed', 'status', 'error'])
            write_csv(overlaps_path, overlap_rows, ['id', 'title', 'brand', 'price_toman', 'url', 'category_count', 'categories'])
            write_csv(failures_path, scanner.failed, ['url', 'page', 'status', 'error'])
            lines = [
                'DIGIKALA UNIQUE PRODUCT SCAN — SUMMARY', '=' * 48,
                f'Started: {summary["started_at"]}', f'Ended: {summary["ended_at"]}',
                f'Elapsed: {summary["elapsed_seconds"]:.1f} seconds',
                f'Input category URLs: {summary["input_urls_count"]} (completed: {summary["input_urls_completed"]})',
                f'Subcategory targets: {summary["targets_scanned"]}',
                f'Targets OK / empty / error: {summary["targets_ok"]} / {summary["targets_empty"]} / {summary["targets_with_errors"]}',
                f'Globally unique eligible products: {summary["globally_unique_eligible_products"]:,}',
                f'Products found in multiple targets: {summary["products_found_in_multiple_targets"]:,}',
                f'Minimum price: {args.min_price:,} toman',
                f'Observed price min / average / max: {summary["product_price_min_toman"]} / {summary["product_price_average_toman"]} / {summary["product_price_max_toman"]}',
                f'API requests / success / failure: {scanner.pages:,} / {scanner.ok:,} / {scanner.fail:,}',
                f'Retry attempts: {scanner.retries_used:,}', f'Price slices: {scanner.slices:,}',
                'EVazar uploads: 0 (DISABLED)', '', 'FILES:',
                str(json_path), str(products_path), str(categories_path), str(targets_path), str(overlaps_path), str(failures_path),
                '', 'Note: Results reflect IDs actually observed from API responses; failed requests or API pagination/sorting limits may leave gaps.'
            ]
            text_path.write_text('\n'.join(lines) + '\n', encoding='utf-8')
        except OSError as exc:
            print(f'WARNING: Could not write all report files: {exc}')
        print('\n' + '=' * 76 + '\nFINAL REPORT\n' + '=' * 76)
        print(f'Input URLs: {len(urls)} | Completed: {len(url_reports)} | Targets: {len(target_reports)}')
        print(f'GLOBAL UNIQUE PRODUCTS: {len(global_ids):,} | Shared across targets: {len(overlap_rows):,}')
        print(f'API requests: {scanner.pages:,} | successful: {scanner.ok:,} | failed: {scanner.fail:,} | retries: {scanner.retries_used:,}')
        print(f'Elapsed: {elapsed:.1f}s | EVazar uploads: 0')
        print(f'Reports folder: {output_dir.resolve()}')
        print(f'Summary: {text_path}')
        print('=' * 76)

if __name__ == '__main__':
    main()
