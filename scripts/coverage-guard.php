#!/usr/bin/env php
<?php
/**
 * Coverage guard — prevents test coverage from dropping.
 *
 * Usage:
 *   php scripts/coverage-guard.php <clover.xml> [--against=<clover.xml>]
 *   php scripts/coverage-guard.php <clover.xml> --against=<clover.xml> --changed-files=<list>
 *   php scripts/coverage-guard.php <clover.xml> --against=<clover.xml> --changed-files=<list> --deletion-neutral
 *   php scripts/coverage-guard.php <clover.xml> [--update-baseline]
 *   php scripts/coverage-guard.php --capabilities
 *
 * TWO FLOORS, AND ONLY ONE OF THEM IS AUTHORITATIVE
 *
 * `--against` names a clover report MEASURED at the merge base. When it is
 * given it is the ONLY floor: the committed `.coverage-baseline` is reported
 * for information and deliberately not enforced.
 *
 * That precedence is the whole fix. `.coverage-baseline` is a number a human
 * types, and against a base branch that moves there is no value a pull-request
 * author can commit that is guaranteed correct when it lands. Measured on
 * openregister: an author committed 58.93, `development` advanced from 16030
 * to 16038 tests while the PR sat, the merge result measured 58.88, and the
 * guard reported "dropped by 0.05%". Taking max(committed, measured) would
 * preserve exactly that failure, so the measured value does not merely win
 * ties — it replaces the committed one outright.
 *
 * A measured floor also disposes of a second trap: CI measures with xdebug and
 * local runs typically use pcov, and the two do not count statements
 * identically. With `--against`, both numbers come from the same driver in the
 * same job, so the difference cancels instead of being baked into a constant.
 *
 * Without `--against` the committed `.coverage-baseline` is used as a
 * conservative fail-safe floor. It is a floor and never an exact target: a
 * measurement ABOVE it is good news and exits 0. Demanding equality is what
 * made this gate unsatisfiable, because closing "the file is stale" required
 * committing the value the tree would measure after landing.
 *
 * Exit codes:
 *   0 — coverage is equal to or higher than the floor
 *   1 — coverage dropped (the change should be blocked)
 *   2 — missing, unparseable or empty input
 */

const CG_OK      = 0;
const CG_DROPPED = 1;
const CG_INPUT   = 2;

/**
 * Capabilities this script understands.
 *
 * The workflow probes this before relying on `--against`. An older copy of this
 * script accepts the flag silently and ignores it, which would downgrade the
 * ratchet to the committed-constant check while still reporting success — a
 * check that did not run looking exactly like one that passed. The probe turns
 * that into a loud failure.
 */
const CG_CAPABILITIES = ['against', 'update-baseline', 'capabilities', 'changed-files', 'deletion-neutral'];

/**
 * Bucket key used for statements that could not be attributed to a method.
 *
 * It is a real key, present on both sides, so a file that falls back is compared
 * exactly as it is today — conservatively, deletions included. It is never a way
 * for a file to disappear from the comparison.
 */
const CG_WHOLE_FILE = '<whole file>';

/**
 * One clover report and the label its messages use ("current", "merge-base").
 *
 * @spec exclude CI tooling shared by every app; it implements no app requirement.
 */
final class CgClover
{


    /**
     * @param string $file  Path of the clover report.
     * @param string $label Label for messages about this report.
     */
    public function __construct(
        public readonly string $file,
        public readonly string $label
    ) {
    }//end __construct()


}//end class

/**
 * The head and merge-base reports of a scoped comparison.
 *
 * @spec exclude CI tooling shared by every app; it implements no app requirement.
 */
final class CgSides
{


    /**
     * @param CgClover $head The head clover report.
     * @param CgClover $base The merge-base clover report.
     */
    public function __construct(
        public readonly CgClover $head,
        public readonly CgClover $base
    ) {
    }//end __construct()


}//end class

/**
 * The options of one run, read once from the command line.
 *
 * An option given without a value (`--against`) or with an empty one
 * (`--against=`) reads as absent.
 *
 * @spec exclude CI tooling shared by every app; it implements no app requirement.
 */
final class CgRun
{

    /**
     * The head clover report.
     *
     * @var CgClover
     */
    public readonly CgClover $head;

    /**
     * The merge-base clover report, null without `--against`.
     *
     * @var CgClover|null
     */
    public readonly ?CgClover $base;

    /**
     * The changed-file list, null without `--changed-files`.
     *
     * @var string|null
     */
    public readonly ?string $changedList;

    /**
     * Whether `--deletion-neutral` was given.
     *
     * @var boolean
     */
    public readonly bool $deletionNeutral;

    /**
     * Whether `--update-baseline` was given.
     *
     * @var boolean
     */
    public readonly bool $updateBaseline;

    /**
     * Path of the committed `.coverage-baseline`.
     *
     * @var string
     */
    public readonly string $baselineFile;


    /**
     * @param array<string,string|bool> $options Parsed `--key=value` options.
     * @param string                    $clover  Head clover report path.
     */
    public function __construct(array $options, string $clover)
    {
        $against               = cgOptionString($options, 'against');
        $this->head            = new CgClover($clover, 'current');
        $base                  = null;
        if ($against !== null) {
            $base = new CgClover($against, 'merge-base');
        }

        $this->base            = $base;
        $this->changedList     = cgOptionString($options, 'changed-files');
        $this->deletionNeutral = isset($options['deletion-neutral']);
        $this->updateBaseline  = isset($options['update-baseline']);
        $this->baselineFile    = (__DIR__ . '/../.coverage-baseline');
    }//end __construct()


}//end class

