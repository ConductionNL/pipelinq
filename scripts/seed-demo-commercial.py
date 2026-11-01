#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
# Copyright (C) 2026 Conduction B.V.
#
# Seed a coherent commercial demo story into the pipelinq register so the
# Commercial dashboard (revenue, pipeline funnel, win rate, average deal
# size, weighted forecast, top customers, product mix, deal tables)
# renders meaningfully on a fresh environment.
#
# The story: a small B2B software studio with a product catalogue across
# four categories, a sales pipeline of leads spread over the trailing
# year (won / lost / open across stages with realistic value and win
# probability), and point-of-sale transactions settled across the year
# with product-linked lines.
#
# All seeded objects carry the DEMO- prefix on a stable functional field,
# which makes the seed idempotent (re-running aborts unless --wipe) and
# fully removable (--wipe deletes only demo objects).
#
# Usage:
#   python3 scripts/seed-demo-commercial.py [--url http://localhost:8080]
#       [--user admin] [--password admin] [--wipe]
#
# @spec openspec/changes/commercial-dashboard/specs/commercial-dashboard/spec.md

import argparse
import base64
import json
import sys
import time
import urllib.error
import urllib.request
from collections import namedtuple
from concurrent.futures import ThreadPoolExecutor
from datetime import date, datetime, timedelta

REGISTER = 'pipelinq'
PREFIX = 'DEMO-'

CLIENTS = [
    ('DEMO-CL1', 'Noordzee Logistics B.V.', 'Logistics'),
    ('DEMO-CL2', 'Veldhoven Zorggroep', 'Healthcare'),
    ('DEMO-CL3', 'Brouwerij De Kromme Haring', 'Food & Beverage'),
    ('DEMO-CL4', 'Stadswerk Almere', 'Public sector'),
    ('DEMO-CL5', 'Helder Advocaten', 'Legal'),
    ('DEMO-CL6', 'Polderdijk Vastgoed', 'Real estate'),
    ('DEMO-CL7', 'Kustlicht Media', 'Media'),
    ('DEMO-CL8', 'TechVarus Solutions', 'Software'),
]

# (sku, name, category, unitPrice)
PRODUCTS = [
    ('DEMO-P1', 'Platform license — Starter', 'Licenses', 1200),
    ('DEMO-P2', 'Platform license — Business', 'Licenses', 3600),
    ('DEMO-P3', 'Platform license — Enterprise', 'Licenses', 9000),
    ('DEMO-P4', 'Implementation sprint', 'Services', 7500),
    ('DEMO-P5', 'Data migration', 'Services', 4200),
    ('DEMO-P6', 'Premium support (year)', 'Support', 2400),
    ('DEMO-P7', 'Standard support (year)', 'Support', 1200),
    ('DEMO-P8', 'Training day', 'Training', 1500),
    ('DEMO-P9', 'Onboarding workshop', 'Training', 950),
]

STAGES = [
    ('Nieuw', 1, 10),
    ('Gekwalificeerd', 2, 30),
    ('Voorstel', 3, 55),
    ('Onderhandeling', 4, 80),
]

# Deterministic per-month multiplier so the year has shape.
SEASONALITY = [0.85, 0.9, 1.05, 1.15, 1.0, 0.8, 0.95, 1.2, 1.1, 1.0, 1.25, 1.3]

# Wipe order: children before the objects they reference.
WIPE_ORDER = ['posTransactionLine', 'posTransaction', 'lead', 'product', 'productCategory', 'client']

BASKETS = [
    ['DEMO-P1', 'DEMO-P6'],
    ['DEMO-P2', 'DEMO-P4'],
    ['DEMO-P8', 'DEMO-P9'],
    ['DEMO-P3', 'DEMO-P5', 'DEMO-P6'],
    ['DEMO-P7'],
    ['DEMO-P2', 'DEMO-P8'],
]

PRODUCT_BY_SKU = {p[0]: p for p in PRODUCTS}

