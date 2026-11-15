<?php

/**
 * Golden test for the InitSlaStatus repair step.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use DateTimeInterface;
use OCA\Pipelinq\Repair\InitSlaStatus;
use OCA\Pipelinq\Service\SlaEngineService;
use OCA\Pipelinq\Service\TicketService;
use OCA\Pipelinq\Tests\Unit\Support\Autowire;
use OCA\Pipelinq\Tests\Unit\Support\ComparesGolden;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Runs the repair step over configured and unconfigured surfaces, paged
 * reads, entity and array rows, rows already tracked, rows without a policy
 * and failing reads and writes: every output line, log entry and call made to
 * the SLA engine and the object service, in order. The step is built by
 * autowiring from the leaf doubles only. Compared with
 * InitSlaStatusGolden.json; set GOLDEN_RECORD=1 to rewrite it, then run once
 * more without it.
 *
 * @covers \OCA\Pipelinq\Repair\InitSlaStatus
 * @uses   \OCA\Pipelinq\Service\Sla\SlaSweepRun
 * @uses   \OCA\Pipelinq\Service\Sla\SlaSweepSource
 * @uses   \OCA\Pipelinq\Service\Sla\SlaSweepSurface
 */
class InitSlaStatusGoldenTest extends TestCase {
	use ComparesGolden;

	/**
	 * What the current case did, in order.
	 *
	 * @var array<int, mixed>
	 */
	public array $trace = [];

	/**
	 * Run every case and compare it with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$actual[$name] = $this->runCase(case: $case);
		}

		$this->assertGolden(file: __DIR__.'/InitSlaStatusGolden.json', actual: $actual);
	}//end testGolden()

	/**
	 * The cases.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$rows = [
			['uuid' => 'u-1', 'slaTier' => 'gold', 'organisationId' => 'org-1', 'contractId' => 'k-1', 'createdAt' => '2026-10-01T08:00:00+00:00'],
			['id' => 'u-2', 'customerTier' => 'silver', 'client' => 'cl-2', 'created' => '2026-10-02T09:00:00+00:00'],
			['uuid' => 'u-3', 'slaStatus' => ['policyId' => 'p-1']],
			['uuid' => 'u-4', 'slaStatus' => ['policyId' => '']],
			['uuid' => 'u-5', 'slaStatus' => 'broken', 'opened_at' => 'not a date', '@self.createdAt' => '2026-10-05T00:00:00+00:00'],
			['uuid' => 'u-nopolicy'],
			['title' => 'no id'],
			['uuid' => 'u-throws'],
			['uuid' => 'u-6', 'createdAt' => 'garbage'],
		];
		$many = [];
		for ($i = 0; $i < 260; $i++) {
			$many[] = ['uuid' => 'm-'.$i, 'createdAt' => '2026-10-01T00:00:00+00:00'];
		}

		return [
			'no register'              => ['register' => ''],
			'no object service'        => ['noService' => true],
			'no surfaces'              => ['ticketSchema' => '', 'callbackSchema' => ''],
			'all surfaces'             => ['rows' => $rows],
			'entity rows'              => ['rows' => $rows, 'entities' => true],
			'tickets only'             => ['rows' => $rows, 'callbackSchema' => ''],
			'callbacks only'           => ['rows' => $rows, 'ticketSchema' => ''],
			'read fails'               => ['rows' => $rows, 'readFails' => 'callback-schema'],
			'save fails'               => ['rows' => $rows, 'saveFails' => true],
			'no runAsSystem'           => ['rows' => $rows, 'legacy' => true],
			'two pages'                => ['rows' => $many, 'ticketSchema' => ''],
			'iterator rows'            => ['rows' => $rows, 'iterator' => true, 'ticketSchema' => ''],
			'endless pages stop'       => ['endless' => true, 'ticketSchema' => ''],
		];
	}//end cases()

	/**
	 * Run one case against a freshly autowired step.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$this->trace = [];
		$config = ['register' => ($case['register'] ?? 'reg-1'), 'callback_schema' => ($case['callbackSchema'] ?? 'callback-schema')];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($config): string {
				$this->trace[] = ['config' => $key];
				return ($config[$key] ?? $default);
			}
		);

		$tickets = $this->createMock(TicketService::class);
		$tickets->method('getSchemaId')->willReturnCallback(
			function () use ($case): string {
				$this->trace[] = ['ticketSchema' => true];
				return ($case['ticketSchema'] ?? 'ticket-schema');
			}
		);

		$service = $this->objectService(case: $case);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($case, $service): object {
				$this->trace[] = ['container' => $id];
				if (($case['noService'] ?? false) === true) {
					throw new RuntimeException('OpenRegister missing');
				}

				return $service;
			}
		);

		$step = Autowire::build(
			class: InitSlaStatus::class,
			doubles: [
				SlaEngineService::class => $this->engine(),
				ContainerInterface::class => $container,
				IAppConfig::class => $appConfig,
				TicketService::class => $tickets,
				LoggerInterface::class => $this->recorder(interface: LoggerInterface::class, methods: ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'], tag: 'log'),
			]
		);
		$step->run($this->recorder(interface: IOutput::class, methods: ['info', 'warning', 'startProgress', 'advance', 'finishProgress', 'debug'], tag: 'output'));

		return ['name' => $step->getName(), 'trace' => $this->trace];
	}//end runCase()

	/**
	 * An engine that has a policy for every object but `u-nopolicy` and throws for `u-throws`.
	 *
	 * @return SlaEngineService
	 */
	private function engine(): SlaEngineService {
		$engine = $this->createMock(SlaEngineService::class);
		$engine->method('resolvePolicyForObject')->willReturnCallback(
			function (string $objectType, string $objectId, array $metadata): ?array {
				$this->trace[] = ['resolvePolicy' => [$objectType, $objectId, $metadata]];
				if ($objectId === 'u-throws') {
					throw new RuntimeException('policy store down');
				}

				return ($objectId === 'u-nopolicy' ? null : ['id' => 'p-'.$objectType]);
			}
		);
		$engine->method('initialiseStatus')->willReturnCallback(
			function (array $policy, DateTimeInterface $startTime): array {
				$started = $startTime->format(DATE_ATOM);
				if (abs($startTime->getTimestamp() - time()) < 300) {
					$started = 'now:'.$startTime->getTimezone()->getName();
				}

				$this->trace[] = ['initialiseStatus' => [$policy['id'], $started]];
				return ['policyId' => $policy['id'], 'startedAt' => $started];
			}
		);
		return $engine;
	}//end engine()

