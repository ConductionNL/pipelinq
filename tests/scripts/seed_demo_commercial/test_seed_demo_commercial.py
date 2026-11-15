# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""Golden tests for scripts/seed-demo-commercial.py.

Each scenario runs the script's main() against a fake OpenRegister (an
in-memory store behind a patched urlopen) at a fixed clock. Every HTTP
request (method, URL, timeout, body bytes; for the long scenarios a digest of
them), the request headers, every retry sleep, the printed output and the exit
are compared with golden.json. Set SEED_DEMO_RECORD=1 to
rewrite golden.json, then run once more without it: a recording run compares
nothing.

  python3 -m unittest discover -s tests/scripts/seed_demo_commercial -p 'test_*.py'
"""

import contextlib
import hashlib
import importlib.util
import io
import json
import os
import sys
import unittest
import urllib.error
from datetime import date, datetime
from pathlib import Path
from unittest import mock

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]
SCRIPT = ROOT / "scripts" / "seed-demo-commercial.py"
GOLDEN = HERE / "golden.json"
FULL = {"fresh seed, @self ids", "wipe then seed, deletes retried", "pos transaction without id"}
SCHEMAS = ["posTransactionLine", "posTransaction", "lead", "product", "productCategory", "client"]


def load_script():
    spec = importlib.util.spec_from_file_location("seed_demo_commercial", SCRIPT)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class FixedDate(date):
    moment = date(2026, 1, 31)

    @classmethod
    def today(cls):
        return cls.moment


class FixedDateTime(datetime):
    moment = datetime(2026, 1, 31, 10, 20, 30, 123456)

    @classmethod
    def now(cls, tz=None):
        return cls.moment


class SequentialPool:
    """Stands in for ThreadPoolExecutor so requests arrive in a fixed order."""

    def __init__(self, max_workers=None):
        self.max_workers = max_workers

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        return False

    def map(self, fn, items):
        return [fn(item) for item in items]


class Response:
    def __init__(self, body):
        self.body = body

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        return False

    def read(self):
        return self.body


class FakeRegister:
    """In-memory register behind urlopen; `failures` maps a request number to an error."""

    def __init__(self, store, failures=None, id_style="self"):
        self.store = {schema: list(rows) for schema, rows in store.items()}
        self.failures = failures or {}
        self.id_style = id_style
        self.calls = []
        self.headers = set()
        self.counter = 0

    def urlopen(self, req, timeout=None):
        number = len(self.calls)
        self.headers.add(json.dumps(dict(sorted(req.header_items()))))
        body = req.data.decode() if req.data is not None else ""
        self.calls.append(f"{req.get_method()} {req.full_url} t={timeout} {body}")
        failure = self.failures.get(number)
        if failure is not None:
            raise failure(req)
        return Response(self.answer(req))

    def answer(self, req):
        path = req.full_url.split("/api/objects/pipelinq/", 1)[1]
        method = req.get_method()
        if method == "GET":
            schema = path.split("?", 1)[0]
            if schema == "productCategory" and not self.store.get(schema):
                return b""
            key = "objects" if schema == "product" else "results"
            return json.dumps({key: self.store.get(schema, [])}).encode()
        if method == "DELETE":
            schema, oid = path.split("/", 1)
            self.store[schema] = [r for r in self.store.get(schema, []) if r.get("id") != oid]
            return b""
        self.counter += 1
        oid = f"{path}-{self.counter}"
        if self.id_style == "self":
            return json.dumps({"@self": {"id": oid}}).encode()
        return json.dumps({"id": oid}).encode()


def http_error(code, detail="boom"):
    def make(req):
        return urllib.error.HTTPError(req.full_url, code, "err", {}, io.BytesIO(detail.encode()))
    return make


def raised(exc):
    def make(req):
        return exc
    return make


def wipe_store():
    rows = {schema: [] for schema in SCHEMAS}
    rows["client"] = [
        {"id": "c1", "name": "Real client", "notes": "x"},
        {"id": "c2", "name": "DEMO-client"},
        {"@self": {"id": "c3"}, "description": "DEMO-desc"},
    ]
    rows["lead"] = [
        {"id": "l1", "title": "DEMO-Deal old"},
        {"id": "l2", "title": "Customer deal"},
        {"title": "DEMO-no id"},
    ]
    rows["product"] = [{"id": "p1", "sku": "DEMO-P1"}, {"id": "p2", "sku": "OTHER"}]
    rows["posTransaction"] = [{"id": "t1", "reference": "DEMO-POS-1"}, {"id": "t2", "reference": 7}]
    return rows


def scenarios():
    base = ["seed", "--url", "http://nc.test:8080/", "--user", "u", "--password", "p:w"]
    return {
        "fresh seed, @self ids": (base, lambda: FakeRegister({})),
        "fresh seed, plain ids, defaults": (["seed"], lambda: FakeRegister({}, id_style="plain")),
        "already seeded": (base, lambda: FakeRegister({"lead": [{"title": "DEMO-x"}, {"title": "DEMO-y"}]})),
        "not demo leads only": (base, lambda: FakeRegister({"lead": [{"title": "Other"}, {"title": 5}]})),
        "wipe then seed, deletes retried": (base + ["--wipe"], lambda: FakeRegister(
            wipe_store(), {4: http_error(404), 10: http_error(400), 11: http_error(400)})),
        "wipe nothing": (base + ["--wipe"], lambda: FakeRegister({}, {0: http_error(503), 1: http_error(502)})),
        "transient 5xx then success": (base, lambda: FakeRegister({}, {
            0: http_error(500), 2: http_error(503), 3: http_error(504), 4: http_error(502)})),
        "five server errors": (base, lambda: FakeRegister({}, {i: http_error(500, "x" * 400) for i in range(4)})),
        "client error aborts": (base, lambda: FakeRegister({}, {1: http_error(422, "bad payload")})),
        "network errors retried": (base, lambda: FakeRegister({}, {
            0: raised(urllib.error.URLError("down")), 1: raised(ConnectionError("reset")),
            2: raised(TimeoutError("slow")), 5: raised(OSError("io"))})),
        "network down": (base, lambda: FakeRegister({}, {i: raised(urllib.error.URLError("down")) for i in range(4)})),
        "pos transaction without id": (base, lambda: NoPosIds({})),
    }


class NoPosIds(FakeRegister):
    """Answers posTransaction creates without an id, so their lines are skipped."""

    def answer(self, req):
        if req.get_method() == "POST" and req.full_url.endswith("/posTransaction"):
            body = json.loads(req.data)
            if body["reference"].endswith("-01"):
                return b"{}"
            if body["reference"].endswith("-03"):
                return b'{"id": ""}'
        return super().answer(req)


def run(argv, register, full):
    module = load_script()
    sleeps = []
    out = io.StringIO()
    exit_code = None
    with contextlib.ExitStack() as stack:
        stack.enter_context(mock.patch.object(sys, "argv", argv))
        stack.enter_context(mock.patch("urllib.request.urlopen", register.urlopen))
        stack.enter_context(mock.patch("time.sleep", sleeps.append))
        stack.enter_context(mock.patch.object(module, "date", FixedDate))
        stack.enter_context(mock.patch.object(module, "datetime", FixedDateTime))
        stack.enter_context(mock.patch.object(module, "ThreadPoolExecutor", SequentialPool))
        stack.enter_context(contextlib.redirect_stdout(out))
        try:
            module.main()
        except SystemExit as stop:
            exit_code = stop.code
    calls = register.calls
    result = {"exit": exit_code, "stdout": out.getvalue(), "sleeps": sleeps, "headers": sorted(register.headers)}
    if full:
        return {**result, "calls": calls}
    digest = hashlib.sha256(json.dumps(calls).encode()).hexdigest()
    return {**result, "calls_count": len(calls), "calls_sha256": digest, "first_calls": calls[:6]}


class SeedDemoCommercialGoldenTest(unittest.TestCase):
    def test_golden(self):
        actual = {name: run(argv, factory(), name in FULL) for name, (argv, factory) in scenarios().items()}
        if os.environ.get("SEED_DEMO_RECORD"):
            GOLDEN.write_text(json.dumps(actual, indent=1, ensure_ascii=False) + "\n", encoding="utf-8")
            return
        expected = json.loads(GOLDEN.read_text(encoding="utf-8"))
        self.assertEqual(sorted(expected), sorted(actual))
        for name in expected:
            with self.subTest(name):
                self.assertEqual(expected[name], actual[name])


if __name__ == "__main__":
    unittest.main()