# The dev container drops or 5xx-es the odd request under bursty load, so
# transient failures are retried after these delays. 4xx are real payload
# errors and abort.
RETRY_DELAYS = (2, 5, 10, 0)
TRANSPORT_ERRORS = (urllib.error.URLError, ConnectionError, TimeoutError, OSError)

# One HTTP call against the register's object API.
Call = namedtuple('Call', 'method path body')

# What the lead and POS builders share: client ids in CLIENTS order, product
# ids by SKU, and the moment the seed runs.
Story = namedtuple('Story', 'clients products today now')


def basic_token(user, password):
    return base64.b64encode(f'{user}:{password}'.encode()).decode()


class Api:
    def __init__(self, base, token):
        self.base = base.rstrip('/')
        self.headers = {
            'Authorization': f'Basic {token}',
            'Content-Type': 'application/json',
            'OCS-APIRequest': 'true',
            'Accept': 'application/json',
        }

    def url(self, path):
        return f'{self.base}/index.php/apps/openregister/api/objects/{REGISTER}/{path}'

    def send(self, call):
        for delay in RETRY_DELAYS:
            try:
                return self._open(call)
            except TRANSPORT_ERRORS as error:
                message, retryable = self._failure(call, error)
            if not (retryable and delay):
                raise SystemExit(message)
            time.sleep(delay)

    def _open(self, call):
        data = json.dumps(call.body).encode() if call.body is not None else None
        req = urllib.request.Request(self.url(call.path), data=data, headers=self.headers, method=call.method)
        with urllib.request.urlopen(req, timeout=120) as response:
            return json.loads(response.read() or b'{}')

    def _failure(self, call, error):
        """The abort message for a failed attempt, and whether to retry it."""
        where = f'{call.method} {self.url(call.path)}'
        if isinstance(error, urllib.error.HTTPError):
            detail = error.read().decode(errors='replace')[:300]
            return f'{where} -> HTTP {error.code}: {detail}', error.code >= 500
        return f'{where} -> {type(error).__name__}: {error}', True

    def list(self, schema):
        rows = self.send(Call('GET', f'{schema}?_limit=5000', None))
        return rows.get('results') or rows.get('objects') or []

    def create(self, schema, obj):
        return self.send(Call('POST', schema, obj))

    def delete(self, schema, object_id):
        return self.send(Call('DELETE', f'{schema}/{object_id}', None))


def object_id(row):
    return (row.get('@self') or {}).get('id') or row.get('id')


def is_demo(row):
    for key in ('reference', 'sku', 'title', 'name', 'description'):
        if str(row.get(key, '')).startswith(PREFIX):
            return True
    return False


def iso(dt):
    return dt.replace(microsecond=0).isoformat()


def delete_twice(api, target):
    """Delete (schema, id), with one retry; True when a delete went through."""
    for _attempt in range(2):
        try:
            api.delete(*target)
            return True
        except SystemExit:
            continue
    return False


def wipe_schema(api, schema):
    """Delete the demo rows of one schema; returns (deleted, failed)."""
    demo = list(filter(is_demo, api.list(schema)))
    ids = list(filter(None, map(object_id, demo)))
    deleted = [oid for oid in ids if delete_twice(api, (schema, oid))]
    if demo:
        print(f'  wiped {len(demo):4d} × {schema}')
    return len(deleted), len(ids) - len(deleted)


def wipe(api):
    results = [wipe_schema(api, schema) for schema in WIPE_ORDER]
    total = sum(deleted for deleted, _failed in results)
    failed = sum(failed for _deleted, failed in results)
    print(f'Wipe done ({total} objects' + (f', {failed} failed' if failed else '') + ').')


def create_all(api, batch):
    """Create the rows of a (schema, rows) batch, four at a time, in order."""
    schema, rows = batch
    with ThreadPoolExecutor(max_workers=4) as pool:
        return list(pool.map(lambda obj: api.create(schema, obj), rows))


