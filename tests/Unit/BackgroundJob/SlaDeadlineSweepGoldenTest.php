<?php

/**
 * Golden test for the SLA deadline sweep.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use ArrayIterator;
use DateTimeInterface;
use OCA\Pipelinq\BackgroundJob\SlaDeadlineSweepJob;
use OCA\Pipelinq\Service\ComplaintSlaService;
use OCA\Pipelinq\Service\Sla\SlaEntitySweeper;
use OCA\Pipelinq\Service\Sla\SlaSweepSource;
use OCA\Pipelinq\Service\SlaEngineService;
use OCA\Pipelinq\Service\TicketService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * Runs the sweep against scripted collaborators and compares every call it
 * makes (by parameter name) with SlaDeadlineSweepGolden.json. Set
 * SLA_SWEEP_GOLDEN_RECORD=1 to rewrite the golden file.
 *
 * @covers \OCA\Pipelinq\BackgroundJob\SlaDeadlineSweepJob
 * @covers \OCA\Pipelinq\Service\Sla\SlaSweepSource
 * @covers \OCA\Pipelinq\Service\Sla\SlaEntitySweeper
 * @covers \OCA\Pipelinq\Service\Sla\SlaSweepRun
 * @covers \OCA\Pipelinq\Service\Sla\SlaSweepSurface
 * @covers \OCA\Pipelinq\Service\Sla\SlaSweptObject
 */
class SlaDeadlineSweepGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/SlaDeadlineSweepGolden.json';

	/**
	 * Calls recorded during one run, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $events = [];

	/**
	 * The scenario names.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function scenarios(): array {
		$names = [];
		foreach (array_keys(self::definitions()) as $name) {
			$names[$name] = [$name];
		}

		return $names;
	}//end scenarios()

	/**
	 * Every scenario's configuration.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function definitions(): array {
		$base = [
			'config'          => ['register' => 'reg', 'callback_schema' => 'cs'],
			'ticketSchema'    => 'ts',
			'containerThrows' => false,
			'policiesThrow'   => false,
			'policies'        => [['id' => 'P1'], ['id' => 'P2', 'x' => 1], ['id' => '']],
			'rows'            => [],
			'overdue'         => [],
			'escalations'     => [],
			'saveThrows'      => [],
		];

		return [
			'register missing'           => array_merge($base, ['config' => ['register' => '']]),
			'object service unavailable' => array_merge($base, ['containerThrows' => true]),
			'policies fail'              => array_merge($base, ['policiesThrow' => true]),
			'no surfaces'                => array_merge($base, self::noSurfaces(interval: '30')),
			'interval above maximum'     => array_merge($base, self::noSurfaces(interval: '5000')),
			'interval configured'        => array_merge($base, self::noSurfaces(interval: '600')),
			'full sweep'                 => array_merge(
				$base,
				[
					'rows'        => [
						'ts/' . TicketService::TYPE_REQUEST   => 'request rows',
						'ts/' . TicketService::TYPE_COMPLAINT => 'complaint rows',
						'cs/'                                 => 'throw',
					],
					'overdue'     => ['c1'],
					'escalations' => [
						'u1' => ['level' => 2, 'eventIds' => ['e1', 'e0']],
						'c1' => ['level' => 3, 'eventIds' => ['x1']],
					],
					'saveThrows'  => ['u6'],
				]
			),
			'two batches'                => array_merge($base, ['ticketSchema' => '', 'rows' => ['cs/' => 'two batches']]),
			'batch cap'                  => array_merge($base, ['ticketSchema' => '', 'rows' => ['cs/' => 'always full']]),
			'iterator rows'              => array_merge($base, ['ticketSchema' => '', 'rows' => ['cs/' => 'iterator']]),
			'findAll returns null'       => array_merge($base, ['ticketSchema' => '', 'rows' => ['cs/' => 'null']]),
		];
	}//end definitions()

	/**
	 * A scenario with no tracked surfaces and a configured sweep interval.
	 *
	 * @param string $interval The configured interval.
	 *
	 * @return array<string, mixed>
	 */
	private static function noSurfaces(string $interval): array {
		return [
			'ticketSchema' => '',
			'config'       => ['register' => 'reg', 'sla_sweep_interval_seconds' => $interval],
		];
	}//end noSurfaces()

	/**
	 * An entity that answers getObject() and, optionally, getUuid().
	 *
	 * @param mixed       $payload The getObject() result, or a Throwable to throw.
	 * @param string|null $uuid    The getUuid() result, or null for no getUuid().
	 *
	 * @return object
	 */
	private static function entity(mixed $payload, ?string $uuid): object {
		if ($uuid === null) {
			return new class ($payload) {
				/**
				 * @param mixed $payload The payload.
				 */
				public function __construct(private mixed $payload) {
				}//end __construct()

				/**
				 * The payload.
				 *
				 * @return mixed
				 */
				public function getObject(): mixed {
					if ($this->payload instanceof Throwable) {
						throw $this->payload;
					}

					return $this->payload;
				}//end getObject()
			};
		}

		return new class ($payload, $uuid) {
			/**
			 * @param mixed  $payload The payload.
			 * @param string $uuid    The UUID.
			 */
			public function __construct(private mixed $payload, private string $uuid) {
			}//end __construct()

			/**
			 * The payload.
			 *
			 * @return mixed
			 */
			public function getObject(): mixed {
				return $this->payload;
			}//end getObject()

			/**
			 * The UUID.
			 *
			 * @return string
			 */
			public function getUuid(): string {
				return $this->uuid;
			}//end getUuid()
		};
	}//end entity()

	/**
	 * The rows a findAll() call answers for one script at one offset.
	 *
	 * @param string $script The row script name.
	 * @param int    $offset The requested offset.
	 *
	 * @return mixed
	 */
	private static function rows(string $script, int $offset): mixed {
		$level0  = ['policyId' => 'P1', 'targets' => [['name' => 'response', 'breachEventIds' => ['e0']]], 'currentEscalationLevel' => 0];
		$scripts = [
			'request rows'   => [
				self::entity(payload: ['title' => 'a', 'slaStatus' => $level0], uuid: 'u1'),
				['uuid' => 'u2', 'slaStatus' => ['policyId' => 'P1', 'pausedAt' => '2026-01-01', 'targets' => []]],
				['id' => 'u3', 'title' => 'x'],
				['uuid' => 'u4', 'slaStatus' => ['policyId' => 'PX', 'targets' => []]],
				['slaStatus' => ['policyId' => 'P1', 'targets' => [['name' => 't']], 'currentEscalationLevel' => 1]],
				['uuid' => 'u6', 'slaStatus' => ['policyId' => 'P1']],
				['uuid' => 'u7', 'slaStatus' => 'not an array'],
				['uuid' => 'u8', 'slaStatus' => ['policyId' => '']],
			],
			'complaint rows' => [
				[
					'uuid'              => 'c1',
					'complaintCategory' => 'service',
					'status'            => 'open',
					'slaDeadline'       => '2026-01-01',
					'slaStatus'         => ['policyId' => 'P2', 'targets' => [['name' => 'resolve']], 'currentEscalationLevel' => 1],
				],
				['uuid' => 'c2'],
				self::entity(payload: new RuntimeException('broken'), uuid: null),
				self::entity(payload: 'not an array', uuid: null),
				self::entity(payload: ['id' => 'c5', 'slaStatus' => ['policyId' => 'P2', 'targets' => []]], uuid: null),
			],
		];
		if (isset($scripts[$script]) === true) {
			if ($offset > 0) {
				return [];
			}

			return $scripts[$script];
		}

		if ($script === 'two batches') {
			$count = 3;
			if ($offset === 0) {
				$count = 100;
			}

			return array_fill(0, $count, ['uuid' => 'b']);
		}

		if ($script === 'always full') {
			return array_fill(0, 100, ['uuid' => 'f']);
		}

		if ($script === 'iterator') {
			return new ArrayIterator([['uuid' => 'i1', 'slaStatus' => ['policyId' => 'P1', 'targets' => []]]]);
		}

		return null;
	}//end rows()

	/**
	 * Record one call.
	 *
	 * @param string               $call The call name.
	 * @param array<string, mixed> $args The arguments by name.
	 *
	 * @return void
	 */
	private function record(string $call, array $args): void {
		$this->events[] = ['call' => $call, 'args' => self::normalise(value: $args)];
	}//end record()

	/**
	 * Replace values that depend on the clock.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private static function normalise(mixed $value): mixed {
		if ($value instanceof DateTimeInterface) {
			return '<now>';
		}

		if (is_array($value) === false) {
			return $value;
		}

		foreach ($value as $key => $item) {
			if ($key === 'lastEvaluatedAt' || $key === 'elapsedSeconds') {
				$value[$key] = '<clock>';
				continue;
			}

			if ($key === 'error' && is_string($item) === true && str_contains($item, 'must be of type') === true) {
				$value[$key] = '<type error>';
				continue;
			}

			$value[$key] = self::normalise(value: $item);
		}

		return $value;
	}//end normalise()

	/**
	 * The scripted OpenRegister ObjectService.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return object
	 */
	private function objectService(array $scenario): object {
		$test = $this;
		return new class ($test, $scenario) {
			/**
			 * @param SlaDeadlineSweepGoldenTest $test     The recording test.
			 * @param array<string, mixed>       $scenario The scenario.
			 */
			public function __construct(private SlaDeadlineSweepGoldenTest $test, private array $scenario) {
			}//end __construct()

			/**
			 * Answer a page of rows.
			 *
			 * @param array<string, mixed> $config The query.
			 *
			 * @return mixed
			 */
			public function findAll(array $config=[]): mixed {
				$this->test->recordCall(call: 'findAll', args: ['config' => $config]);
				$filters = $config['filters'] ?? [];
				$key     = ($filters['schema'] ?? '') . '/' . ($filters['ticketType'] ?? '');
				$script  = $this->scenario['rows'][$key] ?? 'empty';
				if ($script === 'throw') {
					throw new RuntimeException('findAll failed');
				}

				if ($script === 'empty') {
					return [];
				}

				return $this->test->scriptedRows(script: $script, offset: (int) ($config['offset'] ?? 0));
			}//end findAll()

			/**
			 * Record a save.
			 *
			 * @param array<string, mixed> $object   The object.
			 * @param array<int, mixed>    $extend   Extend.
			 * @param string|null          $register Register.
			 * @param string|null          $schema   Schema.
			 * @param string|null          $uuid     UUID.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, array $extend=[], ?string $register=null, ?string $schema=null, ?string $uuid=null): array {
				$this->test->recordCall(
					call: 'saveObject',
					args: ['object' => $object, 'extend' => $extend, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid]
				);
				if (in_array($uuid, $this->scenario['saveThrows'], true) === true) {
					throw new RuntimeException('save failed');
				}

				return $object;
			}//end saveObject()
		};
	}//end objectService()

	/**
	 * Record a call made by a scripted collaborator.
	 *
	 * @param string               $call The call name.
	 * @param array<string, mixed> $args The arguments by name.
	 *
	 * @return void
	 */
	public function recordCall(string $call, array $args): void {
		$this->record(call: $call, args: $args);
	}//end recordCall()

	/**
	 * Rows for the scripted ObjectService.
	 *
	 * @param string $script The row script name.
	 * @param int    $offset The offset.
	 *
	 * @return mixed
	 */
	public function scriptedRows(string $script, int $offset): mixed {
		return self::rows(script: $script, offset: $offset);
	}//end scriptedRows()

	/**
	 * Mocks for every collaborator the sweep reads.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function collaborators(array $scenario): array {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default=''): string => (string) ($scenario['config'][$key] ?? $default)
		);

		$engine = $this->createMock(originalClassName: SlaEngineService::class);
		$engine->method('loadActivePolicies')->willReturnCallback(
			function () use ($scenario): array {
				$this->record(call: 'loadActivePolicies', args: []);
				if ($scenario['policiesThrow'] === true) {
					throw new RuntimeException('policies failed');
				}

				return $scenario['policies'];
			}
		);
		$engine->method('policyIdentity')->willReturnCallback(static fn (array $policy): string => (string) ($policy['id'] ?? ''));
		$engine->method('evaluateTargets')->willReturnCallback(
			function (array $currentTargets, array $policy, DateTimeInterface $now): array {
				$this->record(call: 'evaluateTargets', args: ['currentTargets' => $currentTargets, 'policy' => $policy, 'now' => $now]);
				$evaluated = [];
				foreach ($currentTargets as $target) {
					$target['evaluatedBy'] = $policy['id'];
					$evaluated[]           = $target;
				}

				return $evaluated;
			}
		);
		$engine->method('executeEscalations')->willReturnCallback(
			function (array $policy, string $objectType, string $objectId, array $newTargets, array $priorTargets, int $alreadyFired) use ($scenario): array {
				$this->record(
					call: 'executeEscalations',
					args: [
						'policy'       => $policy,
						'objectType'   => $objectType,
						'objectId'     => $objectId,
						'newTargets'   => $newTargets,
						'priorTargets' => $priorTargets,
						'alreadyFired' => $alreadyFired,
					]
				);
				return $scenario['escalations'][$objectId] ?? ['level' => $alreadyFired, 'eventIds' => []];
			}
		);

		$complaintSla = $this->createMock(originalClassName: ComplaintSlaService::class);
		$complaintSla->method('isOverdue')->willReturnCallback(
			function (array $complaint, ?DateTimeInterface $now=null) use ($scenario): bool {
				$this->record(call: 'isOverdue', args: ['complaint' => $complaint, 'now' => $now]);
				return in_array($complaint['uuid'] ?? '', $scenario['overdue'], true);
			}
		);

		$ticketService = $this->createMock(originalClassName: TicketService::class);
		$ticketService->method('getSchemaId')->willReturn($scenario['ticketSchema']);

		$objectService = $this->objectService(scenario: $scenario);
		$container     = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($scenario, $objectService): object {
				$this->record(call: 'container.get', args: ['id' => $id]);
				if ($scenario['containerThrows'] === true) {
					throw new RuntimeException('no ObjectService');
				}

				return $objectService;
			}
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context=[]) use ($level): void {
					$this->record(call: 'log.' . $level, args: ['message' => (string) $message, 'context' => $context]);
				}
			);
		}

		return [
			'time'          => $this->createMock(originalClassName: ITimeFactory::class),
			'appConfig'     => $appConfig,
			'engine'        => $engine,
			'complaintSla'  => $complaintSla,
			'ticketService' => $ticketService,
			'container'     => $container,
			'logger'        => $logger,
		];
	}//end collaborators()

	/**
	 * Build the job from its collaborators.
	 *
	 * @param array<string, mixed> $c The collaborators.
	 *
	 * @return SlaDeadlineSweepJob
	 */
	private function makeJob(array $c): SlaDeadlineSweepJob {
		return new SlaDeadlineSweepJob(
			time: $c['time'],
			source: new SlaSweepSource(ticketService: $c['ticketService'], appConfig: $c['appConfig'], container: $c['container']),
			sweeper: new SlaEntitySweeper(engine: $c['engine'], complaintSla: $c['complaintSla'], logger: $c['logger']),
			logger: $c['logger'],
		);
	}//end makeJob()

	/**
	 * The sweep makes the recorded calls.
	 *
	 * @param string $name The scenario name.
	 *
	 * @return void
	 */
	#[DataProvider('scenarios')]
	public function testTheSweepMatchesTheGolden(string $name): void {
		$this->events = [];
		$job          = $this->makeJob(c: $this->collaborators(scenario: self::definitions()[$name]));
		$outcome      = 'returned';
		try {
			(new ReflectionMethod($job, 'run'))->invoke($job, null);
		} catch (Throwable $e) {
			$outcome = 'threw ' . get_class($e);
		}

		$actual = [
			'interval'      => $job->getInterval(),
			'timeSensitive' => $job->isTimeSensitive(),
			'outcome'       => $outcome,
			'events'        => $this->events,
		];

		if (getenv('SLA_SWEEP_GOLDEN_RECORD') !== false) {
			$golden = [];
			if (file_exists(self::GOLDEN) === true) {
				$golden = json_decode((string) file_get_contents(self::GOLDEN), true);
			}

			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(
				self::GOLDEN,
				json_encode($golden, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n"
			);
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true);
		$this->assertArrayHasKey(key: $name, array: $golden);
		$this->assertSame(expected: $golden[$name], actual: json_decode((string) json_encode($actual), true));
	}//end testTheSweepMatchesTheGolden()
}//end class
