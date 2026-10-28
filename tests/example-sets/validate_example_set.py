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
    the fields an object names in `verbatimFields` are left as declared, as the loader does;
  * merges the base register file and register.d overlays the way the app's
    SettingsService imports them (objects merge by key, lists concatenate);
  * validates the filled data with jsonschema against the merged schema
    (OpenRegister's `$ref` to a schema slug becomes a plain uuid string);
  * checks a `user` format value against the cast's accounts: the loader also
    accepts an account the instance happens to have, but a set can only rely
    on the accounts it writes itself;
  * refuses a key the schema does not declare, because the loader's read-back
    proof exits 2 on a key the schema does not keep;
  * refuses an e-mail outside the reserved .example top-level domain (RFC 2606)
    and a BSN-like or KVK-like literal that does not come from the cast;
  * warns about a number with more than 2 decimals, which an OpenRegister that
    stores numbers as DECIMAL(10,2) rounds;
  * checks that every lookup finds exactly one object the set declared before it,
    for a lookup in this app's own registers;
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
    # OpenRegister stores null for a property the schema does not require, as for an absent one.
    required = set(out.get('required') or [])
    for name, prop in (out.get('properties') or {}).items():
        if name not in required and isinstance(prop, dict) and isinstance(prop.get('type'), str):
            prop['type'] = [prop['type'], 'null']
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


# The accounts the set itself has: the cast people with a uid. OpenRegister's
# `user` format accepts an existing account or one the set is about to write;
# a set may only name its own.
CAST_UIDS = set()


@checker.checks('user', raises=ValueError)
def _user(v):
    if isinstance(v, str) and v.strip() not in CAST_UIDS:
        raise ValueError('not the uid of a cast account')
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


WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']


def moment(kind, rest, today=None):
    """`n`, `n@thu`, `n HH:MM`, `n@thu HH:MM`: n days from today, then the first such weekday on or after it."""
    wd = r'(?:@(mon|tue|wed|thu|fri|sat|sun))?'
    pat = r'^([+-]?\d{1,4})' + wd + '$' if kind == 'date' else r'^([+-]?\d{1,4})' + wd + r' ([01]\d|2[0-3]):([0-5]\d)$'
    m = re.match(pat, rest)
    if not m:
        return None
    day = (today or TODAY) + dt.timedelta(days=int(m.group(1)))
    if m.group(2):
        day = day + dt.timedelta(days=(WEEKDAYS.index(m.group(2)) - day.weekday()) % 7)
    if kind == 'date':
        return day.date().isoformat()
    return day.replace(hour=int(m.group(3)), minute=int(m.group(4)), second=0).isoformat()


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
        return moment(kind, rest, ctx.get('today'))
    if kind == 'organisation':
        return ctx['organisations'].get(rest)
    if kind == 'instance' and rest == 'baseUrl':
        return 'https://instance.example'
    return None


def cast_entries(cast_file):
    """The cast by key, with each person's and organisation's contactsUid filled the way
    the loader's catalogue does (`example-set-<set>-<key>` unless the cast declares one)."""
    whole = json.load(open(cast_file, encoding='utf-8'))
    cast = whole['cast']
    entries = {}
    for part in ('people', 'organisations', 'groups'):
        for e in cast.get(part, []):
            e = dict(e)
            if part != 'groups' and not isinstance(e.get('contactsUid'), str):
                e['contactsUid'] = 'example-set-%s-%s' % (whole.get('set', ''), e['key'])
            entries[e['key']] = e
    organisations = {}
    for o in cast.get('openregisterOrganisations', []) or whole.get('openregisterOrganisations', []) or []:
        for name in (o.get('slug'), o.get('name')):
            if name:
                organisations[name] = 'stand-in-organisation-' + str(o.get('slug') or o.get('name'))
    return cast, entries, organisations


def literal_identifiers(raw_value, path, problems, where):
    """A BSN-like or KVK-like literal, or an e-mail outside the reserved .example domain, in the declared data."""
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
            mail = mail.rstrip('.')
            if not mail.lower().endswith('.example'):
                problems.append('%s: %s holds e-mail %s outside the reserved .example domain' % (where, path, mail))


def finer_than_storage(data, properties, path=''):
    """The number fields whose value has more than 2 decimals.

    OpenRegister gives a schema `number` a DECIMAL(10,2) column (MagicMapper), so such a
    value comes back rounded and the loader reports it as written but not kept.
    """
    found = []
    for key, value in (data.items() if isinstance(data, dict) else []):
        prop = properties.get(key) or {}
        here = (path + '.' + key).lstrip('.')
        if prop.get('type') == 'number' and isinstance(value, float) and round(value, 2) != value:
            found.append(here)
        elif prop.get('type') == 'object' and isinstance(value, dict):
            found += finer_than_storage(value, prop.get('properties', {}), here)
    return found


def lookup_problem(where, name, lookup, looked_for, register, own_registers, declared, problems, warnings):
    """A lookup that finds no row, or more than one, among the objects the set declared before it.

    The loader looks in the instance first and then among the objects declared earlier
    (ExampleSetInstaller::lookedUp). A set cannot rely on rows of its own app's register that it
    does not write itself, so a miss there is a problem; a miss in another register is a warning,
    since that row may come from the other app.
    """
    if not isinstance(looked_for, dict) or not looked_for or not lookup.get('schema'):
        return
    source = lookup.get('register', register)
    found = [row for reg, schema, row in declared
             if reg == source and schema == lookup['schema']
             and all(row.get(field) == value for field, value in looked_for.items())]
    words = ', '.join('%s %r' % (field, value) for field, value in sorted(looked_for.items()))
    if len(found) > 1:
        problems.append('%s: lookup %s finds %d %s rows with %s; a lookup needs exactly one' % (where, name, len(found), lookup['schema'], words))
    elif not found and source in own_registers:
        problems.append('%s: lookup %s finds no %s declared before it with %s' % (where, name, lookup['schema'], words))
    elif not found:
        warnings.append('%s: lookup %s finds no %s %s in this set with %s; it must exist on the instance' % (where, name, source, lookup['schema'], words))


def overridden_declarations(fragment):
    """Changes that set a field the set's own object declares to another value.

    The read-back proof compares the object with its declaration, so such a
    field reads as written but not kept, and a refresh writes the declaration back.
    """
    problems = []
    raw = {o.get('key'): o for o in fragment.get('objects', []) if isinstance(o, dict) and isinstance(o.get('data'), dict)}
    for change in fragment.get('changes', []):
        where = change.get('where') or {}
        target = re.fullmatch(r'\{\{object:([^.}]+)\.(?:id|uuid)\}\}', str(where.get('id', '')))
        obj = raw.get(target.group(1)) if target and len(where) == 1 else None
        if obj is None or (obj.get('register'), obj.get('schema')) != (change.get('register'), change.get('schema')):
            continue
        for field, value in (change.get('set') or {}).items():
            if field in obj['data'] and obj['data'][field] != value:
                problems.append('change "%s": sets %s on object "%s", which declares %s itself; the read-back proof reports it written but not kept and a refresh writes it back, so change the declaration instead' % (change.get('key'), field, obj['key'], field))
    return problems


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
    cast, entries, organisations = cast_entries(args.cast)
    CAST_UIDS.update(e['uid'] for e in entries.values() if isinstance(e.get('uid'), str))
    fragment = json.load(open(args.fragment, encoding='utf-8'))
    app = args.app or os.path.basename(os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(args.fragment))))))
    problems = []
    warnings = []
    if 'cast' in fragment:
        problems.append('the fragment carries a cast; only openregister may')
    keys = set()
    ctx = {'cast': entries, 'objects': {}, 'lookups': {}, 'organisations': organisations}
    counts = {}
    declared = []
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
            looked_for = fill(lookup.get('where', {}), ctx, gaps)
            ctx['lookups'][name] = stand_in('lookup', key + '/' + name)
            lookup_problem(where, name, lookup, looked_for, obj['register'], registers, declared, problems, warnings)
        verbatim = {f for f in (obj.get('verbatimFields') or []) if isinstance(f, str)}
        data = dict(obj['data'], **fill({k: v for k, v in obj['data'].items() if k not in verbatim}, ctx, gaps))
        for g in gaps:
            problems.append('%s: placeholder %s does not resolve' % (where, g))
        ctx['objects'][key] = dict(data, id=stand_in(app, key), uuid=stand_in(app, key))
        declared.append((obj['register'], obj['schema'], ctx['objects'][key]))
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
        for field in finer_than_storage(data, schema.get('properties', {})):
            warnings.append('%s: %s has more than 2 decimals; an OpenRegister that stores a number as DECIMAL(10,2) rounds it' % (where, field))
        undeclared = sorted(set(data) - set(schema.get('properties', {})))
        if undeclared:
            problems.append('%s: keys the schema does not declare (the read-back proof would exit 2): %s' % (where, ', '.join(undeclared)))
        counts[(obj['register'], obj['schema'])] = counts.get((obj['register'], obj['schema']), 0) + 1
    problems.extend(overridden_declarations(fragment))
    for index, entry in enumerate(fragment.get('appConfig', [])):
        where = 'appConfig %d' % index
        if not isinstance(entry, dict):
            problems.append('%s is not an object' % where)
            continue
        if entry.get('app', app) != app:
            problems.append('%s sets config of app %s; a fragment sets only its own app' % (where, entry.get('app')))
        generate = entry.get('generate') is True
        if not isinstance(entry.get('key'), str) or entry['key'] == '' or (not generate and not isinstance(entry.get('value'), (str, int, float, bool))):
            problems.append('%s needs a key and a value, or generate: true' % where)
            continue
        if generate:
            continue
        gaps = []
        value = fill(entry['value'], ctx, gaps)
        for g in gaps:
            problems.append('%s (%s): placeholder %s does not resolve' % (where, entry['key'], g))
        print('       appConfig %s = %s' % (entry['key'], value))
    for group in fragment.get('groups', []):
        if not group.get('group'):
            problems.append('a group entry has no gid')
        if group.get('group') == 'admin':
            problems.append('group admin: a set never adds anyone to Nextcloud administrators')
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
