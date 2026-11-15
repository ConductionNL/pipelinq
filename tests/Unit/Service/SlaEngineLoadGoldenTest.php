<?php

/**
 * Characterisation tests for SlaEngineService policy loading, customer
 * contact resolution and object normalisation.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\BusinessHoursCalculator;
use OCA\Pipelinq\Service\HolidayCalendarService;
use OCA\Pipelinq\Service\SlaEngineService;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Pins loadActivePolicies(), resolveCustomerContact() and normaliseObject().
 */
class SlaEngineLoadGoldenTest extends TestCase {

	/**
	 * Logged warnings.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * Build the engine over a fake object service.
	 *
	 * @param array<string, string> $config  App config.
	 * @param object|null           $objects The ObjectService the container answers; null throws.
	 *
	 * @return SlaEngineService
	 */
	private function engine(array $config, ?object $objects): SlaEngineService {
		$container = $this->createMock(ContainerInterface::class);
		if ($objects === null) {
			$container->method('get')->willThrowException(new RuntimeException('no object service'));
		} else {
			$container->method('get')->willReturn($objects);
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($config): string {
				return ($config[$key] ?? $default);
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new SlaEngineService(
			$this->createMock(HolidayCalendarService::class),
			$this->createMock(BusinessHoursCalculator::class),
			$container,
			$appConfig,
			$this->createMock(INotificationManager::class),
			$logger
		);
	}//end engine()

	/**
	 * A fake ObjectService answering findAll() and find().
	 *
	 * @param mixed                $rows    The findAll() answer, or a Throwable.
	 * @param array<string, mixed> $objects Objects by "schema/id"; a Throwable throws.
	 *
	 * @return object
	 */
	private static function objects(mixed $rows, array $objects = []): object {
		return new class($rows, $objects) {
			/**
			 * Calls made.
			 *
			 * @var array<int, mixed>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param mixed                $rows    The findAll answer.
			 * @param array<string, mixed> $objects The find answers.
			 */
			public function __construct(private mixed $rows, private array $objects) {
			}

			/**
			 * Find all.
			 *
			 * @param array<string, mixed> $config The config.
			 *
			 * @return mixed
			 */
			public function findAll(array $config = []): mixed {
				$this->calls[] = $config;
				if ($this->rows instanceof \Throwable) {
					throw $this->rows;
				}

				return $this->rows;
			}

			/**
			 * Find one.
			 *
			 * @param string $id       The id.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return mixed
			 */
			public function find(string $id, string $register = '', string $schema = ''): mixed {
				$this->calls[] = $register . ':' . $schema . '/' . $id;
				$hit = ($this->objects[$schema . '/' . $id] ?? null);
				if ($hit instanceof \Throwable) {
					throw $hit;
				}

				return $hit;
			}
		};
	}//end objects()

	/**
	 * Policies: the query, archived and inactive rows dropped, entity rows
	 * normalised.
	 *
	 * @return void
	 */
	public function testLoadActivePolicies(): void {
		$entity = new class {
			/**
			 * The object.
			 *
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return ['name' => 'entity'];
			}

			/**
			 * The uuid.
			 *
			 * @return string
			 */
			public function getUuid(): string {
				return 'u-1';
			}
		};
		$objects = self::objects(
			[
				['name' => 'a'],
				['name' => 'b', 'status' => 'archived'],
				['name' => 'c', 'active' => false],
				['name' => 'd', 'active' => 0, 'status' => 'draft'],
				$entity,
				'junk',
			]
		);
		$engine = $this->engine(['sla_register' => 'sla', 'sla_policy_schema' => 'policy'], $objects);

		$this->assertSame(
			[
				['name' => 'a'],
				['name' => 'd', 'active' => 0, 'status' => 'draft'],
				['name' => 'entity', 'id' => 'u-1'],
				[],
			],
			$engine->loadActivePolicies()
		);
		$this->assertSame([['filters' => ['register' => 'sla', 'schema' => 'policy'], 'limit' => 500]], $objects->calls);
	}//end testLoadActivePolicies()

	/**
	 * Unconfigured, unavailable and failing reads answer no policies.
	 *
	 * @return void
	 */
	public function testLoadActivePoliciesFailures(): void {
		$this->assertSame([], $this->engine(['sla_register' => 'sla'], self::objects([['x' => 1]]))->loadActivePolicies());
		$this->assertSame([], $this->engine(['sla_policy_schema' => 'p'], self::objects([['x' => 1]]))->loadActivePolicies());
		$this->assertSame([], $this->warnings);

		$config = ['sla_register' => 'sla', 'sla_policy_schema' => 'policy'];
		$this->assertSame([], $this->engine($config, null)->loadActivePolicies());
		$this->assertSame([], $this->engine($config, self::objects(new RuntimeException('down')))->loadActivePolicies());
		$this->assertSame([], $this->engine($config, self::objects(null))->loadActivePolicies());
		$this->assertSame(
			[
				['SlaEngineService: ObjectService not available', ['error' => 'no object service']],
				['SlaEngineService: ObjectService::findAll failed', ['error' => 'down']],
			],
			$this->warnings
		);
	}//end testLoadActivePoliciesFailures()

	/**
	 * Call a private engine method.
	 *
	 * @param SlaEngineService  $engine The engine.
	 * @param string            $method The method.
	 * @param array<int, mixed> $args   The arguments.
	 *
	 * @return mixed
	 */
	private static function call(SlaEngineService $engine, string $method, array $args): mixed {
		$reflection = new ReflectionMethod($engine, $method);

		return $reflection->invokeArgs($engine, $args);
	}//end call()

	/**
	 * The customer contact: contact first, then client, with configured
	 * and default schemas.
	 *
	 * @return void
	 */
	public function testResolveCustomerContact(): void {
		$objects = self::objects(
			[],
			[
				'ticket/t-1' => ['contact' => 'c-1', 'client' => 'k-1'],
				'ticket/t-2' => ['contact' => 'c-missing', 'client' => 'k-1'],
				'ticket/t-3' => ['client' => 'k-1'],
				'ticket/t-4' => ['contact' => ''],
				'tickets/t-5' => ['contact' => 'c-1'],
				'tickets/t-6' => ['contact' => 'c-none', 'client' => 'k-1'],
				'contact/c-1' => ['name' => 'Contact'],
				'client/k-1' => ['name' => 'Client'],
				'people/c-1' => ['name' => 'Person'],
				'orgs/k-1' => ['name' => 'Org'],
			]
		);
		$engine = $this->engine(['register' => 'reg'], $objects);

		$this->assertSame(['name' => 'Contact'], self::call($engine, 'resolveCustomerContact', ['ticket', 't-1']));
		$this->assertSame(['name' => 'Client'], self::call($engine, 'resolveCustomerContact', ['ticket', 't-2']));
		$this->assertSame(['name' => 'Client'], self::call($engine, 'resolveCustomerContact', ['ticket', 't-3']));
		$this->assertNull(self::call($engine, 'resolveCustomerContact', ['ticket', 't-4']));
		$this->assertNull(self::call($engine, 'resolveCustomerContact', ['ticket', 't-missing']));
		$this->assertSame(
			['reg:ticket/t-1', 'reg:contact/c-1', 'reg:ticket/t-2', 'reg:contact/c-missing', 'reg:client/k-1', 'reg:ticket/t-3', 'reg:client/k-1', 'reg:ticket/t-4', 'reg:ticket/t-missing'],
			$objects->calls
		);

		$configured = $this->engine(
			['register' => 'reg', 'sla_target_schema_ticket' => 'tickets', 'contact_schema' => 'people', 'client_schema' => 'orgs'],
			$objects
		);
		$this->assertSame(['name' => 'Person'], self::call($configured, 'resolveCustomerContact', ['ticket', 't-5']));
		$this->assertSame(['name' => 'Org'], self::call($configured, 'resolveCustomerContact', ['ticket', 't-6']));
	}//end testResolveCustomerContact()

	/**
	 * Object normalisation for arrays, entities, serialisable objects and the rest.
	 *
	 * @return void
	 */
	public function testNormaliseObject(): void {
		$engine = $this->engine([], null);
		$withId = new class {
			/**
			 * The object.
			 *
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return ['id' => 'own', 'x' => 1];
			}

			/**
			 * The uuid.
			 *
			 * @return string
			 */
			public function getUuid(): string {
				return 'u-2';
			}
		};
		$noUuid = new class {
			/**
			 * The object.
			 *
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return ['x' => 2];
			}
		};
		$badObject = new class implements \JsonSerializable {
			/**
			 * Not an array.
			 *
			 * @return string
			 */
			public function getObject(): string {
				return 'x';
			}

			/**
			 * Serialise.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['json' => true];
			}
		};
		$badJson = new class implements \JsonSerializable {
			/**
			 * Serialise to a scalar.
			 *
			 * @return string
			 */
			public function jsonSerialize(): string {
				return 'x';
			}
		};

		$this->assertSame(['a' => 1], self::call($engine, 'normaliseObject', [['a' => 1]]));
		$this->assertSame(['id' => 'own', 'x' => 1], self::call($engine, 'normaliseObject', [$withId]));
		$this->assertSame(['x' => 2], self::call($engine, 'normaliseObject', [$noUuid]));
		$this->assertSame(['json' => true], self::call($engine, 'normaliseObject', [$badObject]));
		$this->assertSame([], self::call($engine, 'normaliseObject', [$badJson]));
		$this->assertSame([], self::call($engine, 'normaliseObject', [new \stdClass()]));
		$this->assertSame([], self::call($engine, 'normaliseObject', ['x']));
		$this->assertSame([], self::call($engine, 'normaliseObject', [null]));
	}//end testNormaliseObject()
}//end class
