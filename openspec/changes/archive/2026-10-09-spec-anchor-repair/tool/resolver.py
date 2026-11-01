#!/usr/bin/env python3
"""Deterministic @spec anchor repointer.

For a broken `@spec openspec/...` target, resolve the INTENDED canonical target
using ONLY unambiguous, verifiable signals, or classify it for human review.

Categories (task spec a/b/c/d):
  REPOINT_ANCHOR  : exact requirement-heading text match in canonical specs/<cap>/spec.md  -> new anchor
  REPOINT_FILE    : canonical specs/<cap>/spec.md exists, exact requirement heading not found -> file-level (cap proven, no fragment)
  DANGLING        : capability not recoverable / canonical spec file does not exist -> flag for human
Only REPOINT_* are auto-applied. DANGLING is never guessed.
"""
import os, re, glob as _glob

_TAG_SLUG = re.compile(r'[^a-z0-9]+')

def slugify(text):
    return _TAG_SLUG.sub('-', text.strip().lower()).strip('-')

def heading_slugs(md_path, _cache={}):
    if md_path in _cache:
        return _cache[md_path]
    hs = set()
    try:
        with open(md_path, encoding='utf-8', errors='replace') as f:
            for line in f:
                m = re.match(r'^\s*#{1,6}\s+(.+?)\s*$', line)
                if m:
                    hs.add(slugify(m.group(1)))
    except OSError:
        pass
    _cache[md_path] = hs
    return hs

_TASK_START = re.compile(r'^\s*-\s*\[.\]\s*\*{0,2}task-(\d+)\*{0,2}(?=[\s:(])')
_SECTION = re.compile(r'^\s*##\s+([a-z0-9][a-z0-9-]{2,})\s*$')
_CAP_TOK = re.compile(r'\b([a-z0-9][a-z0-9-]{2,})#')
_QUOTED = re.compile(r'"([^"]{4,})"')

def _strip_desc(desc):
    if not desc:
        return ''
    desc = re.sub(r'\s*\((?:retroactive annotation|cross-cap[^)]*)\)\s*$', '', desc)
    return desc.strip()

class _TaskBlocks:
    """Collects {tasknum: (block_text, section_cap_or_None)} from tasks.md lines,
    joining wrapped continuation lines."""

    def __init__(self):
        self.blocks = {}
        self.section = None
        self.cur = None
        self.cur_section = None
        self.buf = []

    def feed(self, line):
        sm = _SECTION.match(line)
        if sm and self.cur is None:
            self.section = sm.group(1)
        m = _TASK_START.match(line)
        if m:
            self.close()
            self.cur, self.cur_section, self.buf = int(m.group(1)), self.section, [line.strip()]
        elif self.cur is not None:
            self._continue(line)

    def _continue(self, line):
        if re.match(r'^\s*-\s*\[', line) or re.match(r'^\s*#', line):
            self.close()
            self.cur, self.buf = None, []
            sm = _SECTION.match(line)
            if sm:
                self.section = sm.group(1)
        elif line.strip():
            self.buf.append(line.strip())

    def close(self):
        if self.cur is not None:
            self.blocks[self.cur] = (' '.join(self.buf), self.cur_section)


def _task_blocks(tasks_path, _cache={}):
    """Return {tasknum: (block_text, section_cap_or_None)} for a tasks.md."""
    if tasks_path in _cache:
        return _cache[tasks_path]
    parser = _TaskBlocks()
    try:
        with open(tasks_path, encoding='utf-8', errors='replace') as f:
            lines = f.readlines()
    except OSError:
        lines = []
    for line in lines:
        parser.feed(line)
    parser.close()
    _cache[tasks_path] = parser.blocks
    return parser.blocks

_ARCHIVE_BASES = ('openspec/changes/archive', 'openspec/archive')

def _task_candidates(root, slug):
    return [
        os.path.join(root, 'openspec/changes', slug, 'tasks.md'),
        os.path.join(root, 'openspec/changes/archive', slug, 'tasks.md'),
        os.path.join(root, 'openspec/archive', slug, 'tasks.md'),
    ]

def _dated_hits(root, where):
    """tasks.md files under <base>/<YYYY-MM-DD>-<slug> for where = (base, slug)."""
    base, slug = where
    hits = sorted(_glob.glob(os.path.join(root, base, f'????-??-??-{slug}', 'tasks.md')))
    # require exact date-prefix + slug suffix (unambiguous)
    return [h for h in hits if re.fullmatch(r'\d{4}-\d{2}-\d{2}-' + re.escape(slug),
                                            os.path.basename(os.path.dirname(h)))]

def _find_archived_tasks(root, slug):
    """Locate the tasks.md for an archived/live change <slug>. Deterministic first,
    then a single unambiguous archive fallback by exact slug."""
    for c in _task_candidates(root, slug):
        if os.path.isfile(c):
            return c
    for base in _ARCHIVE_BASES:
        hits = _dated_hits(root, (base, slug))
        if len(hits) == 1:
            return hits[0]
    return None

