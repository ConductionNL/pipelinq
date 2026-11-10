<?php

/**
 * Characterisation tests for export job validation and schema extraction.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Export;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\Export\CronExpressionHelper;
use OCA\Pipelinq\Service\Export\ExportDataService;
use OCA\Pipelinq\Service\Export\ExportDestinationService;
use OCA\Pipelinq\Service\Export\ExportJobService;
use OCA\Pipelinq\Service\Export\ExportRunSummary;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins every refusal of ExportJobService::validateJob in its order, the
 * extraction descriptor of ExportDataService::extractSchema and the column
 * types it infers, so both can be restructured safely.
 */
class ExportJobValidationAndExtractTest extends TestCase {
	/**
	 * A job that passes every check.
	 *
	 * @var array<string, mixed>
	 */
	private const VALID = [
		'name' => 'Nightly',
		'sourceSchemas' => ['lead', 'client'],
		'format' => 'csv',
		'mode' => 'full',
		'scheduleCron' => '0 2 * * *',
		'destinationId' => 'dest-1',
	];

	/**
	 * Each invalid job and the refusal it gets, first failing check wins.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function invalidJobs(): array {
		$valid = self::VALID;
		return [
			'no name' => [['name' => '  '] + $valid, 'Job name is required.'],
			'missing name' => [array_diff_key($valid, ['name' => 1]), 'Job name is required.'],
			'no schemas' => [['sourceSchemas' => []] + $valid, 'At least one source schema is required.'],
			'schemas not a list' => [['sourceSchemas' => 'lead'] + $valid, 'At least one source schema is required.'],
			'unknown schema' => [['sourceSchemas' => ['lead', 'ghost'], 'format' => 'xml'] + $valid, "Schema 'ghost' does not exist."],
			'bad format' => [['format' => 'xml', 'mode' => 'odd'] + $valid, "Unsupported format 'xml'."],
			'missing format' => [array_diff_key($valid, ['format' => 1]), "Unsupported format ''."],
			'bad mode' => [['mode' => 'odd', 'scheduleCron' => 'nope'] + $valid, "Unsupported mode 'odd'."],
			'incremental without column' => [['mode' => 'incremental', 'incrementalWatermarkColumn' => ' '] + $valid, 'Watermark column is required for incremental mode.'],
			'bad cron' => [['scheduleCron' => 'nope', 'destinationId' => ''] + $valid, 'Invalid cron expression.'],
			'no destination' => [['destinationId' => ' '] + $valid, 'A destination is required.'],
		];
	}//end invalidJobs()

	/**
	 * An invalid job is refused with the first failing check's message.
	 *
	 * @param array<string, mixed> $job     The job.
	 * @param string               $message The expected refusal.
	 *
	 * @return void
	 *
	 * @dataProvider invalidJobs
	 */
	public function testInvalidJobIsRefused(array $job, string $message): void {
		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage($message);
		$this->validate($job);
	}//end testInvalidJobIsRefused()

	/**
	 * A valid job passes, in full and in incremental mode, and every schema is checked in order.
	 *
	 * @return void
	 */
	public function testValidJobPasses(): void {
		$checked = [];
		$this->validate(self::VALID, $checked);
		$this->validate(['mode' => 'incremental', 'incrementalWatermarkColumn' => 'updated', 'format' => 'jsonl'] + self::VALID, $checked);
		$this->validate(['format' => 'parquet'] + self::VALID, $checked);
		$this->assertSame(['lead', 'client', 'lead', 'client', 'lead', 'client'], $checked);
	}//end testValidJobPasses()

	/**
	 * The column types inferred per column, with nullability.
	 *
	 * @return void
	 */
	public function testColumnDefinitionsInferEveryType(): void {
		$rows = [
			['b' => true, 'i' => 1, 'f' => 1.5, 'a' => ['x'], 's' => 'x', 'mixed' => 1, 'n' => null, 'sometimes' => 2],
			['b' => false, 'i' => 2, 'f' => 2.0, 'a' => [], 's' => 'y', 'mixed' => 'one'],
			['b' => true, 'i' => 3, 'f' => 0.1, 'a' => ['k' => 'v'], 's' => 'z', 'mixed' => 2, 'n' => null, 'sometimes' => 3],
		];

		$this->assertSame(
			[
				'b' => 'boolean',
				'i' => 'integer',
				'f' => 'double',
				'a' => 'object',
				's' => 'string',
				'mixed' => 'string',
				'n' => 'string|null',
				'sometimes' => 'integer|null',
			],
			$this->dataService([])->columnDefinitions(rows: $rows)
		);
		$this->assertSame([], $this->dataService([])->columnDefinitions(rows: []));
	}//end testColumnDefinitionsInferEveryType()

