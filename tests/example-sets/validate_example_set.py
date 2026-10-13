#!/usr/bin/env python3
"""Statically validate an example-set fragment (openregister PR #4603 format) against
the schema definitions in this app's register files, without an instance.

Usage:
  python3 -I tests/example-sets/validate_example_set.py \
      --fragment lib/Settings/example-sets/zuiddrecht.json \
      --cast <openregister>/lib/Settings/example-sets/zuiddrecht.json \
      --settings lib/Settings --base lib/Settings/<app>_register.json[,more.json] \
      [--register <register slug>] [--no-fragments]

What it does, per object:
  * resolves every placeholder the loader knows ({{cast:k.path}}, {{date:n}},
    {{datetime:n HH:MM}}, {{object:key.path}} within this fragment only,
    {{lookup:name}}) and fails on any other placeholder or on one that resolves
    to nothing; an object reference gets a UUID stand-in, as the loader's check does;
  * merges the base register file and register.d overlays the way the app's
    SettingsService imports them (objects merge by key, lists concatenate);
  * validates the filled data with jsonschema against the merged schema
    (OpenRegister's `$ref` to a schema slug becomes a plain uuid string);
  * refuses a key the schema does not declare, because the loader's read-back
    proof exits 2 on a key the schema does not keep;
  * refuses an e-mail outside @zuiddrecht.example and a BSN-like or KVK-like
    literal that does not come from the cast;
  * checks that every fragment group member is a cast person with an account.
Exit 0 when clean, 1 otherwise. Stand-ins are not real values: a check here does
not replace `occ openregister:example-set:install <set> --check` on an instance.
"""
import argparse
import copy
import datetime as dt
import glob
import hashlib
import json
import os
import re
import sys
import uuid

import jsonschema

PLACEHOLDER = re.compile(r'\{\{([^{}]+)\}\}')
WHOLE = re.compile(r'^\{\{([^{}]+)\}\}$')
TODAY = dt.datetime(2026, 10, 13, tzinfo=dt.timezone(dt.timedelta(hours=2)))


def deep(base, over):
    for k, v in over.items():
        if k in base and isinstance(base[k], list) and isinstance(v, list):
            base[k] = base[k] + v
        elif k in base and isinstance(base[k], dict) and isinstance(v, dict):
            base[k] = deep(base[k], v)
        else:
            base[k] = copy.deepcopy(v)
    return base


def merged_schemas(settings, bases, fragments=True):
    files = list(bases)
    if fragments:
        files += sorted(glob.glob(os.path.join(settings, 'register.d', '*.json')))
    cfg = {}
    for f in files:
        cfg = deep(cfg, json.load(open(f, encoding='utf-8')))
    out = {}
    for key, s in cfg.get('components', {}).get('schemas', {}).items():
        out[s.get('slug', key)] = s
    return out, cfg


def to_jsonschema(node):
    """OpenRegister's `$ref` names a schema slug; for a stored value it is a uuid string."""
    if isinstance(node, list):
        return [to_jsonschema(n) for n in node]
    if not isinstance(node, dict):
        return node
    out = {}
    for k, v in node.items():
        if k == '$ref' and isinstance(v, str):
            continue
        if k in ('example', 'examples', 'default'):
            continue
        out[k] = to_jsonschema(v)
    if out.get('nullable') is True and isinstance(out.get('type'), str):
        out['type'] = [out['type'], 'null']
    return out


checker = jsonschema.FormatChecker()


@checker.checks('date', raises=ValueError)
def _date(v):
    if isinstance(v, str):
        dt.date.fromisoformat(v)
    return True


@checker.checks('date-time', raises=ValueError)
def _dt(v):
    if isinstance(v, str):
        if not re.match(r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:\d{2})$', v):
            raise ValueError('not RFC 3339')
        dt.datetime.fromisoformat(v.replace('Z', '+00:00'))
    return True


@checker.checks('uuid', raises=ValueError)
def _uuid(v):
    if isinstance(v, str):
        uuid.UUID(v)
    return True


@checker.checks('email', raises=ValueError)
def _email(v):
    if isinstance(v, str) and not re.match(r'^[^@\s]+@[^@\s]+\.[^@\s]+$', v):
        raise ValueError('not an e-mail address')
    return True