def seed_clients(api):
    print(f'Seeding {len(CLIENTS):4d} × client ...')
    client_ids = {}
    for cid, name, industry in CLIENTS:
        created = api.create('client', {
            'name': name, 'type': 'organization', 'industry': industry,
            'email': cid.lower() + '@example.org',
            'notes': cid,
        })
        client_ids[cid] = object_id(created)
    return [client_ids[c[0]] for c in CLIENTS]


def seed_categories(api):
    """Product categories first: product.category is a UUID reference."""
    category_names = sorted({c[2] for c in PRODUCTS})
    print(f'Seeding {len(category_names):4d} × productCategory ...')
    category_ids = {}
    for order, cat in enumerate(category_names, start=1):
        created = api.create('productCategory', {
            'name': cat, 'description': f'{PREFIX}{cat} demo category', 'order': order,
        })
        category_ids[cat] = object_id(created)
    return category_ids


def seed_products(api, category_ids):
    print(f'Seeding {len(PRODUCTS):4d} × product ...')
    product_ids = {}
    for sku, name, category, price in PRODUCTS:
        created = api.create('product', {
            'name': name, 'sku': sku, 'unitPrice': price, 'cost': round(price * 0.4, 2),
            'category': category_ids[category],
            'type': 'service' if category in ('Services', 'Training', 'Support') else 'product',
            'status': 'active',
        })
        product_ids[sku] = object_id(created)
    return product_ids


def client_at(story, position):
    return story.clients[position % len(story.clients)]


def closing_month(story, month_back):
    """The anchor day (about month_back months ago) and its seasonality."""
    anchor = story.today - timedelta(days=30 * month_back)
    return anchor, SEASONALITY[(11 - month_back) % 12]


def won_lead(story, month_back):
    anchor, season = closing_month(story, month_back)
    close = iso(datetime(anchor.year, anchor.month, 12))
    return {
        'title': f'{PREFIX}Deal {anchor.strftime("%Y-%m")} — platform rollout',
        'client': client_at(story, month_back),
        'value': round((18000 + 1500 * (12 - month_back)) * season), 'probability': 100, 'status': 'won',
        'stage': 'Gewonnen', 'stageOrder': 5,
        'expectedCloseDate': close, 'stageEnteredAt': close,
        'source': 'Demo', 'priority': 'normal',
    }


def lost_lead(story, month_back):
    anchor, season = closing_month(story, month_back)
    close = iso(datetime(anchor.year, anchor.month, 24))
    return {
        'title': f'{PREFIX}Deal {anchor.strftime("%Y-%m")} — declined',
        'client': client_at(story, month_back + 3),
        'value': round(9000 * season), 'probability': 0, 'status': 'lost',
        'stage': 'Verloren', 'stageOrder': 6,
        'expectedCloseDate': close, 'stageEnteredAt': close,
        'source': 'Demo', 'priority': 'low',
    }


def quick_win(story, i):
    close = iso(story.now - timedelta(days=5 + i * 6))
    return {
        'title': f'{PREFIX}Quick win #{i + 1}',
        'client': client_at(story, i + 1),
        'value': round(12000 + i * 4000), 'probability': 100, 'status': 'won',
        'stage': 'Gewonnen', 'stageOrder': 5,
        'expectedCloseDate': close, 'stageEnteredAt': close,
        'source': 'Demo', 'priority': 'high',
    }


def open_lead(story, slot):
    """Open opportunity j of stage idx, closing in the future."""
    idx, j = slot
    stage, order, prob = STAGES[idx]
    return {
        'title': f'{PREFIX}{stage} opportunity {j + 1}',
        'client': client_at(story, idx * 3 + j),
        'value': round((20000 + 5000 * idx + 3000 * j)), 'probability': prob, 'status': 'open',
        'stage': stage, 'stageOrder': order,
        'expectedCloseDate': iso(story.now + timedelta(days=15 + 20 * idx + j * 5)),
        'stageEnteredAt': iso(story.now - timedelta(days=10 + j * 4)),
        'source': 'Demo', 'priority': 'normal',
    }


