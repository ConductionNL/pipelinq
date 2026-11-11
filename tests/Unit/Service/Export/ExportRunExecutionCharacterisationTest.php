<?php

/**
 * Characterisation test for ExportExecutionService::executeRun().
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
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-004-02
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Export;

use OCA\Pipelinq\Service\Export\ExportDataService;
use OCA\Pipelinq\Service\Export\ExportDestinationService;
use OCA\Pipelinq\Service\Export\ExportExecutionService;
use OCA\Pipelinq\Service\Export\ExportJobService;
use OCA\Pipelinq\Service\Export\ExportRunService;
use OCA\Pipelinq\Service\Export\ExportUploadService;
use OCA\Pipelinq\Service\Export\SchemaEvolutionService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every collaborator call, in order, and the answer of one run.
 */
class ExportRunExecutionCharacterisationTest extends TestCase {
	/**
	 * Calls seen, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $seen = [];

	/**
	 * Scenarios.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function scenarios(): array {
		$file = static fn (int $rows, ?string $to): array => ['rows' => $rows, 'watermark_to' => $to, 'column_definitions' => [['name' => 'c' . $rows]]];
		return [
			'two schemas, newest watermark wins' => [[
				'run' => ['id' => 'r1', 'jobId' => 'j1', 'watermarkFrom' => '2026-01-01'],
				'job' => ['destinationId' => 'd1', 'sourceSchemas' => ['leads', 'deals'], 'partitionBy' => 'day'],
				'destination' => ['type' => 's3'],
				'files' => ['leads' => $file(3, '2026-02-01'), 'deals' => $file(4, '2026-03-01')],
				'upload' => 'all_succeeded',
				'drift' => ['leads' => [['added' => 'x']]],
			]],
			'older watermark after newer, no watermarkFrom, compression kept' => [[
				'run' => ['uuid' => 'r2', 'jobId' => 'j2'],
				'job' => ['sourceSchemas' => ['a', 'b', 'c'], 'compression' => 'zip'],
				'destination' => ['compression' => 'gzip'],
				'files' => ['a' => $file(1, '2026-05-01'), 'b' => $file(2, '2026-04-01'), 'c' => $file(5, null)],
				'upload' => 'partial',
			]],
			'no schemas, failed upload' => [[
				'run' => ['id' => 'r3', 'jobId' => 'j3', 'watermarkFrom' => ''],
				'job' => [],
				'destination' => [],
				'files' => [],
				'upload' => 'all_failed',
			]],
			'unknown upload status, recordRun fails' => [[
				'run' => ['id' => 'r4', 'jobId' => 'j4'],
				'job' => ['sourceSchemas' => [7]],
				'destination' => [],
				'files' => ['7' => $file(0, null)],
				'upload' => 'weird',
				'recordFails' => true,
			]],
			'extraction throws' => [[
				'run' => ['id' => 'r5', 'jobId' => 'j5'],
				'job' => ['sourceSchemas' => ['a']],
				'destination' => [],
				'files' => [],
				'upload' => 'all_succeeded',
				'extractThrows' => true,
			]],
			'lock held' => [[
				'run' => ['id' => 'r6', 'jobId' => 'j6'],
				'locked' => true,
			]],
			'no job id skips the lock' => [[
				'run' => ['id' => 'r7'],
				'job' => ['sourceSchemas' => ['a']],
				'destination' => [],
				'files' => ['a' => $file(2, null)],
				'upload' => 'all_succeeded',
			]],
		];
	}//end scenarios()

	/**
	 * One scenario: compare the full call trace with the expected one.
	 *
	 * @param array<string, mixed> $case The scenario.
	 *
	 * @return void
	 */
	#[DataProvider('scenarios')]
	public function testScenario(array $case): void {
		$answer = $this->service(case: $case)->executeRun($case['run']);

		self::assertSame(['answered', $this->seen[count($this->seen) - 1]], ['answered', ['return', $answer]]);
		self::assertSame($this->expectedTrace(case: $case), $this->seen);
	}//end testScenario()