/**
 * Method buckets of one clover report, filled file entry by file entry.
 *
 * WHY BY METHOD NAME, AND WHY NOT BY LINE NUMBER.
 *
 * Clover identifies a statement only by its `num` — the line it sits on — and a
 * deletion shifts every line after the cut. On launchpad#128's file, 117 of the
 * 254 surviving statements (46%) sit at or after the deletion site, so matching
 * base statement `num=310` to head statement `num=310` compares two unrelated
 * lines across half the file and returns a confident, meaningless verdict. The
 * test suite measures that directly on its fixture: a line-number intersection
 * there reports 182/234 head against 189/234 base, i.e. a fabricated regression,
 * where method-name attribution reconciles exactly at 183/254 on both sides.
 * Method names survive the shift; line numbers do not.
 *
 * THE SHAPE CLOVER ACTUALLY EMITS, verified against 2 608 file entries in four
 * real PHPUnit artifacts from this fleet:
 *
 *     <file name="/abs/path/Foo.php">
 *       <class name="Foo"><metrics .../></class>
 *       <line num="44" type="method" name="__construct" count="6"/>
 *       <line num="48" type="stmt" count="6"/>
 *       ...
 *       <metrics statements="9" coveredstatements="9" methods="7" .../>
 *     </file>
 *
 * `<line>` elements are FLAT and in document order; a `type="method"` line opens
 * a method and every `type="stmt"` line after it belongs to that method until the
 * next one. `metrics/@statements` counts the `stmt` lines only — methods are
 * counted separately and `elements` is their sum — so summing attributed
 * statements reproduces the file metric wherever the line data is complete.
 *
 * The bucket key is the REPO-RELATIVE path that matched, never the clover
 * `name`. The two reports are produced from two different checkouts and their
 * absolute paths differ, so keying on `name` would put every head bucket and
 * every base bucket in disjoint namespaces and the intersection would be empty —
 * which reads as "nothing to compare", i.e. a pass.
 *
 * TWO DEGENERATE SHAPES, BOTH HANDLED FAIL-CLOSED:
 *
 *  - A file whose metrics declare statements but which carries NO usable line
 *    data (the `processUncoveredFiles` shape: PHPUnit counts a file it never
 *    loaded). Attribution would measure it as 0/0 — an invisible pass on exactly
 *    the file most likely to be untested. Such a file falls back to a single
 *    CG_WHOLE_FILE bucket carrying its file-level metrics, the fallback is
 *    printed by name, and cgCollapseFiles() then puts BOTH sides on the same
 *    footing so the fallback cannot be mistaken for a deletion.
 *  - Two methods of the same name in one file (two classes in one file, in
 *    principle). Their statements are SUMMED into one bucket rather than
 *    disambiguated by ordinal, because ordinals shift when one of them is
 *    deleted. Summing keeps the bucket in the intersection, so deleting one of a
 *    same-named pair is still charged against the change. Not seen in any of the
 *    2 608 entries surveyed; handled so it cannot become a silent hole.
 *
 * @spec exclude CI tooling shared by every app; it implements no app requirement.
 */
final class CgAttribution
{

    /**
     * Buckets keyed "<path>::<method>".
     *
     * @var array<string,array{0:int,1:int}>
     */
    public array $buckets = [];

    /**
     * Repo-relative paths this report matched.
     *
     * @var array<int,string>
     */
    public array $matched = [];

    /**
     * Paths that had to fall back to file-level metrics.
     *
     * @var array<int,string>
     */
    public array $fellBack = [];


    /**
     * @param string $label Label for messages about this report.
     */
    public function __construct(private readonly string $label)
    {
    }//end __construct()


    /**
     * Add one matched clover `<file>` entry to the buckets.
     *
     * @param SimpleXMLElement $entry Clover `<file>` entry.
     * @param string           $path  Repo-relative path the entry matched.
     *
     * @return void
     *
     * @spec exclude CI tooling shared by every app; it implements no app requirement.
     */
    public function addEntry(SimpleXMLElement $entry, string $path): void
    {
        $this->matched[] = $path;

        foreach ($this->entryBuckets(entry: $entry, path: $path) as $key => $pair) {
            $this->buckets[$key] = cgAddPair(($this->buckets[$key] ?? [0, 0]), $pair);
        }
    }//end addEntry()


    /**
     * Drop repeated paths, keeping the first occurrence of each.
     *
     * @spec exclude CI tooling shared by every app; it implements no app requirement.
     *
     * @return void
     */
    public function dedupe(): void
    {
        $this->matched  = array_values(array_unique($this->matched));
        $this->fellBack = array_values(array_unique($this->fellBack));
    }//end dedupe()


    /**
     * The buckets of one entry, reconciled against its declared statement count.
     *
     * @param SimpleXMLElement $entry Clover `<file>` entry.
     * @param string           $path  Repo-relative path the entry matched.
     *
     * @return array<string,array{0:int,1:int}>
     */
    private function entryBuckets(SimpleXMLElement $entry, string $path): array
    {
        $local      = cgLineBuckets($entry, $path);
        $statements = cgSumBuckets($local)[0];
        $declared   = (int) $entry->metrics['statements'];

        if ($statements === 0 && $declared > 0) {
            return $this->fallBack(entry: $entry, path: $path);
        }

        if ($statements !== $declared) {
            echo "    note: {$this->label} report declares {$declared} statement(s) for {$path} but emits "
                . "{$statements} attributable line(s); the attributable ones are what is compared.\n";
        }

        return $local;
    }//end entryBuckets()


    /**
     * No usable line data. Measuring 0/0 here would drop the file out of the
     * comparison entirely, so fall back to the file metric and say so.
     *
     * @param SimpleXMLElement $entry Clover `<file>` entry.
     * @param string           $path  Repo-relative path the entry matched.
     *
     * @return array<string,array{0:int,1:int}>
     */
    private function fallBack(SimpleXMLElement $entry, string $path): array
    {
        $this->fellBack[] = $path;
        $declared         = (int) $entry->metrics['statements'];

        echo "    note: {$this->label} report carries no line data for {$path}; "
            . "falling back to its file-level metric ({$entry->metrics['coveredstatements']}/{$declared}).\n";

        return [($path . '::' . CG_WHOLE_FILE) => [$declared, (int) $entry->metrics['coveredstatements']]];
    }//end fallBack()


}//end class

/**
 * Print an input error and stop with CG_INPUT.
 *
 * @param string $message Error text, newline included.
 *
 * @return never
 */
function cgFail(string $message): never
{
    fwrite(STDERR, $message);
    exit(CG_INPUT);
}//end cgFail()

/**
 * Stop with CG_INPUT when a clover report does not exist.
 *
 * @param CgClover $report Clover report to read.
 *
 * @return void
 */