@checker.checks('uri', raises=ValueError)
def _uri(v):
    if isinstance(v, str) and not re.match(r'^[a-z][a-z0-9+.-]*:', v):
        raise ValueError('not a URI')
    return True


def stand_in(app, key):
    h = hashlib.md5((app + '/' + key).encode()).hexdigest()
    return '%s-%s-4%s-8%s-%s' % (h[0:8], h[8:12], h[13:16], h[17:20], h[20:32])


def path_in(entries, ref):
    parts = ref.split('.')
    if len(parts) < 2:
        return None
    here = entries
    for p in parts:
        if not isinstance(here, dict) or p not in here:
            return None
        here = here[p]
    if here in (None, '', []):
        return None
    return here


def moment(kind, rest):
    pat = r'^([+-]?\d{1,4})$' if kind == 'date' else r'^([+-]?\d{1,4}) ([01]\d|2[0-3]):([0-5]\d)$'
    m = re.match(pat, rest)
    if not m:
        return None
    day = TODAY + dt.timedelta(days=int(m.group(1)))
    if kind == 'date':
        return day.date().isoformat()
    return day.replace(hour=int(m.group(2)), minute=int(m.group(3)), second=0).isoformat()


def fill(value, ctx, gaps):
    if isinstance(value, dict):
        return {k: fill(v, ctx, gaps) for k, v in value.items()}
    if isinstance(value, list):
        return [fill(v, ctx, gaps) for v in value]
    if not isinstance(value, str) or '{{' not in value:
        return value
    w = WHOLE.match(value)
    if w:
        found = resolve(w.group(1).strip(), ctx)
        if found is None:
            gaps.append(value)
            return value
        return found

    def sub(m):
        found = resolve(m.group(1).strip(), ctx)
        if found is None or not isinstance(found, (str, int, float, bool)):
            gaps.append(m.group(0))
            return m.group(0)
        return str(found).lower() if isinstance(found, bool) else str(found)
    return PLACEHOLDER.sub(sub, value)


def resolve(ph, ctx):
    kind, _, rest = ph.partition(':')
    rest = rest.strip()
    if kind == 'cast':
        return path_in(ctx['cast'], rest)
    if kind == 'object':
        return path_in(ctx['objects'], rest)
    if kind == 'lookup':
        return ctx['lookups'].get(rest)
    if kind in ('date', 'datetime'):
        return moment(kind, rest)
    return None


def cast_entries(cast_file):
    cast = json.load(open(cast_file, encoding='utf-8'))['cast']
    entries = {}
    for part in ('people', 'organisations', 'groups'):
        for e in cast.get(part, []):
            entries[e['key']] = e
    return cast, entries


