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


class Api:
    def __init__(self, base, user, password):
        self.base = base.rstrip('/')
        token = base64.b64encode(f'{user}:{password}'.encode()).decode()
        self.headers = {
            'Authorization': f'Basic {token}',
            'Content-Type': 'application/json',
            'OCS-APIRequest': 'true',
            'Accept': 'application/json',
        }

    def request(self, method, path, body=None, params=''):
        url = f'{self.base}/index.php/apps/openregister/api/objects/{REGISTER}/{path}{params}'
        data = json.dumps(body).encode() if body is not None else None
        # The dev container drops or 5xx-es the odd request under bursty
        # load; retry transient failures with backoff. 4xx are real
        # payload errors and abort.
        for delay in (2, 5, 10, 0):
            req = urllib.request.Request(url, data=data, headers=self.headers, method=method)
            try:
                with urllib.request.urlopen(req, timeout=120) as response:
                    return json.loads(response.read() or b'{}')
            except urllib.error.HTTPError as e:
                detail = e.read().decode(errors='replace')[:300]
                if e.code >= 500 and delay:
                    time.sleep(delay)
                    continue
                raise SystemExit(f'{method} {url} -> HTTP {e.code}: {detail}')
            except (urllib.error.URLError, ConnectionError, TimeoutError, OSError) as e:
                if delay:
                    time.sleep(delay)
                    continue
                raise SystemExit(f'{method} {url} -> {type(e).__name__}: {e}')

    def list(self, schema):
        rows = self.request('GET', schema, params='?_limit=5000')
        return rows.get('results') or rows.get('objects') or []

    def create(self, schema, obj):
        return self.request('POST', schema, body=obj)

    def delete(self, schema, object_id):
        return self.request('DELETE', f'{schema}/{object_id}')


def object_id(row):
    return (row.get('@self') or {}).get('id') or row.get('id')


def is_demo(row):
    for key in ('reference', 'sku', 'title', 'name', 'description'):
        if str(row.get(key, '')).startswith(PREFIX):
            return True
    return False


def iso(dt):
    return dt.replace(microsecond=0).isoformat()


def wipe(api):
    total = 0
    failed = 0
    for schema in ['posTransactionLine', 'posTransaction', 'lead', 'product', 'productCategory', 'client']:
        demo = [o for o in api.list(schema) if is_demo(o)]
        for obj in demo:
            oid = object_id(obj)
            if not oid:
                continue
            try:
                api.delete(schema, oid)
                total += 1
            except SystemExit:
                try:
                    api.delete(schema, oid)
                    total += 1
                except SystemExit:
                    failed += 1
        if demo:
            print(f'  wiped {len(demo):4d} × {schema}')
    print(f'Wipe done ({total} objects' + (f', {failed} failed' if failed else '') + ').')