function cgRequireFile(CgClover $report): void
{
    if (file_exists($report->file) === false) {
        cgFail("Error: {$report->label} clover file not found: {$report->file}\n");
    }
}//end cgRequireFile()

/**
 * Load a clover report, stopping with CG_INPUT when it is missing or unparseable.
 *
 * @param CgClover $report Clover report to read.
 *
 * @return SimpleXMLElement
 */
function cgOpenReport(CgClover $report): SimpleXMLElement
{
    cgRequireFile($report);

    $xml = @simplexml_load_file($report->file);
    if ($xml === false) {
        cgFail("Error: could not parse {$report->label} report {$report->file}\n");
    }

    return $xml;
}//end cgOpenReport()

/**
 * The first wanted repo-relative path the clover file name ends with.
 *
 * Matching is by path SUFFIX. Clover records absolute paths from the machine
 * that produced it, and the two reports here are produced from two different
 * checkouts, so absolute equality would match nothing and silently measure zero.
 *
 * @param string            $name Clover file name.
 * @param array<int,string> $only Repo-relative paths to include.
 *
 * @return ?string
 */
function cgMatchPath(string $name, array $only): ?string
{
    if ($name === '') {
        return null;
    }

    foreach ($only as $wanted) {
        if (str_ends_with($name, $wanted) === true) {
            return $wanted;
        }
    }

    return null;
}//end cgMatchPath()

/**
 * Percentage of a [statements, covered] pair, 0.0 when there are no statements.
 *
 * @param array{0:int,1:int} $pair A [statements, covered] pair.
 *
 * @return float
 */
function cgPercent(array $pair): float
{
    if ($pair[0] > 0) {
        return round((($pair[1] / $pair[0]) * 100), 2);
    }

    return 0.0;
}//end cgPercent()

/**
 * Sum two [statements, covered] pairs.
 *
 * @param array{0:int,1:int} $left  First [statements, covered] pair.
 * @param array{0:int,1:int} $right Second [statements, covered] pair.
 *
 * @return array{0:int,1:int}
 */
function cgAddPair(array $left, array $right): array
{
    return [($left[0] + $right[0]), ($left[1] + $right[1])];
}//end cgAddPair()

/**
 * Sum clover metrics for a named subset of files.
 *
 * WHY THIS EXISTS. Comparing the project-wide aggregate made the ratchet fire on
 * measurement noise. Measured on doriath#240 — a pull request whose entire diff
 * was `webpack.config.js`, containing no PHP at all:
 *
 *     Coverage current:       60.50%  (8303/13723 statements)
 *     Coverage merge base:    60.55%  (8309/13723 statements)
 *     FAIL: coverage dropped by 0.05% against the merge base.
 *
 * The denominator is identical, so both runs compiled the same source, and both
 * reported exactly `Tests: 948, Assertions: 3051, Skipped: 1`. Only which six
 * statements xdebug recorded as covered moved. The header above argues that a
 * measured `--against` floor cancels driver variance, and it does — but it does
 * not cancel RUN-TO-RUN variance, and the ratchet has no tolerance.
 *
 * A tolerance band was the obvious alternative and is the wrong one: it blinds
 * the gate to small real regressions permanently, at a threshold nobody can
 * justify. Scoping to the files the change actually touched keeps full strength
 * where it matters and makes the noise unreachable — a diff with no PHP cannot
 * fail, by construction, rather than by being forgiven.
 *
 * Zero matches is NOT zero coverage. It means the changed files are absent from
 * this report — typically because they are new on the head side and do not
 * exist at the merge base, which is normal and must not read as a drop. It
 * measures [0, 0, 0.0].
 *
 * @param CgClover          $report Clover report to read.
 * @param array<int,string> $only   Repo-relative paths to include.
 *
 * @return array{0:int,1:int,2:float} statements, covered, percentage
 */
function cgMeasureFiles(CgClover $report, array $only): array
{
    $pair = [0, 0];

    foreach (cgOpenReport($report)->xpath('//file') as $entry) {
        if (cgMatchPath((string) $entry['name'], $only) !== null) {
            $metrics = [(int) $entry->metrics['statements'], (int) $entry->metrics['coveredstatements']];
            $pair    = cgAddPair($pair, $metrics);
        }
    }

    return [$pair[0], $pair[1], cgPercent($pair)];
}//end cgMeasureFiles()

/**
 * Attribute every statement in the named files to the METHOD it sits in.
 *
 * See CgAttribution for why by method name and how degenerate files are handled.
 *
 * @param CgClover          $report Clover report to read.
 * @param array<int,string> $only   Repo-relative paths to include.
 *
 * @return CgAttribution
 */
function cgAttributeMethods(CgClover $report, array $only): CgAttribution
{
    $run = new CgAttribution($report->label);

    foreach (cgOpenReport($report)->xpath('//file') as $entry) {
        $path = cgMatchPath((string) $entry['name'], $only);
        if ($path !== null) {
            $run->addEntry($entry, $path);
        }
    }

    $run->dedupe();

    return $run;
}//end cgAttributeMethods()

/**
 * Bucket the `stmt` lines of one clover `<file>` entry by the method that opens them.
 *
 * @param SimpleXMLElement $entry Clover `<file>` entry.
 * @param string           $path  Repo-relative path the entry matched.
 *
 * @return array<string,array{0:int,1:int}>
 */
function cgLineBuckets(SimpleXMLElement $entry, string $path): array
{
    $method = CG_WHOLE_FILE;
    $local  = [];

    foreach ($entry->line as $line) {
        $type = (string) $line['type'];
        if ($type === 'method') {
            $method = cgMethodName($line);
        } else if ($type === 'stmt') {
            $key         = ($path . '::' . $method);
            $local[$key] = cgCountLine(($local[$key] ?? [0, 0]), $line);
        }
    }

    return $local;
}//end cgLineBuckets()

/**
 * The bucket name a `type="method"` line opens.
 *
 * @param SimpleXMLElement $line Clover `<line>` element.
 *
 * @return string
 */
function cgMethodName(SimpleXMLElement $line): string
{
    $named = (string) $line['name'];
    if ($named === '') {
        return CG_WHOLE_FILE;
    }

    return $named;
}//end cgMethodName()

