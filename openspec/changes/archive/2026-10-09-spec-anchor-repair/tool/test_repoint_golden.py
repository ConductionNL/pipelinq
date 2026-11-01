#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""Golden test for repoint.py and resolver.py.

Builds one fixture app with a @spec tag for every shape the resolver knows,
runs repoint.main() without and with --apply, and compares the printed
summary, the JSON report, the dangling review list and every rewritten file
with golden.json. Set REPOINT_RECORD=1 to rewrite golden.json, then run once
more without it: a recording run compares nothing.

Run:  python3 test_repoint_golden.py   (exit 0 = pass)
"""
import contextlib
import io
import json
import os
import shutil
import sys
import tempfile
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import repoint  # noqa: E402

GOLDEN = os.path.join(HERE, 'golden.json')

FILES = {
    'openspec/specs/widgets/spec.md': (
        "# Widgets\n\n## Requirements\n\n"
        "### Requirement: Register widgets by slug\n\n"
        "### Plain heading\n\n### A b\n\n### Requirement: En dash title\n\n#### Scenario: listed\n"),
    'openspec/specs/gadgets/spec.md': "# Gadgets\n\n### Requirement: Gadget export\n",
    'openspec/specs/live-cap/spec.md': "# Live cap\n",
    'openspec/specs/sectioned/spec.md': "# Sectioned\n\n### Requirement: From the section\n",
    # A change that is still live (not archived).
    'openspec/changes/live-change/tasks.md': (
        "# Tasks\n\n"
        "- [x] task-1: widgets#REQ-001 — Register widgets by slug (retroactive annotation)\n"
        "- [ ] **task-2**: widgets#ABC-9 \"plain heading\" and more\n"
        "- [x] task-3: gadgets#lower — Gadget export (cross-cap: widgets)\n"
        "- [x] task-4: no capability token here\n"
        "  continued on the next line — Wrapped title\n"
        "\n"
        "- [x] task-5: widgets#REQ-002\n"
        "- [x] task-6: widgets#REQ-3 \"a\n"
        "\n"
        "b\"\n"
        "- [x] task-7: widgets#REQ-4 – En dash title\n"),
    'openspec/changes/archive/plain-archived/tasks.md': (
        "## sectioned\n\n"
        "- [x] task-1 \"From the section\"\n"
        "  - [x] task-2: widgets#REQ-3 — Register widgets by slug\n"
        "## gadgets\n"
        "- [x] task-3 — Gadget export\n"
        "# Heading ends the block\n"
        "- [x] task-4 (ghost) — Nothing (retroactive annotation)\n"),
    'openspec/archive/old-archive/tasks.md': "- [x] task-1: missing-cap#REQ-1 — Gone\n",
    'openspec/changes/archive/2026-01-01-dated/tasks.md': "- [x] task-1: live-cap#REQ-1 — No such heading\n",
    'openspec/archive/2026-02-02-dated-old/tasks.md': "- [x] task-1: widgets#REQ-1 \"Plain heading\"\n",
    'openspec/changes/archive/2026-03-03-twice/tasks.md': "- [x] task-1: widgets#REQ-1\n",
    'openspec/changes/archive/2026-03-04-twice/tasks.md': "- [x] task-1: widgets#REQ-1\n",
    'openspec/changes/live-cap/specs/live-cap/spec.md': "# delta\n",
    'openspec/changes/other/proposal.md': "# Proposal\n",
}

TAGS = [
    'openspec/changes/live-change/tasks.md#task-1',
    'openspec/changes/live-change/tasks.md#task-2',
    'openspec/changes/live-change/tasks.md#task-3',
    'openspec/changes/live-change/tasks.md#task-4',
    'openspec/changes/live-change/tasks.md#task-5',
    'openspec/changes/live-change/tasks.md#task-9',
    'openspec/changes/live-change/tasks.md#task-6',
    'openspec/changes/live-change/tasks.md#task-7',
    'openspec/changes/live-cap/tasks.md#',
    'openspec/changes/plain-archived/tasks.md#task-1',
    'openspec/changes/plain-archived/tasks.md#task-2',
    'openspec/changes/plain-archived/tasks.md#task-3',
    'openspec/changes/plain-archived/tasks.md#task-4',
    'openspec/changes/old-archive/tasks.md#task-1',
    'openspec/changes/dated/tasks.md#task-1',
    'openspec/changes/dated-old/tasks.md#task-1',
    'openspec/changes/twice/tasks.md#task-1',
    'openspec/changes/nowhere/tasks.md#task-1',
    'openspec/changes/live-cap/tasks.md',
    'openspec/changes/not-a-cap/tasks.md',
    'openspec/changes/live-change/tasks.md#notes',
    'openspec/changes/x/specs/widgets/spec.md',
    'openspec/changes/x/specs/widgets/spec.md#plain-heading',
    'openspec/changes/x/specs/widgets/spec.md#Plain-Heading',
    'openspec/changes/x/specs/widgets/spec.md#gone-anchor',
    'openspec/changes/x/specs/missing-cap/spec.md#a',
    'openspec/changes/other/design.md',
    'openspec/changes/other/proposal.md#nothing',
    'openspec/specs/widgets/spec.md#requirement-register-widgets-by-slug',
    'openspec/specs/widgets/spec.md',
    'openspec/specs/widgets/spec.md#vanished',
    'openspec/specs/dir.md#x',
    'openspec/changes/live-cap/specs/live-cap/spec.md#delta',
    'lib/Service/Widget.php#anything',
]


def _write(root, rel, content):
    path = os.path.join(root, rel)
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, 'w', encoding='utf-8', newline='') as f:
        f.write(content)


def build(root):
    for rel, content in FILES.items():
        _write(root, rel, content)
    os.makedirs(os.path.join(root, 'openspec/specs/dir.md'))
    for n in range(12, 21):
        _write(root, f'openspec/changes/live-change/filler-{n}.md', f'- [x] task-{n}\n')
    lines = ''.join(f' * @spec {tag}\n' for tag in TAGS[:20])
    _write(root, 'lib/Service/Widget.php', "<?php\r\n/**\r\n" + lines.replace('\n', '\r\n') + " */\r\nclass Widget {}\r\n")
    _write(root, 'lib/Controller/Clean.php', "<?php\n// @spec openspec/specs/widgets/spec.md\n")
    _write(root, 'src/views/Widget.vue', ''.join(f'// @spec {tag}\n' for tag in TAGS[20:]))
    _write(root, 'src/store/many.js', ''.join(
        f"// @spec openspec/changes/x/specs/missing-cap/spec.md#n{n}\n" for n in range(14)))
    _write(root, 'src/notes.md', "See @spec openspec/changes/live-cap/tasks.md in the notes\n")
    _write(root, 'src/skip.txt', "@spec openspec/changes/live-cap/tasks.md\n")
    for skipped in ('vendor', 'node_modules', 'dist', 'build'):
        _write(root, f'src/{skipped}/inner/x.js', "// @spec openspec/changes/live-cap/tasks.md\n")
    _write(root, 'src/dist/top.js', "// @spec openspec/changes/live-cap/tasks.md\n")
    _write(root, 'tests/Unit/x.php', "<?php // @spec openspec/changes/live-cap/tasks.md\n")


def run_main(root, apply):
    out = io.StringIO()
    argv = ['repoint.py', root] + (['--apply'] if apply else [])
    old_argv = sys.argv
    sys.argv = argv
    try:
        with contextlib.redirect_stdout(out):
            repoint.main()
    finally:
        sys.argv = old_argv
    base = os.path.join(HERE, os.path.basename(root))
    with open(base + '.report.json', encoding='utf-8') as f:
        report = json.load(f)
    with open(base + '.dangling.md', encoding='utf-8') as f:
        dangling = f.read()
    os.remove(base + '.report.json')
    os.remove(base + '.dangling.md')
    report['changed_files'] = sorted(os.path.relpath(p, root) for p in report['changed_files'])
    report['root'] = '<root>'
    return {'stdout': out.getvalue().replace(root, '<root>'), 'report': report,
            'dangling': dangling.replace(os.path.basename(root), '<root>')}


def snapshot(root):
    files = {}
    for dp, _dns, fns in os.walk(os.path.join(root, 'lib')):
        for fn in fns:
            with open(os.path.join(dp, fn), encoding='utf-8', newline='') as f:
                files[os.path.relpath(os.path.join(dp, fn), root)] = f.read()
    for dp, _dns, fns in os.walk(os.path.join(root, 'src')):
        for fn in fns:
            with open(os.path.join(dp, fn), encoding='utf-8', newline='') as f:
                files[os.path.relpath(os.path.join(dp, fn), root)] = f.read()
    return dict(sorted(files.items()))


def scenario():
    tmp = tempfile.mkdtemp(prefix='repoint-golden-')
    root = os.path.join(tmp, 'app')
    try:
        build(root)
        dry = run_main(root, False)
        unchanged = snapshot(root)
        applied = run_main(root, True)
        after = snapshot(root)
        again = run_main(root, True)
        bare = os.path.join(tmp, 'bare')
        os.makedirs(os.path.join(bare, 'lib'))
        _write(bare, 'lib/a.php', "<?php // @spec openspec/nothing.md\n")
        empty = run_main(bare, True)
        return {'dry': dry, 'dry_files': unchanged, 'apply': applied, 'apply_files': after,
                'second_apply': again, 'bare_app': empty}
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


class RepointGoldenTest(unittest.TestCase):
    def test_golden(self):
        actual = scenario()
        if os.environ.get('REPOINT_RECORD'):
            with open(GOLDEN, 'w', encoding='utf-8') as f:
                f.write(json.dumps(actual, indent=1, ensure_ascii=False) + '\n')
            return
        with open(GOLDEN, encoding='utf-8') as f:
            expected = json.load(f)
        for key in expected:
            with self.subTest(key):
                self.assertEqual(expected[key], actual[key])
        self.assertEqual(sorted(expected), sorted(actual))


if __name__ == '__main__':
    unittest.main()
