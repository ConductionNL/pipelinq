#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction B.V.
# SPDX-License-Identifier: EUPL-1.2

"""Deterministic @spec anchor repointer (comment-only, gate-46-verifiable).

Usage:
  repoint.py <app-root> [--apply]

- Scans lib/ src/ for @spec openspec/... tags (same file set + regex as gate-46).
- Resolves each BROKEN tag via resolver.resolve().
- REPOINT_ANCHOR / REPOINT_FILE  -> rewritten in place (only when --apply).
- DANGLING                        -> written to review list, never guessed.
- Rewrite goes through the @spec TAG regex, so ONLY tag targets can change.
Outputs a JSON report next to this script (<root-basename>.report.json) and a
markdown review list (<root-basename>.dangling.md).
"""
import os, re, sys, json, collections
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import resolver

TAG = re.compile(r'@spec\s+(openspec/[^\s`\'"]+)')

def slugify(t): return resolver.slugify(t)

def heading_slugs(p): return resolver.heading_slugs(p)

SKIP_DIRS = ('/vendor/', '/node_modules/', '/dist/', '/build/')
EXTENSIONS = ('.php', '.vue', '.js', '.ts', '.md')
CAUSES = [
    ('has no canonical spec', 'C. capability archived/deleted — no canonical spec (requirement genuinely gone)'),
    ('not found in', 'D. change uses non-annotate tasks.md (no task-N: cap#REQ line) — needs spec delta read'),
    ('tasks.md not found', 'D. archived change dir not located'),
    ('unrecognised', 'D. non-tasks.md ref (decimal task / design.md / proposal.md / specs anchor re-headed)'),
    ('no-frag', 'D. no-fragment tasks.md ref, slug is not a canonical capability'),
    ('slug', 'D. no-fragment tasks.md ref, slug is not a canonical capability'),
]

def split_target(target):
    path, sep, frag = target.partition('#')
    return path, (frag if sep else None)

def _anchor_missing(absf, frag):
    return bool(frag and absf.endswith('.md') and slugify(frag) not in heading_slugs(absf))

def is_broken(root, target):
    path, frag = split_target(target)
    absf = os.path.join(root, path)
    return not os.path.exists(absf) or _anchor_missing(absf, frag), path, frag

def _wanted(dp, fns):
    if any(x in dp for x in SKIP_DIRS):
        return []
    return [os.path.join(dp, fn) for fn in fns if fn.endswith(EXTENSIONS)]

def iter_files(root):
    for base in ('lib', 'src'):
        bdir = os.path.join(root, base)
        if os.path.isdir(bdir):
            for dp, _dns, fns in os.walk(bdir):
                yield from _wanted(dp, fns)

def cause(note):
    if not note:
        return 'unknown'
    return next((label for needle, label in CAUSES if needle in note), 'other: ' + note)

def _read(fp):
    """The file's text with its original line endings, or None when unreadable."""
    try:
        # newline='' preserves original line endings (CRLF/BOM) so a rewrite
        # can never normalise them — comment-only edits must not touch bytes
        # outside the @spec target substring.
        with open(fp, encoding='utf-8', errors='replace', newline='') as _f:
            return _f.read()
    except OSError:
        return None

def _swap(mo, local):
    t = mo.group(1)
    if t in local:
        return mo.group(0).replace(t, local[t])
    return mo.group(0)


class Repoint:
    """One pass over an app: classifies every broken @spec tag and, when
    applying, rewrites the repointable ones in place."""

    def __init__(self, root, apply):
        self.root = root
        self.apply = apply
        self.cats = collections.Counter()
        self.files_changed = []
        self.dangling = []  # (file, target, note)

    def scan(self, fp):
        src = _read(fp)
        if src is None:
            return
        local = self._repoints(fp, src)  # old_target -> new_target
        if local and self.apply:
            self._rewrite(fp, (src, local))
        elif local:
            self.files_changed.append(fp)

    def _repoints(self, fp, src):
        local = {}
        for m in TAG.finditer(src):
            new_target = self._classify(fp, m.group(1))
            if new_target:
                local[m.group(1)] = new_target
        return local

    def _classify(self, fp, target):
        """The new target for a repointable broken tag; records everything else as dangling."""
        broken, path, frag = is_broken(self.root, target)
        if not broken:
            return None
        r = resolver.resolve(self.root, (path, frag))
        self.cats[r['category']] += 1
        if r['category'].startswith('REPOINT') and r['new_target']:
            return self._accept(fp, (target, r))
        self.dangling.append((fp, target, r['note']))
        return None

    def _accept(self, fp, found):
        # sanity: the new target MUST itself resolve (gate-green) before we accept it
        target, r = found
        if is_broken(self.root, r['new_target'])[0]:
            self.cats['REPOINT_REJECTED_STILL_BROKEN'] += 1
            self.cats[r['category']] -= 1
            self.dangling.append((fp, target, 'repoint candidate still broken: ' + r['new_target']))
            return None
        return r['new_target']

    def _rewrite(self, fp, edit):
        src, local = edit
        new = TAG.sub(lambda mo: _swap(mo, local), src)
        if new != src:
            with open(fp, 'w', encoding='utf-8', newline='') as _f:
                _f.write(new)
            self.files_changed.append(fp)

    def report(self):
        return {
            'root': self.root,
            'counts': dict(self.cats),
            'repoint_anchor': self.cats.get('REPOINT_ANCHOR', 0),
            'repoint_file': self.cats.get('REPOINT_FILE', 0),
            'dangling': len(self.dangling),
            'files_changed': len(self.files_changed),
        }

    def write_review(self, path):
        """The dangling review list, grouped by cause, largest group first."""
        by = collections.defaultdict(list)
        for fp, t, note in self.dangling:
            by[cause(note)].append((fp, t))
        with open(path, 'w') as g:
            g.write(f"# Residual dangling @spec anchors — {os.path.basename(self.root)}\n\n")
            g.write(f"{len(self.dangling)} anchors could not be repointed unambiguously and need human triage.\n\n")
            for group in sorted(by.items(), key=lambda kv: -len(kv[1])):
                g.write(self._group_text(group))

    def _group_text(self, group):
        note, items = group
        text = f"\n## {note} ({len(items)})\n"
        for fp, t in items[:15]:
            text += f"- `{os.path.relpath(fp, self.root)}` → `{t}`\n"
        if len(items) > 15:
            text += f"- … +{len(items)-15} more\n"
        return text


def main():
    root = os.path.abspath(sys.argv[1])
    run = Repoint(root, '--apply' in sys.argv[2:])
    for fp in iter_files(root):
        run.scan(fp)
    report = run.report()
    base = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                        os.path.basename(root))
    with open(base + '.report.json', 'w') as f:
        json.dump({**report, 'changed_files': run.files_changed}, f, indent=1)
    run.write_review(base + '.dangling.md')
    print(json.dumps(report, indent=1))

if __name__ == '__main__':
    main()