def main():
    parser = argparse.ArgumentParser(description='Seed pipelinq commercial demo data.')
    parser.add_argument('--url', default='http://localhost:8080')
    parser.add_argument('--user', default='admin')
    parser.add_argument('--password', default='admin')
    parser.add_argument('--wipe', action='store_true', help='Delete previously seeded demo objects first')
    args = parser.parse_args()

    api = Api(args.url, args.user, args.password)

    if args.wipe:
        wipe(api)

    existing = [o for o in api.list('lead') if str(o.get('title', '')).startswith(PREFIX)]
    if existing:
        print(f'Demo data already present ({len(existing)} DEMO leads). Use --wipe to reseed.')
        sys.exit(0)

    today = date.today()
    now = datetime.now()

    # ---- Clients (create first so leads/POS can reference their ids). ----
    print(f'Seeding {len(CLIENTS):4d} × client ...')
    client_ids = {}
    for cid, name, industry in CLIENTS:
        created = api.create('client', {
            'name': name, 'type': 'organization', 'industry': industry,
            'email': cid.lower() + '@example.org',
            'notes': cid,
        })
        client_ids[cid] = object_id(created)

    # ---- Product categories (product.category is a UUID reference). ----
    category_names = sorted({c[2] for c in PRODUCTS})
    print(f'Seeding {len(category_names):4d} × productCategory ...')
    category_ids = {}
    for order, cat in enumerate(category_names, start=1):
        created = api.create('productCategory', {
            'name': cat, 'description': f'{PREFIX}{cat} demo category', 'order': order,
        })
        category_ids[cat] = object_id(created)

    # ---- Products. ----
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

    client_list = [c[0] for c in CLIENTS]

    # ---- Leads spread across the trailing year. ----
    leads = []
    # Won + lost (closed) deals, two per month, value scaled by seasonality.
    for month_back in range(12):
        first = (today.replace(day=1) - timedelta(days=1))
        # Anchor each batch ~ (month_back) months ago, on the 12th + 24th.
        anchor = today - timedelta(days=30 * month_back)
        season = SEASONALITY[(11 - month_back) % 12]
        won_value = round((18000 + 1500 * (12 - month_back)) * season)
        lost_value = round(9000 * season)
        won_close = datetime(anchor.year, anchor.month, min(12, 28))
        lost_close = datetime(anchor.year, anchor.month, min(24, 28))
        leads.append({
            'title': f'{PREFIX}Deal {anchor.strftime("%Y-%m")} — platform rollout',
            'client': client_ids[client_list[month_back % len(client_list)]],
            'value': won_value, 'probability': 100, 'status': 'won',
            'stage': 'Gewonnen', 'stageOrder': 5,
            'expectedCloseDate': iso(won_close), 'stageEnteredAt': iso(won_close),
            'source': 'Demo', 'priority': 'normal',
        })
        leads.append({
            'title': f'{PREFIX}Deal {anchor.strftime("%Y-%m")} — declined',
            'client': client_ids[client_list[(month_back + 3) % len(client_list)]],
            'value': lost_value, 'probability': 0, 'status': 'lost',
            'stage': 'Verloren', 'stageOrder': 6,
            'expectedCloseDate': iso(lost_close), 'stageEnteredAt': iso(lost_close),
            'source': 'Demo', 'priority': 'low',
        })
    # A handful of extra won deals in the most recent two months for a strong trend.
    for i in range(3):
        close = now - timedelta(days=5 + i * 6)
        leads.append({
            'title': f'{PREFIX}Quick win #{i + 1}',
            'client': client_ids[client_list[(i + 1) % len(client_list)]],
            'value': round(12000 + i * 4000), 'probability': 100, 'status': 'won',
            'stage': 'Gewonnen', 'stageOrder': 5,
            'expectedCloseDate': iso(close), 'stageEnteredAt': iso(close),
            'source': 'Demo', 'priority': 'high',
        })

    # Open pipeline: several leads per stage with future close dates.
    for idx, (stage, order, prob) in enumerate(STAGES):
        for j in range(3):
            value = round((20000 + 5000 * idx + 3000 * j))
            close = now + timedelta(days=15 + 20 * idx + j * 5)
            entered = now - timedelta(days=10 + j * 4)
            leads.append({
                'title': f'{PREFIX}{stage} opportunity {j + 1}',
                'client': client_ids[client_list[(idx * 3 + j) % len(client_list)]],
                'value': value, 'probability': prob, 'status': 'open',
                'stage': stage, 'stageOrder': order,
                'expectedCloseDate': iso(close), 'stageEnteredAt': iso(entered),
                'source': 'Demo', 'priority': 'normal',
            })

    print(f'Seeding {len(leads):4d} × lead ...')
    with ThreadPoolExecutor(max_workers=4) as pool:
        list(pool.map(lambda obj: api.create('lead', obj), leads))

    # ---- POS transactions settled across the year, with product lines. ----
    pos = []
    lines_by_ref = {}
    basket_rotation = [
        ['DEMO-P1', 'DEMO-P6'],
        ['DEMO-P2', 'DEMO-P4'],
        ['DEMO-P8', 'DEMO-P9'],
        ['DEMO-P3', 'DEMO-P5', 'DEMO-P6'],
        ['DEMO-P7'],
        ['DEMO-P2', 'DEMO-P8'],
    ]
    for week in range(40):
        settled = now - timedelta(days=7 * week + 2)
        ref = f'{PREFIX}POS-{settled.strftime("%Y%m%d")}-{week:02d}'
        basket = basket_rotation[week % len(basket_rotation)]
        lines = []
        subtotal = 0.0
        for n, sku in enumerate(basket):
            price = next(p[3] for p in PRODUCTS if p[0] == sku)
            qty = 1 + (week + n) % 3
            line_total = round(price * qty, 2)
            subtotal += line_total
            lines.append({
                'product': product_ids[sku],
                'description': next(p[1] for p in PRODUCTS if p[0] == sku),
                'quantity': qty, 'unitPrice': price, 'lineTotal': line_total,
                'taxRate': 21, 'sortOrder': n + 1,
            })
        tax = round(subtotal * 0.21, 2)
        pos.append({
            'reference': ref, 'cashier': 'admin', 'status': 'settled',
            'client': client_ids[client_list[week % len(client_list)]],
            'tenderType': 'card', 'subtotal': round(subtotal, 2), 'totalTax': tax,
            'total': round(subtotal + tax, 2), 'priceMode': 'excl',
            'settledAt': iso(settled), 'confirmedAt': iso(settled),
        })
        lines_by_ref[ref] = lines

    print(f'Seeding {len(pos):4d} × posTransaction ...')
    pos_id_by_ref = {}
    with ThreadPoolExecutor(max_workers=4) as pool:
        def make(txn):
            created = api.create('posTransaction', txn)
            return txn['reference'], object_id(created)
        for ref, oid in pool.map(make, pos):
            pos_id_by_ref[ref] = oid

    pos_lines = []
    for ref, lines in lines_by_ref.items():
        txn_id = pos_id_by_ref.get(ref)
        if not txn_id:
            continue
        for line in lines:
            pos_lines.append({**line, 'transaction': txn_id})

    print(f'Seeding {len(pos_lines):4d} × posTransactionLine ...')
    with ThreadPoolExecutor(max_workers=4) as pool:
        list(pool.map(lambda obj: api.create('posTransactionLine', obj), pos_lines))

    total = len(CLIENTS) + len(PRODUCTS) + len(leads) + len(pos) + len(pos_lines)
    print(f'Done — {total} objects seeded into register "{REGISTER}". '
          f'Open the Commercial dashboard.')


if __name__ == '__main__':
    main()
