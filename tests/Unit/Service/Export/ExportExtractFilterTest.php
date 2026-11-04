<?php

/**
 * Tests for the row filter and column allowlist steps of ExportDataService::extractSchema().
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
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-006
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Export;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\Export\ExportDataService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * extractSchema() applies the job's row filter, and an incremental job keeps
 * its `_deleted` tombstone column even when the column allowlist leaves it
 * out, while a full job drops a stored `_deleted` column the allowlist does
 * not name. The rows are synthetic.
 *
 * @covers \OCA\Pipelinq\Service\Export\ExportDataService
 * @uses   \OCA\Pipelinq\Service\Export\AbstractExportService
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-006
 */
class ExportExtractFilterTest extends TestCase {
	private const ROWS = [
		['id' => 'a', 'status' => 'open', 'email' => 'a@example.org', 'deletedAt' => ''],
		['id' => 'b', 'status' => 'won', 'email' => 'b@example.org', 'deletedAt' => '2026-01-01'],
		['id' => 'c', 'status' => 'open', 'email' => 'c@example.org', '_deleted' => false],
	];

	/**
	 * The row filter keeps only the matching rows, before the allowlist drops columns.
	 *
	 * @return void
	 */
	public function testRowFilterKeepsMatchingRows(): void {
		$job = ['mode' => 'full', 'format' => 'jsonl', 'rowFilterExpression' => "status = 'open'", 'columnAllowlist' => ['id']];

		$file = $this->service()->extractSchema(job: $job, schemaSlug: 'lead');

		$this->assertSame(expected: 2, actual: $file['rows']);
		$this->assertSame(expected: ['{"id":"a"}', '{"id":"c"}'], actual: explode("\n", rtrim($file['contents'], "\n")));
		$this->assertSame(expected: ['status', 'email', 'deletedAt', '_deleted'], actual: $file['dropped_columns']);
	}//end testRowFilterKeepsMatchingRows()

	/**
	 * A negated filter keeps the other rows.
	 *
	 * @return void
	 */
	public function testNegatedRowFilterKeepsTheOtherRows(): void {
		$job = ['mode' => 'full', 'format' => 'jsonl', 'rowFilterExpression' => 'status != open', 'columnAllowlist' => ['id']];

		$file = $this->service()->extractSchema(job: $job, schemaSlug: 'lead');

		$this->assertSame(expected: ['{"id":"b"}'], actual: explode("\n", rtrim($file['contents'], "\n")));
	}//end testNegatedRowFilterKeepsTheOtherRows()

	/**
	 * An incremental job keeps `_deleted` next to the allowlisted columns; a full job does not.
	 *
	 * @return void
	 */
	public function testIncrementalAllowlistKeepsTheTombstone(): void {
		$incremental = ['mode' => 'incremental', 'incrementalWatermarkColumn' => 'deletedAt', 'format' => 'jsonl', 'columnAllowlist' => ['id']];
		$full = ['mode' => 'full', 'format' => 'jsonl', 'columnAllowlist' => ['id']];

		$withTombstone = $this->service()->extractSchema(job: $incremental, schemaSlug: 'lead');
		$withoutTombstone = $this->service()->extractSchema(job: $full, schemaSlug: 'lead');

		$this->assertSame(
			expected: ['{"id":"a","_deleted":false}', '{"id":"b","_deleted":true}', '{"id":"c","_deleted":false}'],
			actual: explode("\n", rtrim($withTombstone['contents'], "\n"))
		);
		$this->assertSame(expected: ['status', 'email', 'deletedAt'], actual: $withTombstone['dropped_columns']);
		$this->assertSame(expected: ['{"id":"a"}', '{"id":"b"}', '{"id":"c"}'], actual: explode("\n", rtrim($withoutTombstone['contents'], "\n")));
		$this->assertSame(expected: ['status', 'email', 'deletedAt', '_deleted'], actual: $withoutTombstone['dropped_columns']);
	}//end testIncrementalAllowlistKeepsTheTombstone()

	/**
	 * A service over the fixed rows.
	 *
	 * @return ExportDataService
	 */
	private function service(): ExportDataService {
		$store = new class(self::ROWS) extends ObjectService {
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
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		return new ExportDataService(
			container: $this->createMock(originalClassName: ContainerInterface::class),
			appConfig: $appConfig,
			objectService: $store,
		);
	}//end service()
}//end class