def literal_identifiers(raw_value, path, problems, where):
    """A BSN-like or KVK-like literal, or an e-mail outside the set's domain, in the declared data."""
    if isinstance(raw_value, dict):
        for k, v in raw_value.items():
            literal_identifiers(v, path + '.' + k, problems, where)
    elif isinstance(raw_value, list):
        for i, v in enumerate(raw_value):
            literal_identifiers(v, '%s[%d]' % (path, i), problems, where)
    elif isinstance(raw_value, str):
        bare = PLACEHOLDER.sub('', raw_value)
        if re.search(r'(?<![\d-])\d{8,9}(?![\d-])', bare) and re.search(r'bsn|burgerservice|kvk|identif', path, re.I):
            problems.append('%s: %s holds a literal identifier; use {{cast:...}}' % (where, path))
        for mail in re.findall(r'[\w.+-]+@[\w.-]+', bare):
            if not mail.lower().endswith('@zuiddrecht.example'):
                problems.append('%s: %s holds e-mail %s outside @zuiddrecht.example' % (where, path, mail))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--fragment', required=True)
    ap.add_argument('--cast', required=True)
    ap.add_argument('--settings', required=True)
    ap.add_argument('--base', required=True)
    ap.add_argument('--app', default=None)
    ap.add_argument('--register', action='append', default=[], help='register slug this app owns (repeatable); default: every register the files declare')
    ap.add_argument('--no-fragments', action='store_true')
    args = ap.parse_args()

    schemas, cfg = merged_schemas(args.settings, args.base.split(','), not args.no_fragments)
    registers = args.register or list(cfg.get('components', {}).get('registers', {}).keys())
    reg_schemas = {}
    for slug, reg in cfg.get('components', {}).get('registers', {}).items():
        reg_schemas[reg.get('slug', slug)] = set(reg.get('schemas', []))
    cast, entries = cast_entries(args.cast)
    fragment = json.load(open(args.fragment, encoding='utf-8'))
    app = args.app or os.path.basename(os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(args.fragment))))))
    problems = []
    warnings = []
    if 'cast' in fragment:
        problems.append('the fragment carries a cast; only openregister may')
    keys = set()
    ctx = {'cast': entries, 'objects': {}, 'lookups': {}}
    counts = {}
    for i, obj in enumerate(fragment.get('objects', [])):
        key = obj.get('key', '')
        where = '%s: object "%s"' % (os.path.basename(args.fragment), key)
        if not key or not obj.get('register') or not obj.get('schema') or not isinstance(obj.get('data'), dict):
            problems.append('object %d needs a key, a register, a schema and data' % i)
            continue
        if key in keys:
            problems.append('%s: key used twice' % where)
        keys.add(key)
        literal_identifiers(obj['data'], 'data', problems, where)
        gaps = []
        ctx['lookups'] = {}
        for name, lookup in (obj.get('lookups') or {}).items():
            if not lookup.get('schema') or not lookup.get('where'):
                problems.append('%s: lookup %s needs a schema and a where' % (where, name))
            fill(lookup.get('where', {}), ctx, gaps)
            ctx['lookups'][name] = stand_in('lookup', key + '/' + name)
        data = fill(obj['data'], ctx, gaps)
        for g in gaps:
            problems.append('%s: placeholder %s does not resolve' % (where, g))
        ctx['objects'][key] = dict(data, id=stand_in(app, key), uuid=stand_in(app, key))
        schema = schemas.get(obj['schema'])
        if obj['register'] in registers and schema is None:
            problems.append('%s: schema %s is not declared in %s' % (where, obj['schema'], args.base))
            continue
        if schema is None:
            problems.append('%s: register %s is not this app\'s; not validated here' % (where, obj['register']))
            continue
        known = reg_schemas.get(obj['register'])
        if known and obj['schema'] not in known and not any(schemas.get(s, {}).get('slug') == obj['schema'] for s in known):
            warnings.append('%s: schema %s is declared but not listed in register %s; the loader resolves it by slug, confirm with --check on an instance' % (where, obj['schema'], obj['register']))
        js = to_jsonschema({k: schema[k] for k in ('type', 'properties', 'required') if k in schema})
        js.setdefault('type', 'object')
        for err in sorted(jsonschema.Draft202012Validator(js, format_checker=checker).iter_errors(data), key=str):
            problems.append('%s: %s at %s' % (where, err.message, '/'.join(map(str, err.absolute_path)) or '(root)'))
        undeclared = sorted(set(data) - set(schema.get('properties', {})))
        if undeclared:
            problems.append('%s: keys the schema does not declare (the read-back proof would exit 2): %s' % (where, ', '.join(undeclared)))
        counts[(obj['register'], obj['schema'])] = counts.get((obj['register'], obj['schema']), 0) + 1
    for group in fragment.get('groups', []):
        if not group.get('group'):
            problems.append('a group entry has no gid')
        for m in group.get('members', []):
            if not entries.get(m, {}).get('uid'):
                problems.append('group %s: %s is not a cast person with an account' % (group.get('group'), m))
    for (reg, sch), n in sorted(counts.items()):
        print('%5d  %s %s' % (n, reg, sch))
    print('%5d  objects, %d groups' % (sum(counts.values()), len(fragment.get('groups', []))))
    for w in sorted(set(warnings)):
        print('WARNING', w)
    for p in problems:
        print('REFUSED', p)
    print('CLEAN' if not problems else '%d problem(s)' % len(problems))
    return 0 if not problems else 1


if __name__ == '__main__':
    sys.exit(main())