/**
 * Count one `stmt` line into a [statements, covered] pair.
 *
 * @param array{0:int,1:int} $pair A [statements, covered] pair.
 * @param SimpleXMLElement   $line Clover `<line>` element.
 *
 * @return array{0:int,1:int}
 */
function cgCountLine(array $pair, SimpleXMLElement $line): array
{
    if (((int) $line['count']) > 0) {
        return [($pair[0] + 1), ($pair[1] + 1)];
    }

    return [($pair[0] + 1), $pair[1]];
}//end cgCountLine()

/**
 * Collapse every bucket belonging to the named files into one per file.
 *
 * THIS IS THE HOLE THAT WRITING THE TESTS FOUND, and it is worth naming because
 * it is the invisible-pass shape in its purest form. If one side of the
 * comparison cannot be attributed to methods it falls back to a single
 * CG_WHOLE_FILE bucket — but that bucket's key then exists on ONE side only, so
 * the asymmetric rule classifies the entire base-side file as "deleted", drops
 * it, finds nothing left to compare, and reports:
 *
 *     OK: none of the changed PHP existed at the merge base
 *
 * A file the guard could not read would have passed every possible drop. So when
 * EITHER side falls back for a file, BOTH sides are collapsed to that file's
 * single bucket: the key then exists on both sides, the file is compared exactly
 * as the file-scoped mode compares it today, and the deletion penalty applies to
 * it. Conservative, and visible in the output.
 *
 * @param array<string,array{0:int,1:int}> $buckets Buckets to read.
 * @param array<int,string>                $paths   Files to collapse.
 *
 * @return array<string,array{0:int,1:int}>
 */
function cgCollapseFiles(array $buckets, array $paths): array
{
    if (empty($paths) === true) {
        return $buckets;
    }

    $collapse = array_fill_keys($paths, true);
    $out      = [];

    foreach ($buckets as $key => $pair) {
        $key       = cgCollapsedKey($key, $collapse);
        $out[$key] = cgAddPair(($out[$key] ?? [0, 0]), $pair);
    }

    return $out;
}//end cgCollapseFiles()

/**
 * The bucket key after collapsing: the file's CG_WHOLE_FILE key for a collapsed file.
 *
 * @param string             $key      Bucket key "<path>::<method>".
 * @param array<string,bool> $collapse Paths to collapse, as keys.
 *
 * @return string
 */
function cgCollapsedKey(string $key, array $collapse): string
{
    $split = strrpos($key, '::');
    $path  = $key;
    if ($split !== false) {
        $path = substr($key, 0, $split);
    }

    if (isset($collapse[$path]) === true) {
        return ($path . '::' . CG_WHOLE_FILE);
    }

    return $key;
}//end cgCollapsedKey()

/**
 * Sum a bucket map, optionally restricted to a set of keys.
 *
 * @param array<string,array{0:int,1:int}> $buckets Buckets to read.
 * @param array<string,bool>|null          $keep    Keys to include, or null for all.
 *
 * @return array{0:int,1:int} statements, covered
 */
function cgSumBuckets(array $buckets, ?array $keep = null): array
{
    $statements = 0;
    $covered    = 0;

    foreach ($buckets as $key => $pair) {
        if ($keep !== null && isset($keep[$key]) === false) {
            continue;
        }

        $statements += $pair[0];
        $covered    += $pair[1];
    }

    return [$statements, $covered];
}//end cgSumBuckets()

/**
 * Read the changed-file list, keeping only PHP files the guard can measure.
 *
 * @param string $path File containing one repo-relative path per line.
 *
 * @return array<int,string>
 */
function cgReadChangedFiles(string $path): array
{
    $php = [];
    foreach (cgReadLines($path) as $line) {
        $line = trim($line);
        if ($line !== '' && str_ends_with($line, '.php') === true) {
            $php[] = $line;
        }
    }

    return array_values(array_unique($php));
}//end cgReadChangedFiles()

/**
 * The non-empty lines of the changed-file list, stopping with CG_INPUT when it cannot be read.
 *
 * @param string $path File containing one repo-relative path per line.
 *
 * @return array<int,string>
 */