	/**
	 * A double that records every call to the given methods.
	 *
	 * @param string             $interface The interface.
	 * @param array<int, string> $methods   The methods.
	 * @param string             $tag       The trace tag.
	 *
	 * @return object
	 */
	private function recorder(string $interface, array $methods, string $tag): object {
		$double = $this->createMock($interface);
		foreach ($methods as $method) {
			$double->method($method)->willReturnCallback(
				function (...$args) use ($method, $tag): void {
					$this->trace[] = [$tag => array_merge([$method], array_map(static fn (mixed $a): mixed => (is_object($a) === true ? get_class($a) : $a), $args))];
				}
			);
		}

		return $double;
	}//end recorder()

	/**
	 * A fake object service over the case's rows, recording every call.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return object
	 */
	private function objectService(array $case): object {
		$test = $this;
		if (($case['legacy'] ?? false) === true) {
			return new class($case, $test) {
				use InitSlaStatusFakeStore;
			};
		}

		return new class($case, $test) {
			use InitSlaStatusFakeStore;

			/**
			 * @param callable $operation The write.
			 *
			 * @return mixed
			 */
			public function runAsSystem(callable $operation): mixed {
				$this->test->trace[] = ['runAsSystem' => true];
				return $operation();
			}
		};
	}//end objectService()
}//end class

/**
 * The shared body of the fake object services.
 */
trait InitSlaStatusFakeStore {
	/**
	 * @param array<string, mixed>    $case The case.
	 * @param InitSlaStatusGoldenTest $test The test, for call capture.
	 */
	public function __construct(private array $case, public InitSlaStatusGoldenTest $test) {
	}

	/**
	 * @param array<string, mixed> $config The query.
	 *
	 * @return iterable<mixed>
	 */
	public function findAll(array $config): iterable {
		$this->test->trace[] = ['findAll' => $config];
		if (($this->case['readFails'] ?? '') === ($config['filters']['schema'] ?? '')) {
			throw new RuntimeException('read failed');
		}

		if (($this->case['endless'] ?? false) === true) {
			return array_fill(0, (int)$config['limit'], ['uuid' => 'e', 'slaStatus' => ['policyId' => 'p']]);
		}

		$rows = array_slice(($this->case['rows'] ?? []), (int)$config['offset'], (int)$config['limit']);
		if (($this->case['entities'] ?? false) === true) {
			$rows = array_map(
				static function (array $row): object {
					return new class($row) {
						/**
						 * @param array<string, mixed> $row The payload.
						 */
						public function __construct(private array $row) {
						}

						/**
						 * @return array<string, mixed>
						 */
						public function getObject(): array {
							return $this->row;
						}

						/**
						 * @return string|null
						 */
						public function getUuid(): ?string {
							return ($this->row['uuid'] ?? null);
						}
					};
				},
				$rows
			);
		}

		if (($this->case['iterator'] ?? false) === true) {
			return new \ArrayIterator($rows);
		}

		return $rows;
	}

	/**
	 * @param array<string, mixed>  $object   The object.
	 * @param array<int, string>    $extend   Extends.
	 * @param string|null           $register The register.
	 * @param string|null           $schema   The schema.
	 * @param string|null           $uuid     The uuid.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(array $object, array $extend = [], ?string $register = null, ?string $schema = null, ?string $uuid = null): array {
		$this->test->trace[] = ['saveObject' => [$object, $extend, $register, $schema, $uuid]];
		if (($this->case['saveFails'] ?? false) === true) {
			throw new RuntimeException('locked');
		}

		return $object;
	}
}