def build_leads(story):
    """Won and lost deals over the trailing year, recent quick wins, then the open pipeline."""
    leads = []
    for month_back in range(12):
        leads += [won_lead(story, month_back), lost_lead(story, month_back)]
    leads += [quick_win(story, i) for i in range(3)]
    leads += [open_lead(story, (idx, j)) for idx in range(len(STAGES)) for j in range(3)]
    return leads


def basket_line(story, item):
    """Line n of a basket: item is (n, sku, week)."""
    n, sku, week = item
    _sku, description, _category, price = PRODUCT_BY_SKU[sku]
    qty = 1 + (week + n) % 3
    return {
        'product': story.products[sku],
        'description': description,
        'quantity': qty, 'unitPrice': price, 'lineTotal': round(price * qty, 2),
        'taxRate': 21, 'sortOrder': n + 1,
    }


def pos_transaction(story, week):
    """The settled transaction of a week and its product lines."""
    settled = story.now - timedelta(days=7 * week + 2)
    basket = BASKETS[week % len(BASKETS)]
    lines = [basket_line(story, (n, sku, week)) for n, sku in enumerate(basket)]
    subtotal = sum((line['lineTotal'] for line in lines), 0.0)
    tax = round(subtotal * 0.21, 2)
    return {
        'reference': f'{PREFIX}POS-{settled.strftime("%Y%m%d")}-{week:02d}', 'cashier': 'admin', 'status': 'settled',
        'client': client_at(story, week),
        'tenderType': 'card', 'subtotal': round(subtotal, 2), 'totalTax': tax,
        'total': round(subtotal + tax, 2), 'priceMode': 'excl',
        'settledAt': iso(settled), 'confirmedAt': iso(settled),
    }, lines


def linked_lines(pos, created):
    """The lines of each created transaction, pointing at it; a transaction without an id drops its lines."""
    linked = []
    for (_txn, lines), txn_id in zip(pos, map(object_id, created)):
        if txn_id:
            linked += [{**line, 'transaction': txn_id} for line in lines]
    return linked


def seed_pos(api, story):
    """POS transactions settled across the year, then the lines of those that got an id."""
    pos = [pos_transaction(story, week) for week in range(40)]
    print(f'Seeding {len(pos):4d} × posTransaction ...')
    created = create_all(api, ('posTransaction', [txn for txn, _lines in pos]))
    pos_lines = linked_lines(pos, created)
    print(f'Seeding {len(pos_lines):4d} × posTransactionLine ...')
    create_all(api, ('posTransactionLine', pos_lines))
    return len(pos) + len(pos_lines)


def seed(api):
    today = date.today()
    now = datetime.now()
    clients = seed_clients(api)
    products = seed_products(api, seed_categories(api))
    story = Story(clients, products, today, now)
    leads = build_leads(story)
    print(f'Seeding {len(leads):4d} × lead ...')
    create_all(api, ('lead', leads))
    total = len(CLIENTS) + len(PRODUCTS) + len(leads) + seed_pos(api, story)
    print(f'Done — {total} objects seeded into register "{REGISTER}". '
          f'Open the Commercial dashboard.')


def parse_args():
    parser = argparse.ArgumentParser(description='Seed pipelinq commercial demo data.')
    parser.add_argument('--url', default='http://localhost:8080')
    parser.add_argument('--user', default='admin')
    parser.add_argument('--password', default='admin')
    parser.add_argument('--wipe', action='store_true', help='Delete previously seeded demo objects first')
    return parser.parse_args()


def main():
    args = parse_args()
    api = Api(args.url, basic_token(args.user, args.password))
    if args.wipe:
        wipe(api)
    existing = [o for o in api.list('lead') if str(o.get('title', '')).startswith(PREFIX)]
    if existing:
        print(f'Demo data already present ({len(existing)} DEMO leads). Use --wipe to reseed.')
        sys.exit(0)
    seed(api)


if __name__ == '__main__':
    main()
