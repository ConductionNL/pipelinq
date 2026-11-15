<?php

/**
 * Characterisation tests for the run of MigrateAvgVerzoekenToOrDsar.
 *
 * Pins what the migration reads (the source query, the satellite queries
 * and their limits), what it reports (the summary line on the output and
 * in the log, and the two conditional warnings) and its two early stops,
 * so the run can be restructured without changing them.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Repair\MigrateAvgVerzoekenToOrDsar;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the reads and the report of the AVG to DSAR migration.
 */
final class MigrateAvgVerzoekenToOrDsarRunTest extends TestCase {
	/**
	 * Schema-config-key => id.
	 *
	 * @var array<string, string>
	 */
	private const IDS = [
		'register' => '11',
		'avgVerzoek_schema' => '40',
		'bewijsItem_schema' => '41',
		'weigering_schema' => '42',
		'redactieActie_schema' => '43',
	];

	/**
	 * The findAll calls, as [config, rbac, multitenancy].
	 *
	 * @var array<int, array{0: array<string, mixed>, 1: bool, 2: bool}>
	 */
	private array $reads = [];

	/**
	 * Output and log lines, as [channel, text].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $lines = [];

	/**
	 * findAll rows by schema id; a string is the message the read throws.
	 *
	 * @var array<string, array<int, array<string, mixed>>|string>
	 */
	private array $store = [];

	/**
	 * The source ids whose case write fails.
	 *
	 * @var array<int, string>
	 */
	private array $failingWrites = [];

	/**
	 * A source read that fails stops the run with a warning.
	 *
	 * @return void
	 */
	public function testAFailingSourceReadStopsWithAWarning(): void {
		$this->store['40'] = 'store down';

		$this->step()->run($this->recordingOutput());

		$this->assertSame([['output.warning', 'Pipelinq AVG→DSAR migration: could not read avgVerzoek objects — store down']], $this->lines);
		$this->assertSame([[['filters' => ['register' => '11', 'schema' => '40'], 'limit' => 5000], false, false]], $this->reads);
	}//end testAFailingSourceReadStopsWithAWarning()

	/**
	 * No source objects stops the run with an info line and reads nothing else.
	 *
	 * @return void
	 */
	public function testNoSourceObjectsStopsWithAnInfoLine(): void {
		$this->step()->run($this->recordingOutput());

		$this->assertSame([['output.info', 'Pipelinq AVG→DSAR migration: no avgVerzoek objects present.']], $this->lines);
		$this->assertCount(1, $this->reads);
	}//end testNoSourceObjectsStopsWithAnInfoLine()

	/**
	 * A run reads the satellites, migrates what it can and reports every
	 * bucket, with both warnings when something was left or failed.
	 *
	 * @return void
	 */
	public function testARunReadsTheSatellitesAndReportsEveryBucket(): void {
		$this->store['40'] = [
			['id' => 'v1', 'artikel' => 'art-15-inzage', 'status' => 'ingediend'],
			['id' => 'v2', 'artikel' => 'geen-avg', 'status' => 'ingediend'],
			['id' => 'v3', 'artikel' => 'art-17-wissing', 'status' => 'ingediend'],
			'not an object',
		];
		$this->store['41'] = 'satellite down';
		$this->failingWrites = ['v3'];

		$this->step()->run($this->recordingOutput());

		$summary = 'Pipelinq AVG→DSAR migration: 1 migrated, 0 skipped (already migrated or trashed), 1 unmappable, 1 failed (of 4 source objects).';
		$this->assertSame(
			[
				['output.info', $summary],
				['log.info', $summary],
				['output.warning', 'Pipelinq AVG→DSAR migration: 1 verzoek(en) have no dataSubjectRequest equivalent (e.g. artikel "geen-avg") and were left in place. They are not failures — but they will not disappear on a re-run either, so decide what should happen to them before retiring the avgVerzoek fragment.'],
				['output.warning', 'Pipelinq AVG→DSAR migration: 1 object(s) failed — the avgVerzoek fragment must not be removed until a clean run.'],
			],
			array_values(array_filter($this->lines, static fn (array $line): bool => $line[0] !== 'log.warning' && $line[0] !== 'log.error'))
		);
		$this->assertSame(
			[
				[['filters' => ['register' => '11', 'schema' => '40'], 'limit' => 5000], false, false],
				[['filters' => ['register' => '11', 'schema' => '41'], 'limit' => 10000], true, true],
				[['filters' => ['register' => '11', 'schema' => '43'], 'limit' => 10000], true, true],
				[['filters' => ['register' => '11', 'schema' => '42'], 'limit' => 10000], true, true],
			],
			array_slice($this->reads, 0, 4)
		);
	}//end testARunReadsTheSatellitesAndReportsEveryBucket()