	/**
	 * The full descriptor of an incremental, filtered, allowlisted, gzip-compressed extraction.
	 *
	 * @return void
	 */
	public function testExtractDescriptor(): void {
		$rows = [
			['id' => 'a', 'status' => 'open', 'updated' => '2026-01-02', 'n' => 1],
			['id' => 'b', 'status' => 'won', 'updated' => '2026-01-03', 'n' => 2],
			['id' => 'c', 'status' => 'open', 'updated' => '2026-01-01', 'n' => null],
		];
		$job = [
			'mode' => 'incremental',
			'incrementalWatermarkColumn' => 'updated',
			'rowFilterExpression' => "status = 'open'",
			'columnAllowlist' => ['id', 'n'],
			'format' => 'jsonl',
			'compression' => 'gzip',
		];

		$file = $this->dataService($rows)->extractSchema(job: $job, schemaSlug: 'lead', watermarkFrom: '2025-12-31');
		$contents = $file['contents'];
		unset($file['contents']);

		$this->assertSame(
			[
				'schema' => 'lead',
				'rows' => 2,
				'size_bytes' => strlen($contents),
				'sha256' => hash('sha256', $contents),
				'compression_used' => 'gzip',
				'watermark_to' => '2026-01-03',
				'dropped_columns' => ['status', 'updated'],
				'column_definitions' => ['id' => 'string', 'n' => 'integer|null', '_deleted' => 'boolean'],
			],
			$file
		);
		$this->assertSame(
			"{\"id\":\"a\",\"n\":1,\"_deleted\":false}\n{\"id\":\"c\",\"n\":null,\"_deleted\":false}\n",
			gzdecode($contents)
		);
	}//end testExtractDescriptor()

	/**
	 * A full extraction keeps every column, defaults to uncompressed CSV and has no watermark.
	 *
	 * @return void
	 */
	public function testExtractDefaults(): void {
		$rows = [['id' => 'a', '_deleted' => true], ['id' => 'b']];
		$file = $this->dataService($rows)->extractSchema(job: ['columnAllowlist' => []], schemaSlug: 'lead');

		$this->assertSame(2, $file['rows']);
		$this->assertSame('none', $file['compression_used']);
		$this->assertNull($file['watermark_to']);
		$this->assertSame([], $file['dropped_columns']);
		$this->assertSame("id,_deleted\r\na,true\r\nb,\r\n", $file['contents']);
	}//end testExtractDefaults()

	/**
	 * Refusals: a malformed slug, an unconfigured schema, an incremental job without a column.
	 *
	 * @return void
	 */
	public function testExtractRefusals(): void {
		$service = $this->dataService([]);
		foreach (
			[
				['1x', ['mode' => 'full'], "Schema '1x' does not exist."],
				['ghost', ['mode' => 'full'], "Schema 'ghost' does not exist."],
				['lead', ['mode' => 'incremental'], 'Watermark column is required for incremental mode.'],
			] as [$slug, $job, $message]
		) {
			try {
				$service->extractSchema(job: $job, schemaSlug: $slug);
				$this->fail('No refusal for ' . $slug);
			} catch (OCSBadRequestException $e) {
				$this->assertSame($message, $e->getMessage());
			}
		}
	}//end testExtractRefusals()

	/**
	 * Run the private validateJob on a fresh service.
	 *
	 * @param array<string, mixed> $job     The job.
	 * @param array<int, string>   $checked Collects the schema slugs checked.
	 *
	 * @return void
	 */
	private function validate(array $job, array &$checked = []): void {
		$data = $this->createMock(ExportDataService::class);
		$data->method('schemaExists')->willReturnCallback(
			static function (string $slug) use (&$checked): bool {
				$checked[] = $slug;
				return $slug !== 'ghost';
			}
		);
		$service = new ExportJobService(
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(ObjectServiceInterface::class),
			$this->createMock(ExportDestinationService::class),
			$data,
			new CronExpressionHelper(),
			$this->createMock(LoggerInterface::class),
			new ExportRunSummary(cron: new CronExpressionHelper()),
		);
		$method = new \ReflectionMethod($service, 'validateJob');
		$method->invoke($service, $job);
	}//end validate()

	/**
	 * An extraction service over fixed rows, with the lead schema configured.
	 *
	 * @param array<int, array<string, mixed>> $rows The stored rows.
	 *
	 * @return ExportDataService
	 */
	private function dataService(array $rows): ExportDataService {
		$store = new class($rows) extends ObjectService {
			/**
			 * @param array<int, array<string, mixed>> $rows The stored rows.
			 */
			public function __construct(private array $rows) {
			}

			/**
			 * @param array<string, mixed> $config        The query configuration.
			 * @param bool                 $_rbac         Whether to enforce RBAC checks.
			 * @param bool                 $_multitenancy Whether to enforce tenant scoping.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return array_slice($this->rows, (int)($config['offset'] ?? 0), (int)($config['limit'] ?? 0));
			}
		};

		$config = ['register' => 'reg-1', 'lead_schema' => 'sch-lead'];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		return new ExportDataService(
			container: $this->createMock(ContainerInterface::class),
			appConfig: $appConfig,
			objectService: $store,
		);
	}//end dataService()
}//end class