def _cap_and_req(body, section):
    """(cap, reqid_or_None) from an inline cap#REQ token, else from the `## <cap>` section."""
    cm = _CAP_TOK.search(body)
    if not cm:
        return (section, None) if section else None
    rm = re.match(r'#(REQ-[A-Z0-9-]+|[A-Z0-9][A-Z0-9-]*)', body[cm.end() - 1:])
    return cm.group(1), (rm.group(1) if rm else None)

def _title(body):
    """The quoted string of a task line, else the text after an em/en dash."""
    qm = _QUOTED.search(body)
    if qm:
        return qm.group(1)
    dm = re.search(r'[—–]\s*(.+)$', body)
    return dm.group(1) if dm else ''

def _parse_task(tasks_path, tasknum):
    """Return (cap, reqid_or_None, desc_title) for task-N, or None."""
    entry = _task_blocks(tasks_path).get(tasknum)
    if not entry:
        return None
    block, section = entry
    # strip the leading "- [x] **task-N**:" marker
    body = _TASK_START.sub('', block, count=1).lstrip(' :').strip()
    found = _cap_and_req(body, section)
    if not found:
        return None
    cap, reqid = found
    return cap, reqid, _strip_desc(_title(body))

def _canonical_anchor(root, task):
    """Return (relpath, anchor_or_None, category) for a parsed (cap, reqid, desc) task."""
    cap, _reqid, desc = task
    rel = f'openspec/specs/{cap}/spec.md'
    absf = os.path.join(root, rel)
    if not os.path.isfile(absf):
        return None
    slugs = heading_slugs(absf)
    for cand in ((f'requirement-{slugify(desc)}', slugify(desc)) if desc else ()):
        if cand in slugs:
            return (rel, cand, 'REPOINT_ANCHOR')
    return (rel, None, 'REPOINT_FILE')

def _dangling(note):
    return {'category': 'DANGLING', 'new_target': None, 'note': note}

def _resolve_task(root, ref):
    """Shape 1: changes/<slug>/tasks.md#task-N, for ref = (slug, N)."""
    slug, tnum = ref
    tp = _find_archived_tasks(root, slug)
    if not tp:
        return _dangling(f'archived change {slug} tasks.md not found')
    parsed = _parse_task(tp, tnum)
    if not parsed:
        return _dangling(f'task-{tnum} not found in {slug}/tasks.md')
    res = _canonical_anchor(root, parsed)
    if res is None:
        return _dangling(f'capability {parsed[0]} has no canonical spec')
    rel, anchor, cat = res
    return {'category': cat, 'new_target': rel + (f'#{anchor}' if anchor else ''),
            'note': f'{parsed[0]}#{parsed[1]}'}

def _resolve_tasks_file(root, slug):
    """Shape 1b: changes/<slug>/tasks.md with NO task fragment, where <slug> is itself
    a canonical capability name -> file-level repoint (unambiguous: slug == cap verbatim)."""
    rel = f'openspec/specs/{slug}/spec.md'
    if os.path.isfile(os.path.join(root, rel)):
        return {'category': 'REPOINT_FILE', 'new_target': rel, 'note': f'slug==cap {slug}'}
    return _dangling(f'no-frag tasks.md, slug {slug} not a canonical cap')

def _resolve_tasks_ref(root, ref):
    """A changes/<slug>/tasks.md target, for ref = (slug, frag)."""
    slug, frag = ref
    if not frag:
        return _resolve_tasks_file(root, slug)
    if re.match(r'task-(\d+)$', frag):
        return _resolve_task(root, (slug, int(frag.split('-', 1)[1])))
    return _dangling('unrecognised shape')

def _resolve_delta(root, ref):
    """Shape 2: changes/<slug>/specs/<cap>/spec.md[#anchor], for ref = (cap, frag)."""
    cap, frag = ref
    rel = f'openspec/specs/{cap}/spec.md'
    absf = os.path.join(root, rel)
    if not os.path.isfile(absf):
        return _dangling(f'capability {cap} has no canonical spec')
    if not frag:
        return {'category': 'REPOINT_FILE', 'new_target': rel, 'note': cap}
    if slugify(frag) in heading_slugs(absf):
        return {'category': 'REPOINT_ANCHOR', 'new_target': f'{rel}#{frag}', 'note': cap}
    # fragment lost — capability proven, drop to file-level
    return {'category': 'REPOINT_FILE', 'new_target': rel, 'note': f'{cap} (anchor {frag} not in canonical)'}

def resolve(root, target):
    """Given a broken target = (path, frag) under app <root>, return a dict:
       {category, new_target|None, note}."""
    path, frag = target
    m = re.match(r'openspec/changes/([^/]+)/tasks\.md$', path)
    if m:
        return _resolve_tasks_ref(root, (m.group(1), frag))
    m = re.match(r'openspec/changes/([^/]+)/specs/([^/]+)/spec\.md$', path)
    if m:
        return _resolve_delta(root, (m.group(2), frag))
    # anything else (specs/* anchor-nf, design.md, proposal.md, OTHER) -> human review
    return _dangling('unrecognised shape')