	/**
	 * The trace the old code produced, written out by hand.
	 *
	 * @param array<string, mixed> $case The scenario.
	 *
	 * @return array<int, array<int, mixed>>
	 */
	private function expectedTrace(array $case): array {
		$run = $case['run'];
		$runId = (string)($run['id'] ?? $run['uuid'] ?? '');
		$jobId = (string)($run['jobId'] ?? '');
		$t = [];
		if ($jobId !== '') {
			$t[] = ['lock.add', $jobId];
		}

		if (($case['locked'] ?? false) === true) {
			$t[] = ['info', 'Pipelinq: export run skipped (overlap)', ['runId' => $runId, 'jobId' => $jobId]];
			$t[] = ['markSkippedOverlap', $runId, 'A run for this job is already executing.'];
			$t[] = ['return', ['skipped' => $runId]];
			return $t;
		}

		$job = $case['job'];
		$t[] = ['getJob', $jobId];
		$t[] = ['markRunning', $runId];
		$t[] = ['getDestination', (string)($job['destinationId'] ?? '')];
		$destination = $case['destination'];
		$destination['compression'] = ($destination['compression'] ?? 'none');
		$watermark = (string)($run['watermarkFrom'] ?? '');
		$files = [];
		$rows = 0;
		$to = null;
		foreach (($job['sourceSchemas'] ?? []) as $slug) {
			$slug = (string)$slug;
			$t[] = ['extractSchema', array_merge($job, ['compression' => $destination['compression']]), $slug, ($watermark === '' ? null : $watermark), null];
			if (($case['extractThrows'] ?? false) === true) {
				$t[] = ['error', 'Pipelinq: export run failed', ['runId' => $runId, 'error' => 'disk full']];
				$t[] = ['completeRun', $runId, ['status' => 'failed', 'errorMessage' => 'disk full']];
				$t[] = ['recordRun', $jobId, ['completed' => $runId]];
				$t = $this->withRelease(trace: $t, jobId: $jobId);
				$t[] = ['return', ['completed' => $runId]];
				return $t;
			}

			$file = $case['files'][$slug];
			$files[] = $file;
			$rows += $file['rows'];
			if ($file['watermark_to'] !== null && ($to === null || strcmp($file['watermark_to'], $to) > 0)) {
				$to = $file['watermark_to'];
			}

			$t[] = ['findPreviousSnapshot', $jobId, $slug];
			$changes = $case['drift'][$slug] ?? [];
			$t[] = ['compareToSnapshot', $file['column_definitions'], ['prev' => $slug]];
			if ($changes !== []) {
				$t[] = ['info', 'Pipelinq: export schema drift detected', ['schema' => $slug, 'changes' => $changes]];
			}

			$t[] = ['createSnapshot', $runId, $slug, $file['column_definitions'], $changes];
		}//end foreach

		$t[] = ['uploadFiles', $destination, $files, ['run_id' => $runId, 'timestamp' => 'TS', 'partition' => (string)($job['partitionBy'] ?? '')]];
		$status = ['all_succeeded' => 'succeeded', 'partial' => 'partial'][$case['upload']] ?? 'failed';
		$t[] = ['completeRun', $runId, [
			'status' => $status,
			'rowCount' => $rows,
			'byteCount' => 10,
			'fileCount' => count($files),
			'manifest' => ['m' => $runId],
			'watermarkTo' => $to,
			'destinationAck' => 'ack',
			'errorMessage' => null,
		]];
		$t[] = ['recordRun', $jobId, ['completed' => $runId]];
		if (($case['recordFails'] ?? false) === true) {
			$t[] = ['warning', 'Pipelinq: could not keep the last run on its export job', ['jobId' => $jobId, 'error' => 'job gone']];
		}

		$t = $this->withRelease(trace: $t, jobId: $jobId);
		$t[] = ['return', ['completed' => $runId]];
		return $t;
	}//end expectedTrace()

	/**
	 * Append the lock release when the run has a job id.
	 *
	 * @param array<int, array<int, mixed>> $trace The trace so far.
	 * @param string                        $jobId The job id.
	 *
	 * @return array<int, array<int, mixed>>
	 */
	private function withRelease(array $trace, string $jobId): array {
		if ($jobId !== '') {
			$trace[] = ['lock.remove', $jobId];
		}

		return $trace;
	}//end withRelease()

