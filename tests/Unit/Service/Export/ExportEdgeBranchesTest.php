<?php

/**
 * Edge branches of the export services: the generated run id, a run without a
 * usable end time, the default file naming and a malformed schema snapshot.
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
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Export;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Adapter\ExportSinkRegistry;
use OCA\Pipelinq\Service\Export\AbstractExportService;
use OCA\Pipelinq\Service\Export\CronExpressionHelper;
use OCA\Pipelinq\Service\Export\ExportRunSummary;
use OCA\Pipelinq\Service\Export\ExportUploadService;
use OCA\Pipelinq\Service\Export\SchemaEvolutionService;
use OCA\Pipelinq\Tests\Unit\Support\FakeSlugResolver;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the edge branches of the export services.
 *
 * @covers \OCA\Pipelinq\Service\Export\AbstractExportService
 * @covers \OCA\Pipelinq\Service\Export\ExportRunSummary
 * @covers \OCA\Pipelinq\Service\Export\ExportUploadService
 * @covers \OCA\Pipelinq\Service\Export\SchemaEvolutionService
 * @uses   \OCA\Pipelinq\Adapter\ExportSinkRegistry
 * @uses   \OCA\Pipelinq\Service\ConnectorSourceRegister
 * @uses   \OCA\Pipelinq\Service\Export\CronExpressionHelper
 */
class ExportEdgeBranchesTest extends TestCase {

	/**
	 * The generated id is a version 4, RFC 4122 variant UUID and differs per call.
	 *
	 * @return void
	 */
	public function testUuidIsAVersionFourUuid(): void {
		$service = new class(
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(ObjectServiceInterface::class)
		) extends AbstractExportService {
			/**
			 * Expose the protected generator.
			 *
			 * @return string
			 */
			public function nextId(): string {
				return $this->uuid();
			}
		};

		$first  = $service->nextId();
		$second = $service->nextId();

		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $second);
		$this->assertNotSame($first, $second);
	}//end testUuidIsAVersionFourUuid()

	/**
	 * A run without a usable end or start time has no last-run moment, and the defaults fill the rest.
	 *
	 * @return void
	 */
	public function testRunWithoutAUsableTimeHasNoLastRunAt(): void {
		$summary = new ExportRunSummary(cron: new CronExpressionHelper());

		$this->assertSame(
			[
				'lastRunStatus' => 'failed',
				'lastRunAt'     => null,
				'lastRunRows'   => 0,
				'lastRunFiles'  => 0,
				'lastRunFilesExpected' => 0,
				'lastRunError'  => null,
			],
			$summary->lastRunSummary(run: ['endedAt' => 1700000000, 'errorMessage' => ''])
		);
		$this->assertNull($summary->lastRunSummary(run: ['endedAt' => '', 'status' => 'success'])['lastRunAt']);
		$this->assertNull($summary->lastRunSummary(run: [])['lastRunAt']);
	}//end testRunWithoutAUsableTimeHasNoLastRunAt()

	/**
	 * Without a naming convention the file is named schema, run id and timestamp; a trailing slash collapses.
	 *
	 * @return void
	 */
	public function testResolvePathFallsBackToTheDefaultNaming(): void {
		$service = new ExportUploadService(
			container: $this->createMock(ContainerInterface::class),
			appConfig: $this->createMock(IAppConfig::class),
			objectService: $this->createMock(ObjectServiceInterface::class),
			sinks: new ExportSinkRegistry([]),
			connectorRegister: FakeSlugResolver::connectorRegister(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->assertSame(
			'exports/client/client_r1_20260101T000000Z',
			$service->resolvePath(
				destination: ['pathTemplate' => 'exports/{schema}/', 'namingConvention' => ''],
				file: ['schema' => 'client'],
				context: ['run_id' => 'r1', 'timestamp' => '20260101T000000Z']
			)
		);
		$this->assertSame(
			'/data_r2_t',
			$service->resolvePath(destination: [], file: [], context: ['run_id' => 'r2', 'timestamp' => 't'])
		);
	}//end testResolvePathFallsBackToTheDefaultNaming()

	/**
	 * A snapshot whose column definitions are not a map reports no changes.
	 *
	 * @return void
	 */
	public function testMalformedSnapshotReportsNoChanges(): void {
		$service = new SchemaEvolutionService();

		$this->assertSame([], $service->compareToSnapshot(current: ['id' => 'integer'], previous: ['columnDefinitionsJson' => 'id:integer']));
		$this->assertNotSame([], $service->compareToSnapshot(current: ['id' => 'integer'], previous: ['columnDefinitionsJson' => ['id' => 'string']]));
	}//end testMalformedSnapshotReportsNoChanges()
}//end class
