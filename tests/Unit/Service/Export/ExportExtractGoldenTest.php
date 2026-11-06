<?php

/**
 * Golden test for ExportDataService::extractSchema().
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-005
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Export;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\Export\ExportDataService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Every findAll() page request (filters, limit, offset) and the file
 * descriptor of extractSchema() for full and incremental jobs, row caps, a
 * page that fails and short schemas, compared with ExportExtractGolden.json.
 * The rows are synthetic. Set EXPORT_EXTRACT_GOLDEN_RECORD=1 to rewrite the
 * file, then run once more without it.
 *
 * @covers \OCA\Pipelinq\Service\Export\ExportDataService
 * @uses   \OCA\Pipelinq\Service\Export\AbstractExportService
 * @uses \OCA\Pipelinq\Util\CastingPayloadToArray
 * @uses \OCA\Pipelinq\Util\EntityPayload
 * @uses \OCA\Pipelinq\Util\SerialisedPayload
 * @uses \OCA\Pipelinq\Util\ObjectToPlainArray
 * @uses \OCA\Pipelinq\Util\AnyPayloadToArray
 * @uses \OCA\Pipelinq\Util\NullablePayloadToArray
 * @uses \OCA\Pipelinq\Util\OptionalPayloadToArray
 * @uses \OCA\Pipelinq\Util\PayloadToArray
 * @uses \OCA\Pipelinq\Util\SerialisedToArray
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-005
 */
class ExportExtractGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/ExportExtractGolden.json';

	/**
	 * Run every case and compare with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$full = ['mode' => 'full', 'format' => 'jsonl'];
		$incremental = ['mode' => 'incremental', 'incrementalWatermarkColumn' => 'updated', 'format' => 'csv'];
		$cases = [
			'full, three pages'              => ['job' => $full, 'rows' => 1203],
			'full, exactly one page'         => ['job' => $full, 'rows' => 500],
			'full, empty'                    => ['job' => $full, 'rows' => 0],
			'full, cap inside first page'    => ['job' => $full, 'rows' => 1203, 'cap' => 7],
			'full, cap on a page edge'       => ['job' => $full, 'rows' => 1203, 'cap' => 500],
			'full, cap in a later page'      => ['job' => $full, 'rows' => 1203, 'cap' => 777],
			'full, cap above the rows'       => ['job' => $full, 'rows' => 12, 'cap' => 100],
			'full, second page fails'        => ['job' => $full, 'rows' => 1203, 'failAt' => 500],
			'full, first page fails'         => ['job' => $full, 'rows' => 1203, 'failAt' => 0],
			'full, watermark ignored'        => ['job' => ['incrementalWatermarkColumn' => 'updated'] + $full, 'rows' => 3, 'from' => '2026-01-01'],
			'incremental from a watermark'   => ['job' => $incremental, 'rows' => 600, 'from' => '2026-01-01T00:00:00+00:00'],
			'incremental without watermark'  => ['job' => $incremental, 'rows' => 4],
			'incremental, empty watermark'   => ['job' => $incremental, 'rows' => 4, 'from' => ''],
			'incremental on register column' => ['job' => ['incrementalWatermarkColumn' => 'register'] + $incremental, 'rows' => 4, 'from' => 'x'],
			'incremental, capped'            => ['job' => $incremental, 'rows' => 1203, 'from' => '2026', 'cap' => 501],
			'incremental, no column'         => ['job' => ['incrementalWatermarkColumn' => ''] + $incremental, 'rows' => 4],
			'unknown schema'                 => ['job' => $full, 'rows' => 4, 'slug' => 'nothing'],
			'bad slug'                       => ['job' => $full, 'rows' => 4, 'slug' => '1x'],
		];

		$actual = [];
		foreach ($cases as $name => $case) {
			$actual[$name] = $this->extract(case: $case);
		}

		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (getenv('EXPORT_EXTRACT_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, json_encode($actual, $flags) . "\n");
			$this->markTestSkipped(message: 'recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		foreach ($expected as $name => $want) {
			$this->assertSame(expected: json_encode($want, $flags), actual: json_encode(($actual[$name] ?? null), $flags), message: $name);
		}

		$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
	}//end testGolden()

	/**
	 * Run one case: the page requests and the descriptor, or the error.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function extract(array $case): array {
		$store = new class((int)$case['rows'], ($case['failAt'] ?? null)) extends ObjectService {
			/**
			 * The page requests made.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $requests = [];

			/**
			 * @param int      $total  Number of stored rows.
			 * @param int|null $failAt Offset at which findAll throws.
			 */
			public function __construct(private int $total, private ?int $failAt) {
			}

			/**
			 * @param array<string, mixed> $config        The query configuration.
			 * @param bool                 $_rbac         Whether to enforce RBAC checks.
			 * @param bool                 $_multitenancy Whether to enforce tenant scoping.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->requests[] = $config;
				$offset = (int)($config['offset'] ?? 0);
				if ($this->failAt === $offset) {
					throw new \RuntimeException('store down');
				}

				$rows = [];
				$end = min($this->total, ($offset + (int)($config['limit'] ?? 0)));
				for ($i = $offset; $i < $end; $i++) {
					$rows[] = ['id' => 'r' . $i, 'updated' => sprintf('2026-02-%02dT10:00:00+00:00', (($i % 28) + 1)), 'amount' => ($i * 3)];
				}

				return $rows;
			}
		};

		$config = ['register' => 'reg-1', 'lead_schema' => 'sch-lead'];
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$service = new ExportDataService(
			container: $this->createMock(originalClassName: ContainerInterface::class),
			appConfig: $appConfig,
			objectService: $store,
		);

		try {
			$file = $service->extractSchema(
				job: $case['job'],
				schemaSlug: ($case['slug'] ?? 'lead'),
				watermarkFrom: ($case['from'] ?? null),
				rowCap: ($case['cap'] ?? null)
			);
			$file['contents'] = hash('sha256', $file['contents']);
			$result = ['file' => $file];
		} catch (Throwable $e) {
			$result = ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

		return $result + ['requests' => $store->requests];
	}//end extract()
}//end class