	/**
	 * Build the service over recording fakes.
	 *
	 * @param array<string, mixed> $case The scenario.
	 *
	 * @return ExportExecutionService
	 */
	private function service(array $case): ExportExecutionService {
		$this->seen = [];
		$rec = function (string $name): \Closure {
			return function (...$args) use ($name) {
				$this->seen[] = array_merge([$name], $args);
			};
		};
		$jobs = $this->createMock(ExportJobService::class);
		$jobs->method('getJob')->willReturnCallback(function (string $id) use ($case, $rec): array {
			$rec('getJob')($id);
			return $case['job'];
		});
		$jobs->method('recordRun')->willReturnCallback(function (string $jobId, array $run) use ($case, $rec): array {
			$rec('recordRun')($jobId, $run);
			if (($case['recordFails'] ?? false) === true) {
				throw new RuntimeException('job gone');
			}

			return [];
		});
		$runs = $this->createMock(ExportRunService::class);
		$runs->method('markRunning')->willReturnCallback(function (string $runId) use ($rec): array {
			$rec('markRunning')($runId);
			return [];
		});
		$runs->method('markSkippedOverlap')->willReturnCallback(function (string $runId, string $reason) use ($rec): array {
			$rec('markSkippedOverlap')($runId, $reason);
			$this->seen[] = ['return', ['skipped' => $runId]];
			return ['skipped' => $runId];
		});
		$runs->method('completeRun')->willReturnCallback(function (string $runId, array $outcome) use ($rec): array {
			$rec('completeRun')($runId, $outcome);
			return ['completed' => $runId];
		});
		$runs->method('findPreviousSnapshot')->willReturnCallback(function (string $jobId, string $schemaName) use ($rec): array {
			$rec('findPreviousSnapshot')($jobId, $schemaName);
			return ['prev' => $schemaName];
		});
		$runs->method('createSnapshot')->willReturnCallback(function (...$args) use ($rec): array {
			$rec('createSnapshot')(...$args);
			return [];
		});
		$data = $this->createMock(ExportDataService::class);
		$data->method('extractSchema')->willReturnCallback(function (array $job, string $slug, ?string $from = null, ?int $cap = null) use ($case, $rec): array {
			$rec('extractSchema')($job, $slug, $from, $cap);
			if (($case['extractThrows'] ?? false) === true) {
				throw new RuntimeException('disk full');
			}

			return $case['files'][$slug];
		});
		$uploads = $this->createMock(ExportUploadService::class);
		$uploads->method('uploadFiles')->willReturnCallback(function (array $destination, array $files, array $context = []) use ($case, $rec): array {
			self::assertMatchesRegularExpression('/^\d{8}T\d{6}Z$/', $context['timestamp']);
			$context['timestamp'] = 'TS';
			$rec('uploadFiles')($destination, $files, $context);
			return ['status' => $case['upload'], 'byte_count' => '10', 'file_count' => count($files), 'manifest' => ['m' => $context['run_id']], 'destination_ack' => 'ack', 'error_message' => null];
		});
		$destinations = $this->createMock(ExportDestinationService::class);
		$destinations->method('getDestination')->willReturnCallback(function (string $id) use ($case, $rec): array {
			$rec('getDestination')($id);
			return $case['destination'];
		});
		$evolution = $this->createMock(SchemaEvolutionService::class);
		$evolution->method('compareToSnapshot')->willReturnCallback(function (array $current, ?array $previous) use ($case, $rec): array {
			$rec('compareToSnapshot')($current, $previous);
			return $case['drift'][$previous['prev']] ?? [];
		});
		$cache = new class($rec, (($case['locked'] ?? false) === false)) implements ICache {
			/**
			 * Constructor.
			 *
			 * @param \Closure $rec  Recorder factory.
			 * @param bool     $free Whether the lock is free.
			 */
			public function __construct(private \Closure $rec, private bool $free) {
			}

			/**
			 * Add-if-absent.
			 *
			 * @param string $key   Key.
			 * @param mixed  $value Value.
			 * @param int    $ttl   TTL.
			 *
			 * @return bool
			 */
			public function add(string $key, $value, int $ttl = 0): bool {
				($this->rec)('lock.add')($key);
				return $this->free;
			}

			/**
			 * Remove.
			 *
			 * @param string $key Key.
			 *
			 * @return bool
			 */
			public function remove(string $key) {
				($this->rec)('lock.remove')($key);
				return true;
			}

			/**
			 * Unused.
			 *
			 * @param string $key Key.
			 *
			 * @return mixed
			 */
			public function get(string $key) {
				return null;
			}

			/**
			 * Unused.
			 *
			 * @param string $key   Key.
			 * @param mixed  $value Value.
			 * @param int    $ttl   TTL.
			 *
			 * @return bool
			 */
			public function set(string $key, $value, int $ttl = 0) {
				return true;
			}

			/**
			 * Unused.
			 *
			 * @param string $key Key.
			 *
			 * @return bool
			 */
			public function hasKey(string $key) {
				return false;
			}

			/**
			 * Unused.
			 *
			 * @param string $prefix Prefix.
			 *
			 * @return bool
			 */
			public function clear(string $prefix = '') {
				return true;
			}

			/**
			 * Available.
			 *
			 * @return bool
			 */
			public static function isAvailable(): bool {
				return true;
			}
		};
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context = []) use ($level, $rec): void {
				$rec($level)($message, $context);
			});
		}

		return new class($jobs, $runs, $data, $uploads, $destinations, $evolution, $factory, $logger, $this) extends ExportExecutionService {
			/**
			 * Record the answer as the last trace entry.
			 *
			 * @param mixed ...$deps The collaborators and the test.
			 */
			public function __construct(...$deps) {
				$this->test = array_pop($deps);
				parent::__construct(...$deps);
			}

			/**
			 * The test that records.
			 *
			 * @var ExportRunExecutionCharacterisationTest
			 */
			private ExportRunExecutionCharacterisationTest $test;

			/**
			 * Execute and record the answer.
			 *
			 * @param array<string, mixed> $run The run.
			 *
			 * @return array<string, mixed>
			 */
			public function executeRun(array $run): array {
				$answer = parent::executeRun($run);
				$this->test->recordReturn(answer: $answer);
				return $answer;
			}
		};
	}//end service()

	/**
	 * Append the answer to the trace (called by the recording subclass).
	 *
	 * @param array<string, mixed> $answer The answer.
	 *
	 * @return void
	 */
	public function recordReturn(array $answer): void {
		if (($this->seen[count($this->seen) - 1][0] ?? '') !== 'return') {
			$this->seen[] = ['return', $answer];
		}
	}//end recordReturn()
}//end class
