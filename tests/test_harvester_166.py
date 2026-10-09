import csv
import importlib.util
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

MODULE_PATH = Path(__file__).resolve().parents[1] / "digikala_harvester_1.6.6.py"
SPEC = importlib.util.spec_from_file_location("evazar_harvester_166", MODULE_PATH)
HARVESTER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(HARVESTER)


def make_items(count=100):
    return [
        {"dkp": str(100000 + i), "url": f"https://www.digikala.com/product/dkp-{100000 + i}/"}
        for i in range(count)
    ]


class HarvesterBatchingTests(unittest.TestCase):
    def reset_ingest_flags(self):
        HARVESTER.LAST_INGEST_UNCERTAIN = False
        HARVESTER.LAST_INGEST_FATAL = False
        HARVESTER.LAST_INGEST_HTTP_STATUS = None
        HARVESTER.LAST_INGEST_ERROR = ""

    def test_explicit_413_reduces_batch_100_to_50(self):
        self.reset_ingest_flags()
        sent_sizes = []

        def fake_push(items, *args, **kwargs):
            sent_sizes.append(len(items))
            if len(sent_sizes) == 1:
                HARVESTER.LAST_INGEST_UNCERTAIN = False
                HARVESTER.LAST_INGEST_FATAL = False
                HARVESTER.LAST_INGEST_HTTP_STATUS = 413
                HARVESTER.LAST_INGEST_ERROR = "HTTP 413 Payload Too Large"
                return False
            HARVESTER.LAST_INGEST_UNCERTAIN = False
            HARVESTER.LAST_INGEST_FATAL = False
            return {"success": True, "inserted": len(items), "skipped": 0, "requeued": 0}

        with patch.object(HARVESTER, "push_to_wordpress", side_effect=fake_push):
            result = HARVESTER.push_batch_adaptive(
                make_items(), "https://example.invalid/queue-ingest",
                100, 1, 5, 1, 1,
            )

        self.assertTrue(result)
        self.assertEqual(sent_sizes, [100, 50, 50])
        self.assertEqual(result["inserted"], 100)
        self.assertEqual(result["batch_size_used"], 50)

    def test_ambiguous_delivery_reconciles_before_resending(self):
        self.reset_ingest_flags()
        items = make_items()
        sent_sizes = []

        def fake_push(batch, *args, **kwargs):
            sent_sizes.append(len(batch))
            if len(sent_sizes) == 1:
                HARVESTER.LAST_INGEST_UNCERTAIN = True
                HARVESTER.LAST_INGEST_FATAL = False
                HARVESTER.LAST_INGEST_HTTP_STATUS = None
                HARVESTER.LAST_INGEST_ERROR = "Remote disconnected"
                return False
            HARVESTER.LAST_INGEST_UNCERTAIN = False
            HARVESTER.LAST_INGEST_FATAL = False
            return {"success": True, "inserted": len(batch), "skipped": 0, "requeued": 0}

        reserved = {item["dkp"] for item in items[:20]}
        with patch.object(HARVESTER, "push_to_wordpress", side_effect=fake_push), \
             patch.object(HARVESTER, "fetch_reserved_dkps", return_value=reserved):
            result = HARVESTER.push_batch_adaptive(
                items, "https://example.invalid/queue-ingest", 100, 1, 5, 1, 1
            )

        self.assertTrue(result)
        self.assertEqual(sent_sizes, [100, 50, 30])
        self.assertEqual(result["inserted"], 80)
        self.assertEqual(result["skipped"], 20)
        self.assertTrue(result["reconciled"])

    def test_resume_of_large_active_batch_uses_saved_small_chunks(self):
        self.reset_ingest_flags()
        items = make_items()
        sent_sizes = []
        reserved = {item["dkp"] for item in items[:20]}

        def fake_push(batch, *args, **kwargs):
            sent_sizes.append(len(batch))
            HARVESTER.LAST_INGEST_UNCERTAIN = False
            HARVESTER.LAST_INGEST_FATAL = False
            return {"success": True, "inserted": len(batch), "skipped": 0, "requeued": 0}

        with patch.object(HARVESTER, "push_to_wordpress", side_effect=fake_push), \
             patch.object(HARVESTER, "fetch_reserved_dkps", return_value=reserved):
            result = HARVESTER.push_batch_adaptive(
                items, "https://example.invalid/queue-ingest", 10, 1, 5, 2, 20,
                reconcile_first=True,
            )

        self.assertTrue(result)
        self.assertEqual(sent_sizes, [10] * 8)
        self.assertEqual(result["inserted"], 80)
        self.assertEqual(result["skipped"], 20)
        self.assertEqual(result["batch_size_used"], 10)

    def test_reports_write_json_and_recovery_csv(self):
        with tempfile.TemporaryDirectory() as tmp:
            reports = HARVESTER.save_detailed_reports(
                [{"dkp": "12345", "url": "https://www.digikala.com/product/dkp-12345/",
                  "title": "Test Product", "price_toman": 50000}],
                "https://www.digikala.com/search/category-test/",
                report_dir=tmp,
                page_cap=100,
            )
            for key in ("json", "summary", "products_csv", "targets_csv", "audits_csv", "failures_csv"):
                self.assertTrue(Path(reports[key]).is_file(), key)
            payload = json.loads(Path(reports["json"]).read_text(encoding="utf-8"))
            self.assertEqual(payload["summary"]["unique_new_products_ready_for_queue"], 1)
            with open(reports["products_csv"], encoding="utf-8-sig", newline="") as handle:
                rows = list(csv.DictReader(handle))
            self.assertEqual(rows[0]["dkp"], "12345")


if __name__ == "__main__":
    unittest.main(verbosity=2)
