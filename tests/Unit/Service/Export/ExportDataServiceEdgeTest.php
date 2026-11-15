<?php

/**
 * Unit tests for ExportDataService's edge cases: schema references that cannot
 * be configured, payloads JSON cannot encode, compression algorithms whose
 * extension is absent, unparseable row filters, and CSV cell rendering of
 * nulls, booleans and nested values.
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
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Export;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\Export\ExportDataService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Edge cases of ExportDataService's formatting and filtering.
 *
 * @covers \OCA\Pipelinq\Service\Export\ExportDataService
 * @uses   \OCA\Pipelinq\Service\Export\AbstractExportService
 */
class ExportDataServiceEdgeTest extends TestCase {

	/**
	 * A string that is not valid UTF-8, which json_encode refuses.
	 */
	private const INVALID_UTF8 = "\xB1\x31";

	/**
	 * Build the service over the given app config.
	 *
	 * @param IAppConfig|null $appConfig The app config.
	 *
	 * @return ExportDataService
	 */
	private function service(?IAppConfig $appConfig = null): ExportDataService {
		return new ExportDataService(
			$this->createMock(ContainerInterface::class),
			($appConfig ?? $this->createMock(IAppConfig::class)),
			$this->createMock(ObjectServiceInterface::class)
		);
	}//end service()

	/**
	 * A slug that is not an identifier never reaches the app config and does not exist.
	 *
	 * @return void
	 */
	public function testAnInvalidSlugDoesNotExist(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->never())->method('getValueString');

		$this->assertFalse($this->service($appConfig)->schemaExists(slug: '1lead'));
		$this->assertFalse($this->service($appConfig)->schemaExists(slug: '  '));
	}//end testAnInvalidSlugDoesNotExist()

	/**
	 * A valid slug exists only when its schema key is configured.
	 *
	 * @return void
	 */
	public function testAValidSlugExistsWhenConfigured(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => (['lead_schema' => '12'][$key] ?? $default)
		);

		$this->assertTrue($this->service($appConfig)->schemaExists(slug: 'lead'));
		$this->assertFalse($this->service($appConfig)->schemaExists(slug: 'contact'));
	}//end testAValidSlugExistsWhenConfigured()

	/**
	 * A row JSON cannot encode becomes an empty object; the other rows are kept.
	 *
	 * @return void
	 */
	public function testAnUnencodableJsonlRowBecomesAnEmptyObject(): void {
		$jsonl = $this->service()->formatJsonl(rows: [['a' => self::INVALID_UTF8], ['a' => 'ok']]);

		$this->assertSame("{}\n{\"a\":\"ok\"}\n", $jsonl);
	}//end testAnUnencodableJsonlRowBecomesAnEmptyObject()

	/**
	 * A parquet envelope JSON cannot encode yields an empty payload.
	 *
	 * @return void
	 */
	public function testAnUnencodableParquetEnvelopeIsEmpty(): void {
		$this->assertSame('', $this->service()->formatParquet(rows: [['a' => self::INVALID_UTF8]]));
	}//end testAnUnencodableParquetEnvelopeIsEmpty()

	/**
	 * Without their extensions zstd and snappy leave the payload as it is, as does an unknown algorithm.
	 *
	 * @return void
	 */
	public function testCompressionWithoutItsExtensionLeavesThePayload(): void {
		if (function_exists('zstd_compress') === true || function_exists('snappy_compress') === true) {
			$this->markTestSkipped('The zstd or snappy extension is loaded.');
		}

		$service = $this->service();

		$this->assertSame('payload', $service->compress(contents: 'payload', compression: 'zstd'));
		$this->assertSame('payload', $service->compress(contents: 'payload', compression: 'snappy'));
		$this->assertSame('payload', $service->compress(contents: 'payload', compression: 'brotli'));
	}//end testCompressionWithoutItsExtensionLeavesThePayload()

	/**
	 * An expression that is not `column OP value` leaves the rows untouched.
	 *
	 * @return void
	 */
	public function testAnUnparseableRowFilterKeepsEveryRow(): void {
		$rows = [['status' => 'open'], ['status' => 'won']];

		$this->assertSame($rows, $this->service()->applyRowFilter(rows: $rows, expression: 'status > open'));
		$this->assertSame($rows, $this->service()->applyRowFilter(rows: $rows, expression: '1status = open'));
	}//end testAnUnparseableRowFilterKeepsEveryRow()

	/**
	 * CSV cells render null empty, booleans as literals, arrays as JSON, and unencodable arrays empty.
	 *
	 * @return void
	 */
	public function testCsvCellsRenderEachValueKind(): void {
		$csv = $this->service()->formatCsv(
			rows: [
				[
					'none' => null,
					'yes' => true,
					'no' => false,
					'tags' => ['a', 'b/c'],
					'bad' => [self::INVALID_UTF8],
					'n' => 3,
				],
			]
		);

		$this->assertSame("none,yes,no,tags,bad,n\r\n,true,false,\"[\"\"a\"\",\"\"b/c\"\"]\",,3\r\n", $csv);
	}//end testCsvCellsRenderEachValueKind()

	/**
	 * A row filter compares the string form of booleans and arrays.
	 *
	 * @return void
	 */
	public function testARowFilterComparesTheStringForm(): void {
		$rows = [['active' => true], ['active' => false], ['active' => null]];

		$this->assertSame([['active' => true]], $this->service()->applyRowFilter(rows: $rows, expression: "active = 'true'"));
		$this->assertSame(
			[['active' => true], ['active' => false]],
			array_values($this->service()->applyRowFilter(rows: $rows, expression: "active != ''"))
		);
	}//end testARowFilterComparesTheStringForm()
}//end class
