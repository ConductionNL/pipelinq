<?php

/**
 * Unit tests for ExportDataService.
 *
 * Exercises the pure extraction/formatting/masking helpers that do not touch
 * OpenRegister: RFC 4180 CSV, RFC 7464 JSON-lines and the self-describing
 * Parquet envelope; the row filter and column allowlist (PII minimisation);
 * the soft-delete tombstone marker and incremental watermark cursor; the
 * sensitive-column (PII/BSN) detection (ADR-005); and gzip compression.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://codeberg.org/Conduction/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Export;

use OCA\Pipelinq\Service\Export\ExportDataService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for ExportDataService's pure formatting + masking surface.
 */
class ExportDataServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var ExportDataService
     */
    private ExportDataService $service;

    /**
     * Build the service with mocked OR collaborators (unused by pure methods).
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $container = $this->createMock(ContainerInterface::class);
        $appConfig = $this->createMock(IAppConfig::class);

        $this->service = new ExportDataService($container, $appConfig);
    }//end setUp()

    /**
     * CSV output is RFC 4180: header row, CRLF line endings, quoted escaping.
     *
     * @return void
     */
    public function testFormatCsvIsRfc4180(): void
    {
        $rows = [
            ['name' => 'Alice', 'note' => 'hi, there'],
            ['name' => 'Bob "B"', 'note' => 'line'],
        ];

        $csv = $this->service->formatCsv(rows: $rows);

        $lines = explode("\r\n", rtrim($csv, "\r\n"));
        $this->assertSame('name,note', $lines[0]);
        // A value containing a comma is quoted.
        $this->assertSame('Alice,"hi, there"', $lines[1]);
        // An embedded double quote is doubled.
        $this->assertSame('"Bob ""B""",line', $lines[2]);
        $this->assertStringEndsWith("\r\n", $csv);
    }//end testFormatCsvIsRfc4180()

    /**
     * Empty input yields an empty CSV.
     *
     * @return void
     */
    public function testFormatCsvEmpty(): void
    {
        $this->assertSame('', $this->service->formatCsv(rows: []));
    }//end testFormatCsvEmpty()

    /**
     * JSON-lines emits one JSON object per line preserving native types.
     *
     * @return void
     */
    public function testFormatJsonlPreservesTypes(): void
    {
        $rows = [
            ['id' => 1, 'active' => true, 'score' => 4.5],
            ['id' => 2, 'active' => false, 'score' => null],
        ];

        $jsonl = $this->service->formatJsonl(rows: $rows);
        $lines = explode("\n", rtrim($jsonl, "\n"));

        $this->assertCount(2, $lines);
        $first = json_decode($lines[0], true);
        $this->assertSame(1, $first['id']);
        $this->assertTrue($first['active']);
        $this->assertSame(4.5, $first['score']);
        $second = json_decode($lines[1], true);
        $this->assertFalse($second['active']);
        $this->assertNull($second['score']);
    }//end testFormatJsonlPreservesTypes()

    /**
     * The Parquet envelope embeds an inferred schema so it is self-describing.
     *
     * @return void
     */
    public function testFormatParquetEmbedsSchema(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ];

        $envelope = json_decode($this->service->formatParquet(rows: $rows), true);

        $this->assertSame('parquet', $envelope['format']);
        $this->assertArrayHasKey('id', $envelope['schema']);
        $this->assertArrayHasKey('name', $envelope['schema']);
        $this->assertCount(2, $envelope['rows']);
    }//end testFormatParquetEmbedsSchema()

    /**
     * The format dispatch routes to the requested formatter.
     *
     * @return void
     */
    public function testFormatDispatch(): void
    {
        $rows = [['a' => 1]];

        $this->assertStringContainsString('a', $this->service->format(rows: $rows, format: 'csv'));
        $this->assertStringContainsString('"format":"parquet"', $this->service->format(rows: $rows, format: 'parquet'));
        $this->assertStringContainsString('{"a":1}', $this->service->format(rows: $rows, format: 'jsonl'));
    }//end testFormatDispatch()

    /**
     * gzip compression round-trips back to the original payload.
     *
     * @return void
     */
    public function testCompressGzipRoundTrips(): void
    {
        $payload    = str_repeat('pipelinq-export ', 100);
        $compressed = $this->service->compress(contents: $payload, compression: 'gzip');

        $this->assertNotSame($payload, $compressed);
        $this->assertLessThan(strlen($payload), strlen($compressed));
        $this->assertSame($payload, gzdecode($compressed));
    }//end testCompressGzipRoundTrips()

    /**
     * `none` compression leaves the payload byte-identical.
     *
     * @return void
     */
    public function testCompressNoneIsNoop(): void
    {
        $payload = 'unchanged';
        $this->assertSame($payload, $this->service->compress(contents: $payload, compression: 'none'));
    }//end testCompressNoneIsNoop()

    /**
     * The row filter keeps only rows matching a `column = value` expression.
     *
     * @return void
     */
    public function testApplyRowFilterEquality(): void
    {
        $rows = [
            ['status' => 'open'],
            ['status' => 'closed'],
            ['status' => 'open'],
        ];

        $filtered = $this->service->applyRowFilter(rows: $rows, expression: "status = 'open'");

        $this->assertCount(2, $filtered);
    }//end testApplyRowFilterEquality()

    /**
     * The row filter supports negation (`!=`).
     *
     * @return void
     */
    public function testApplyRowFilterNegation(): void
    {
        $rows = [
            ['status' => 'open'],
            ['status' => 'closed'],
        ];

        $filtered = $this->service->applyRowFilter(rows: $rows, expression: 'status != closed');

        $this->assertCount(1, $filtered);
        $this->assertSame('open', $filtered[0]['status']);
    }//end testApplyRowFilterNegation()

    /**
     * The column allowlist drops every non-listed column and reports the drops.
     *
     * @return void
     */
    public function testApplyColumnAllowlistDropsAndReports(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'Alice', 'bsn' => '123456782'],
            ['id' => 2, 'name' => 'Bob', 'bsn' => '987654321'],
        ];

        [$masked, $dropped] = $this->service->applyColumnAllowlist(rows: $rows, allowlist: ['id', 'name']);

        $this->assertArrayNotHasKey('bsn', $masked[0]);
        $this->assertArrayHasKey('name', $masked[0]);
        $this->assertContains('bsn', $dropped);
    }//end testApplyColumnAllowlistDropsAndReports()

    /**
     * Sensitive columns (BSN/email) are flagged when no allowlist restricts them.
     *
     * @return void
     */
    public function testSensitiveColumnsExportedWithoutAllowlist(): void
    {
        $present  = ['id', 'name', 'bsn', 'email'];
        $exported = $this->service->sensitiveColumnsExported(presentColumns: $present, allowlist: null);

        $this->assertContains('bsn', $exported);
        $this->assertContains('email', $exported);
        $this->assertNotContains('id', $exported);
    }//end testSensitiveColumnsExportedWithoutAllowlist()

    /**
     * An allowlist that omits the sensitive columns reports none as exported.
     *
     * @return void
     */
    public function testSensitiveColumnsSuppressedByAllowlist(): void
    {
        $present  = ['id', 'name', 'bsn'];
        $exported = $this->service->sensitiveColumnsExported(presentColumns: $present, allowlist: ['id', 'name']);

        $this->assertSame([], $exported);
    }//end testSensitiveColumnsSuppressedByAllowlist()

    /**
     * The tombstone marker flags soft-deleted rows for warehouse erasure.
     *
     * @return void
     */
    public function testAddTombstoneMarker(): void
    {
        $rows = [
            ['id' => 1, 'deleted' => null],
            ['id' => 2, 'deletedAt' => '2026-01-01T00:00:00Z'],
        ];

        $marked = $this->service->addTombstoneMarker(rows: $rows);

        $this->assertFalse($marked[0]['_deleted']);
        $this->assertTrue($marked[1]['_deleted']);
    }//end testAddTombstoneMarker()

    /**
     * The incremental cursor returns the maximum watermark value across rows.
     *
     * @return void
     */
    public function testMaxWatermarkTracksCursor(): void
    {
        $rows = [
            ['updatedAt' => '2026-01-01T00:00:00Z'],
            ['updatedAt' => '2026-03-15T12:00:00Z'],
            ['updatedAt' => '2026-02-01T00:00:00Z'],
        ];

        $max = $this->service->maxWatermark(rows: $rows, watermarkColumn: 'updatedAt');

        $this->assertSame('2026-03-15T12:00:00Z', $max);
    }//end testMaxWatermarkTracksCursor()

    /**
     * The cursor is null when no row carries the watermark column.
     *
     * @return void
     */
    public function testMaxWatermarkNullWhenAbsent(): void
    {
        $rows = [['id' => 1], ['id' => 2]];

        $this->assertNull($this->service->maxWatermark(rows: $rows, watermarkColumn: 'updatedAt'));
    }//end testMaxWatermarkNullWhenAbsent()

    /**
     * Column definitions infer a type per column for the schema snapshot.
     *
     * @return void
     */
    public function testColumnDefinitions(): void
    {
        $rows = [['id' => 1, 'name' => 'Alice', 'active' => true]];

        $defs = $this->service->columnDefinitions(rows: $rows);

        $this->assertArrayHasKey('id', $defs);
        $this->assertArrayHasKey('name', $defs);
        $this->assertArrayHasKey('active', $defs);
    }//end testColumnDefinitions()
}//end class