function cgReadLines(string $path): array
{
    if (file_exists($path) === false) {
        cgFail("Error: changed-files list not found: {$path}\n");
    }

    $lines = file($path, (FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    if ($lines === false) {
        cgFail("Error: could not read changed-files list: {$path}\n");
    }

    return $lines;
}//end cgReadLines()

/**
 * Parse `--key=value` / `--flag` into a map, and everything else in order.
 *
 * @param array<int,string> $argv Command-line arguments, script name first.
 *
 * @return array{0: array<string,string|bool>, 1: array<int,string>}
 */
function cgParseArgs(array $argv): array
{
    $options    = [];
    $positional = [];

    foreach (array_slice($argv, 1) as $arg) {
        if (strncmp($arg, '--', 2) !== 0) {
            $positional[] = $arg;
            continue;
        }

        [$key, $value]  = cgParseOption(substr($arg, 2));
        $options[$key] = $value;
    }

    return [$options, $positional];
}//end cgParseArgs()

/**
 * Split one option body (`key=value` or `flag`) into its key and value.
 *
 * @param string $body Option text after the leading `--`.
 *
 * @return array{0:string,1:string|bool}
 */
function cgParseOption(string $body): array
{
    $eq = strpos($body, '=');
    if ($eq === false) {
        return [$body, true];
    }

    return [substr($body, 0, $eq), substr($body, ($eq + 1))];
}//end cgParseOption()

/**
 * An option's value when it is a non-empty string, otherwise null.
 *
 * @param array<string,string|bool> $options Parsed `--key=value` options.
 * @param string                    $name    Option key.
 *
 * @return ?string
 */
function cgOptionString(array $options, string $name): ?string
{
    $value = ($options[$name] ?? null);
    if (is_string($value) === true && $value !== '') {
        return $value;
    }

    return null;
}//end cgOptionString()

/**
 * Read a clover report and return its statement counts and percentage.
 *
 * An empty or zero-statement report is a HARD ERROR, not 0% and not 100%.
 * Reading "no statements" as a number would let a run that produced nothing
 * satisfy the guard — and when the empty report is the merge-base side, it
 * would set the floor to 0 and pass every possible drop.
 *
 * @param CgClover $report Clover report to read.
 *
 * @return array{0: int, 1: int, 2: float}
 */
function cgMeasure(CgClover $report): array
{
    cgRequireFile($report);

    $xml = @simplexml_load_file($report->file);
    if ($xml === false || isset($xml->project->metrics) === false) {
        cgFail("Error: could not parse coverage metrics from {$report->label} report {$report->file}\n");
    }

    $metrics = $xml->project->metrics;
    $pair    = [(int) $metrics['statements'], (int) $metrics['coveredstatements']];
    cgRequireStatements($report, $pair[0]);

    return [$pair[0], $pair[1], cgPercent($pair)];
}//end cgMeasure()

/**
 * Stop with CG_INPUT when a whole-project report counts no statements.
 *
 * @param CgClover $report     Clover report to read.
 * @param int      $statements Statements the report counts.
 *
 * @return void
 */
function cgRequireStatements(CgClover $report, int $statements): void
{
    if ($statements <= 0) {
        cgFail(
            "Error: {$report->label} report {$report->file} counts zero statements. Refusing to turn an empty "
            . "report into a percentage — this is a measurement failure, not a coverage result.\n"
        );
    }
}//end cgRequireStatements()

/**
 * Compare percentages as integer hundredths.
 *
 * Used only against the committed floor, which IS a two-decimal constant, so
 * two decimals is all the precision that comparison can carry.
 *
 * @param float $percentage Percentage to convert.
 *
 * @return int
 */
function cgHundredths(float $percentage): int
{
    return (int) round(($percentage * 100));
}//end cgHundredths()

/**
 * Is the head ratio strictly below the merge-base ratio?
 *
 * Both sides are [statements, covered, ...] measurements.
 *
 * Compared as exact integer cross-products, NOT as rounded percentages, and
 * that distinction is load-bearing. Rounding to two decimals hides roughly one
 * statement: measured on openbuild, dropping 8229/13987 to 8228/13987 leaves
 * both sides reading 58.83%, so the guard printed two different statement
 * counts next to the word "unchanged" and exited 0. One statement is a small
 * hole, but it is a hole that does not close by itself — a regression that
 * gives back a statement at a time is invisible for as many pull requests as it
 * cares to take.
 *
 * The cost is that genuine measurement jitter now reds a build instead of being
 * swallowed. That is the intended direction: the counts are printed on failure,
 * so a flake is immediately legible as a flake, whereas a swallowed drop is
 * legible as nothing at all.
 *
 * @param array{0:int,1:int} $head Head measurement: statements, covered, percentage.
 * @param array{0:int,1:int} $base Merge-base measurement: statements, covered, percentage.
 *
 * @return bool
 */
function cgRatioDropped(array $head, array $base): bool
{
    return (($head[1] * $base[0]) < ($base[1] * $head[0]));
}//end cgRatioDropped()

/**
 * Print a percentage together with the counts it came from.
 *
 * The raw statement counts are printed on purpose. A bare percentage cannot
 * distinguish "tests were deleted" from "untested code was added", and the
 * second is the common way a ratchet is tripped by a change that added no
 * tests at all.
 *
 * @param string                     $label   Text printed before the figures.
 * @param array{0:int,1:int,2:float} $measure statements, covered, percentage
 *
 * @return void
 */
function cgReport(string $label, array $measure): void
{
    printf("%-22s %6.2f%%  (%d/%d statements)\n", $label, $measure[2], $measure[1], $measure[0]);
}//end cgReport()

/**
 * Say how many statements a change added, when it added any.
 *
 * @param int    $added Head statements minus base statements.
 * @param string $where Text after "statements", e.g. " to those files".
 *
 * @return void
 */
function cgPrintAdded(int $added, string $where): void
{
    if ($added > 0) {
        echo "      This change adds {$added} statements{$where}. Adding code without tests drops coverage.\n";
    }
}//end cgPrintAdded()

/**
 * The verdict when none of the changed files is in either report.
 *
 * @return int
 */
function cgNothingMeasured(): int
{
    echo "OK: none of the changed PHP files appear in either coverage report.\n";
    echo "    Nothing was measured, so nothing is claimed about them.\n";
    return CG_OK;
}//end cgNothingMeasured()

/**
 * Print the capabilities, one per line.
 *
 * @return int
 */
function cgPrintCapabilities(): int
{
    echo implode("\n", CG_CAPABILITIES), "\n";
    return CG_OK;
}//end cgPrintCapabilities()

/**
 * Run the guard and return its exit code.
 *
 * @param array<int,string> $argv Command-line arguments, script name first.
 *
 * @return int
 */
function cgMain(array $argv): int
{
    [$options, $positional] = cgParseArgs($argv);

    if (isset($options['capabilities']) === true) {
        return cgPrintCapabilities();
    }

    $run = new CgRun($options, ($positional[0] ?? 'coverage/clover.xml'));

    cgRequireScopeForDeletionNeutral($run);

    $changedList = $run->changedList;
    if ($changedList !== null) {
        return cgScoped($run, $changedList);
    }

    return cgWholeProject($run);
}//end cgMain()

/**
 * Stop with CG_INPUT when `--deletion-neutral` comes without `--changed-files`.
 *
 * A flag that is accepted and ignored is the silent-downgrade shape this script's
 * `--capabilities` probe exists to prevent, so refuse rather than fall through to
 * a comparison the caller did not ask for.
 *
 * @param CgRun $run Options of this run.
 *
 * @return void
 */
function cgRequireScopeForDeletionNeutral(CgRun $run): void
{
    if ($run->deletionNeutral === true && $run->changedList === null) {
        cgFail(
            'Error: --deletion-neutral requires --changed-files (and therefore --against). '
            . "It refines the file-scoped comparison; it has no meaning against the whole project.\n"
        );
    }
}//end cgRequireScopeForDeletionNeutral()

/**
 * Scoped mode: compare ONLY the PHP the change touched.
 *
 * Requires --against. Without a merge-base report there is nothing to compare a
 * file subset to, and falling back to the committed whole-project constant here
 * would compare a subset against a whole and fail everything.
 *
 * @param CgRun  $run         Options of this run.
 * @param string $changedList File containing one repo-relative path per line.
 *
 * @return int
 */
function cgScoped(CgRun $run, string $changedList): int
{
    $base = $run->base;
    if ($base === null) {
        cgFail("Error: --changed-files requires --against; a file subset has no meaning against the committed whole-project baseline.\n");
    }

    $changed = cgReadChangedFiles($changedList);
    if (empty($changed) === true) {
        return cgNoPhpChanged();
    }

    echo 'Scoped to ' . count($changed) . " changed PHP file(s).\n";

    $sides = new CgSides($run->head, $base);
    return ($run->deletionNeutral === true ? cgDeletionNeutral($sides, $changed) : cgFileScoped($sides, $changed));
}//end cgScoped()

/**
 * The verdict when the change touches no PHP.
 *
 * @return int
 */
function cgNoPhpChanged(): int
{
    echo "OK: this change touches no PHP files, so there is no coverage to compare.\n";
    echo "    (Whole-project drift between two runs of identical code is measurement noise, not a regression.)\n";
    return CG_OK;
}//end cgNoPhpChanged()

/**
 * File-scoped mode: the changed files' totals, head against merge base.
 *
 * @param CgSides           $sides   Head and merge-base reports.
 * @param array<int,string> $changed Changed PHP files, repo-relative.
 *
 * @return int
 */
function cgFileScoped(CgSides $sides, array $changed): int
{
    $head = cgMeasureFiles($sides->head, $changed);
    $base = cgMeasureFiles($sides->base, $changed);

    if ($head[0] === 0 && $base[0] === 0) {
        return cgNothingMeasured();
    }

    cgReport('Changed files, head:', $head);
    cgReport('Changed files, base:', $base);

    if ($base[0] === 0) {
        echo "OK: the changed PHP is new at the merge base, so there is no prior figure to drop below.\n";
        return CG_OK;
    }

    return (cgRatioDropped($head, $base) === true ? cgFileScopedDropped($head, $base) : cgFileScopedKept());
}//end cgFileScoped()

/**
 * The verdict when the changed files lost coverage.
 *
 * @param array{0:int,1:int,2:float} $head Head measurement: statements, covered, percentage.
 * @param array{0:int,1:int,2:float} $base Merge-base measurement: statements, covered, percentage.
 *
 * @return int
 */
function cgFileScopedDropped(array $head, array $base): int
{
    $delta = round(($base[2] - $head[2]), 2);
    echo "FAIL: coverage of the files this change touches dropped by {$delta}%.\n";
    echo "      base {$base[1]}/{$base[0]} -> head {$head[1]}/{$head[0]} statements.\n";
    cgPrintAdded(($head[0] - $base[0]), ' to those files');

    return CG_DROPPED;
}//end cgFileScopedDropped()

/**
 * The verdict when the changed files kept their coverage.
 *
 * @return int
 */
function cgFileScopedKept(): int
{
    echo "OK: coverage of the changed files did not drop.\n";
    return CG_OK;
}//end cgFileScopedKept()

/**
 * Deletion-neutral mode.
 *
 * WHAT THIS FIXES. `cgRatioDropped()` is a single integer cross-product with
 * no tolerance, so the file-scoped ratchet demands, exactly:
 *
 *     the code you touched must be at least as well covered as the code you
 *     did not.
 *
 * For ADDITIONS that is right, and it earns its keep: openconnector#1265
 * covered 27 of 54 new statements against a 61.86% floor, needed 34, and
 * writing the missing seven is what exposed a cascade that deleted nothing
 * and reported success.
 *
 * For DELETIONS it INVERTS. Removing `d` statements of which `c` were covered
 * lowers the ratio whenever c/d exceeds the ratio of what remains — i.e.
 * whenever the deleted code was better tested than average. And dead code is
 * dead because nothing CALLS it, not because nothing TESTED it: gate-57
 * (orphaned-write-capability) exists to find precisely that code, so gate-57
 * and this ratchet are in arithmetic opposition, not tension. Such a pull
 * request cannot satisfy the ratchet from inside its own subject at all —
 * the only moves are delete less, add filler, or delete additional
 * *uncovered* statements until it balances. The gate can be satisfied by
 * deleting more code and cannot be satisfied by testing anything.
 *
 * Measured, both blocked by the file-scoped rule:
 *   opencatalogi#895  head 112/115 (97.39%)  base 148/151 (98.01%)  -0.62%
 *   launchpad#128     head 183/254 (72.05%)  base 220/304 (72.37%)  -0.32%
 *
 * THE RULE, AND IT IS ASYMMETRIC ON PURPOSE:
 *
 *     drop BASE-ONLY methods (deletions) from the base side;
 *     KEEP HEAD-ONLY methods (additions) on the head side.
 *
 * The symmetric version — "compare over statements present in both reports" —
 * is the obvious one and it is broken. It also drops head-only statements, so
 * a change adding 40 new statements with 0 of them covered compares an empty
 * set to an empty set and PASSES. That is the openconnector#1265 shape, and
 * the symmetric rule would have retired the half of this ratchet that works.
 * A mutant reintroducing it is in the test suite and must stay there.
 *
 * Consequences, all four measured in quality-config/tests/test-coverage-guard.py:
 *   pure deletion (opencatalogi) PASS  112/115 vs 112/115 — exactly neutral
 *   pure deletion (launchpad)    PASS  183/254 vs 183/254
 *   regression in survivors      FAIL  180/254 vs 183/254
 *   new untested code            FAIL  183/294 (62.24%) vs 183/254 (72.05%)
 *
 * KNOWN RESIDUAL, stated rather than discovered: a RENAME reads as a delete
 * plus an add. The old name leaves the base side, the new name arrives on the
 * head side and must be covered. That is the right incentive — a renamed
 * method is new code as far as the test suite is concerned — but it means a
 * pure rename of a well-covered method is not free, and an author who did not
 * expect it will read the failure as noise. It is documented here and in the
 * failure message so it is legible when it happens.
 *
 * @param CgSides           $sides   Head and merge-base reports.
 * @param array<int,string> $changed Changed PHP files, repo-relative.
 *
 * @return int
 */
function cgDeletionNeutral(CgSides $sides, array $changed): int
{
    $head = cgAttributeMethods($sides->head, $changed);
    $base = cgAttributeMethods($sides->base, $changed);

    cgCollapseFallbacks($head, $base);
    cgPrintAttribution($head, $base);

    if (empty($head->buckets) === true && empty($base->buckets) === true) {
        return cgNothingMeasured();
    }

    return cgCompareSurvivors($head->buckets, $base->buckets);
}//end cgDeletionNeutral()

/**
 * Either side falling back forces BOTH sides to file level for that file —
 * see cgCollapseFiles() for why anything else is an invisible pass.
 *
 * @param CgAttribution $head Head attribution.
 * @param CgAttribution $base Merge-base attribution.
 *
 * @return void
 */
function cgCollapseFallbacks(CgAttribution $head, CgAttribution $base): void
{
    $collapse = array_values(array_unique(array_merge($head->fellBack, $base->fellBack)));
    if (empty($collapse) === true) {
        return;
    }

    echo 'Falling back to file-level metrics on both sides for: ' . implode(', ', $collapse) . "\n";
    $head->buckets = cgCollapseFiles($head->buckets, $collapse);
    $base->buckets = cgCollapseFiles($base->buckets, $collapse);
}//end cgCollapseFallbacks()

/**
 * Print what was attributed on each side and which files the head no longer measures.
 *
 * @param CgAttribution $head Head attribution.
 * @param CgAttribution $base Merge-base attribution.
 *
 * @return void
 */
function cgPrintAttribution(CgAttribution $head, CgAttribution $base): void
{
    echo 'Deletion-neutral: attributed by method name (head ' . count($head->matched) . ' file(s), '
        . 'base ' . count($base->matched) . ' file(s), ' . count($head->buckets) . ' head method(s), '
        . count($base->buckets) . " base method(s)).\n";

    cgPrintGoneFiles(array_values(array_diff($base->matched, $head->matched)));
}//end cgPrintAttribution()

/**
 * A changed file the base measured and the head did not is normally a DELETED
 * file, and treating it as deleted is the point of this mode. But it is also
 * what an accidental coverage exclusion looks like, and the two are
 * indistinguishable from here — so it is said out loud rather than absorbed
 * silently.
 *
 * @param array<int,string> $goneFiles Files the base measured and the head did not.
 *
 * @return void
 */
function cgPrintGoneFiles(array $goneFiles): void
{
    if (empty($goneFiles) === true) {
        return;
    }

    echo 'Measured at the merge base and absent from the head report: ' . implode(', ', $goneFiles) . "\n";
    echo "    Treated as deleted. If one of those files still exists, it has been dropped from\n";
    echo "    coverage measurement and that is the thing to fix, not this guard.\n";
}//end cgPrintGoneFiles()

/**
 * Compare every head bucket with the base buckets that still exist on the head side.
 *
 * @param array<string,array{0:int,1:int}> $headBuckets Head buckets.
 * @param array<string,array{0:int,1:int}> $baseBuckets Merge-base buckets.
 *
 * @return int
 */
function cgCompareSurvivors(array $headBuckets, array $baseBuckets): int
{
    $keep = array_fill_keys(array_keys($headBuckets), true);
    cgPrintDropped(array_diff_key($baseBuckets, $keep));

    $head   = cgSumBuckets($headBuckets);
    $base   = cgSumBuckets($baseBuckets, $keep);
    $head[] = cgPercent($head);
    $base[] = cgPercent($base);

    cgReport('Surviving code, head:', $head);
    cgReport('Surviving code, base:', $base);

    return cgJudgeSurvivors($head, $base);
}//end cgCompareSurvivors()

/**
 * @param array<string,array{0:int,1:int}> $dropped Base buckets absent from head.
 *
 * @return void
 */
function cgPrintDropped(array $dropped): void
{
    if (empty($dropped) === true) {
        return;
    }

    [$droppedStatements, $droppedCovered] = cgSumBuckets($dropped);
    echo 'Removed from the base side: ' . count($dropped)
        . " method(s) absent from head, {$droppedCovered}/{$droppedStatements} statements.\n";
    echo "    A method that no longer exists is not a coverage regression; it is deleted code.\n";
}//end cgPrintDropped()

/**
 * @param array{0:int,1:int,2:float} $head Head measurement: statements, covered, percentage.
 * @param array{0:int,1:int,2:float} $base Merge-base measurement: statements, covered, percentage.
 *
 * @return int
 */
function cgJudgeSurvivors(array $head, array $base): int
{
    if ($base[0] === 0) {
        echo "OK: none of the changed PHP existed at the merge base, so there is no prior figure to drop below.\n";
        return CG_OK;
    }

    if (cgRatioDropped($head, $base) === true) {
        return cgSurvivorsDropped($head, $base);
    }

    echo "OK: coverage of the surviving and added code did not drop.\n";
    return CG_OK;
}//end cgJudgeSurvivors()

/**
 * @param array{0:int,1:int,2:float} $head Head measurement: statements, covered, percentage.
 * @param array{0:int,1:int,2:float} $base Merge-base measurement: statements, covered, percentage.
 *
 * @return int
 */
function cgSurvivorsDropped(array $head, array $base): int
{
    $delta = round(($base[2] - $head[2]), 2);
    echo($delta > 0
        ? "FAIL: coverage of the code this change KEEPS or ADDS dropped by {$delta}%.\n"
        : "FAIL: coverage of the code this change KEEPS or ADDS dropped by less than 0.01% — too "
        . "little to show in the percentage, but a real loss in the counts below.\n");
    echo "      base {$base[1]}/{$base[0]} -> head {$head[1]}/{$head[0]} statements, "
        . "comparing only methods that exist on BOTH sides plus everything new on this branch.\n";
    cgPrintAdded(($head[0] - $base[0]), ' to those files');
    echo "      Deleted methods were already excluded, so this is not a deletion penalty. If you\n";
    echo "      RENAMED a method, note that a rename reads as a delete plus an add: the new name is\n";
    echo "      new code here and has to be covered.\n";

    return CG_DROPPED;
}//end cgSurvivorsDropped()

/**
 * Whole-project mode: the merge base as the floor when measured, else the committed floor.
 *
 * @param CgRun $run Options of this run.
 *
 * @return int
 */
function cgWholeProject(CgRun $run): int
{
    $head = cgMeasure($run->head);
    cgReport('Coverage current:', $head);

    $against = $run->base;
    if ($against === null) {
        return cgCommittedFloor($run, $head);
    }

    $base = cgMeasure($against);
    cgReport('Coverage merge base:', $base);
    cgPrintCommitted($run->baselineFile);

    return cgAgainstBase($head, $base);
}//end cgWholeProject()

/**
 * The ratchet: a MEASURED floor.
 *
 * @param array{0:int,1:int,2:float} $head Head measurement: statements, covered, percentage.
 * @param array{0:int,1:int,2:float} $base Merge-base measurement: statements, covered, percentage.
 *
 * @return int
 */
function cgAgainstBase(array $head, array $base): int
{
    if (cgRatioDropped($head, $base) === true) {
        return cgAgainstDropped($head, $base);
    }

    if (cgRatioDropped($base, $head) === true) {
        return cgAgainstImproved($head, $base);
    }

    echo "OK: coverage unchanged against the merge base.\n";
    return CG_OK;
}//end cgAgainstBase()

/**
 * @param array{0:int,1:int,2:float} $head Head measurement: statements, covered, percentage.
 * @param array{0:int,1:int,2:float} $base Merge-base measurement: statements, covered, percentage.
 *
 * @return int
 */
function cgAgainstImproved(array $head, array $base): int
{
    $gain = round(($head[2] - $base[2]), 2);
    echo "OK: coverage improved by {$gain}% against the merge base "
        . "({$base[1]}/{$base[0]} -> {$head[1]}/{$head[0]}).\n";

    return CG_OK;
}//end cgAgainstImproved()

/**
 * Show the committed floor, which a measured merge base replaces.
 *
 * @param string $baselineFile Path of the committed `.coverage-baseline`.
 *
 * @return void
 */
function cgPrintCommitted(string $baselineFile): void
{
    if (file_exists($baselineFile) === true) {
        $committed = trim(file_get_contents($baselineFile));
        echo "Committed .coverage-baseline: {$committed}% — recorded only, NOT enforced on this run.\n";
        echo "The merge base is measured, so it is the floor; see the header of this script.\n";
    }
}//end cgPrintCommitted()

/**
 * @param array{0:int,1:int,2:float} $head Head measurement: statements, covered, percentage.
 * @param array{0:int,1:int,2:float} $base Merge-base measurement: statements, covered, percentage.
 *
 * @return int
 */
function cgAgainstDropped(array $head, array $base): int
{
    $delta = round(($base[2] - $head[2]), 2);
    echo($delta > 0 ? "FAIL: coverage dropped by {$delta}% against the merge base.\n"
        : "FAIL: coverage dropped against the merge base by less than 0.01% — too little to show in "
        . "the percentage, but a real loss in the counts below.\n");
    echo "      merge base {$base[1]}/{$base[0]} -> head {$head[1]}/{$head[0]} statements.\n";
    cgPrintAdded(($head[0] - $base[0]), '');

    return CG_DROPPED;
}//end cgAgainstDropped()

/**
 * Fail-safe: the committed floor.
 *
 * @param CgRun                      $run  Options of this run.
 * @param array{0:int,1:int,2:float} $head Head measurement: statements, covered, percentage.
 *
 * @return int
 */
function cgCommittedFloor(CgRun $run, array $head): int
{
    $baseline = cgReadBaseline($run->baselineFile);

    $order = (cgHundredths($head[2]) <=> cgHundredths($baseline));
    if ($order < 0) {
        return cgBelowFloor($head[2], $baseline);
    }

    if ($order > 0) {
        echo 'OK: coverage is ' . round(($head[2] - $baseline), 2) . "% above the committed floor.\n";
        cgUpdateBaseline($run, $head[2]);
        return CG_OK;
    }

    echo "OK: coverage unchanged.\n";
    return CG_OK;
}//end cgCommittedFloor()

/**
 * The verdict when coverage is below the committed floor.
 *
 * @param float $current  Measured head percentage.
 * @param float $baseline Committed floor percentage.
 *
 * @return int
 */
function cgBelowFloor(float $current, float $baseline): int
{
    echo 'FAIL: coverage dropped by ' . round(($baseline - $current), 2) . "% below the committed floor.\n";
    return CG_DROPPED;
}//end cgBelowFloor()

/**
 * Read and print the committed floor, stopping with CG_INPUT when it is missing or not a number.
 *
 * @param string $baselineFile Path of the committed `.coverage-baseline`.
 *
 * @return float
 */
function cgReadBaseline(string $baselineFile): float
{
    if (file_exists($baselineFile) === false) {
        cgFail("Error: baseline file not found: {$baselineFile}\n");
    }

    $raw = trim(file_get_contents($baselineFile));
    if (preg_match('/^[0-9]+(\.[0-9]+)?$/', $raw) !== 1) {
        cgFail("Error: unparseable coverage baseline value '{$raw}' — refusing to guess.\n");
    }

    $baseline = (float) $raw;
    printf("%-22s %6.2f%%  (committed fail-safe floor)\n", 'Coverage baseline:', $baseline);

    return $baseline;
}//end cgReadBaseline()

/**
 * Kept for callers that want the recomputed value written out. Being ABOVE
 * the committed floor is explicitly not a failure: the floor is conservative
 * by design and drifts low as coverage rises. The live ratchet is the
 * merge-base comparison, which cannot go stale at all.
 *
 * @param CgRun $run     Options of this run.
 * @param float $current Measured head percentage.
 *
 * @return void
 */
function cgUpdateBaseline(CgRun $run, float $current): void
{
    if ($run->updateBaseline === true) {
        file_put_contents($run->baselineFile, (number_format($current, 2) . "\n"));
        echo "Recomputed floor written: {$current}%\n";
    }
}//end cgUpdateBaseline()

// ── main ────────────────────────────────────────────────────────────────────

exit(cgMain($argv));