	/**
	 * A clean run reports its summary without warnings.
	 *
	 * @return void
	 */
	public function testACleanRunHasNoWarnings(): void {
		$this->store['40'] = [['id' => 'v1', 'artikel' => 'art-15-inzage', 'status' => 'ingediend']];

		$this->step()->run($this->recordingOutput());

		$summary = 'Pipelinq AVG→DSAR migration: 1 migrated, 0 skipped (already migrated or trashed), 0 unmappable, 0 failed (of 1 source objects).';
		$this->assertSame([['output.info', $summary], ['log.info', $summary]], $this->lines);
	}//end testACleanRunHasNoWarnings()

	/**
	 * A satellite schema that is not provisioned is not read.
	 *
	 * @return void
	 */
	public function testAnUnprovisionedSatelliteIsNotRead(): void {
		$this->store['40'] = [['id' => 'v1', 'artikel' => 'art-15-inzage', 'status' => 'ingediend']];

		$this->step(ids: array_diff_key(self::IDS, ['weigering_schema' => true]))->run($this->recordingOutput());

		$schemas = array_map(static fn (array $read): string => $read[0]['filters']['schema'], array_slice($this->reads, 0, 3));
		$this->assertSame(['40', '41', '43'], $schemas);
	}//end testAnUnprovisionedSatelliteIsNotRead()

	/**
	 * A recording output.
	 *
	 * @return IOutput
	 */
	private function recordingOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		foreach (['info', 'warning'] as $level) {
			$output->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->lines[] = ['output.' . $level, $message];
				}
			);
		}

		return $output;
	}//end recordingOutput()

	/**
	 * The step over an in-memory ObjectService and a recording logger.
	 *
	 * @param array<string, string> $ids The provisioned schema ids by config key.
	 *
	 * @return MigrateAvgVerzoekenToOrDsar
	 */
	private function step(array $ids = self::IDS): MigrateAvgVerzoekenToOrDsar {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $ids[$key] ?? $default
		);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('runAsSystem')->willReturnCallback(static fn (callable $operation) => $operation());
		$objects->method('findAll')->willReturnCallback(
			function (array $config, bool $rbac = true, bool $multitenancy = true): array {
				$this->reads[] = [$config, $rbac, $multitenancy];
				$rows = ($this->store[(string)($config['filters']['schema'] ?? '')] ?? []);
				if (is_string($rows) === true) {
					throw new RuntimeException($rows);
				}

				return $rows;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $data, array $extend, $register, $schema): ObjectEntity {
				$source = (string)($data['notes'] ?? '');
				foreach ($this->failingWrites as $id) {
					if ((string)$schema === 'dataSubjectRequest' && str_contains($source, '"' . $id . '"') === true) {
						throw new RuntimeException('write refused');
					}
				}

				$entity = new ObjectEntity();
				$entity->setUuid('dsar-' . (string)($data['id'] ?? 'x'));
				return $entity;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->lines[] = ['log.' . $level, $message];
				}
			);
		}

		return new MigrateAvgVerzoekenToOrDsar(appConfig: $appConfig, container: $container, logger: $logger);
	}//end step()
}//end class
