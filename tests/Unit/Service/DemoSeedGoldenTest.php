<?php

/**
 * Golden test for seeding and removing the demo data set.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCA\Pipelinq\Service\ContactVcardService;
use OCA\Pipelinq\Service\Demo\DemoMarketingSeeder;
use OCA\Pipelinq\Service\Demo\DemoSeedContext;
use OCA\Pipelinq\Service\Demo\DemoSeedValues;
use OCA\Pipelinq\Service\Demo\DemoSeedWriter;
use OCA\Pipelinq\Service\DemoSeedService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Runs seed() or remove() over the shipped demo definitions and scripted
 * stores, and compares the result and every write, lookup and delete with
 * DemoSeedGolden.json. Placeholders stay unresolved so the golden does not
 * depend on the date. Set DEMO_SEED_GOLDEN_RECORD=1 to rewrite the file.
 *
 * @covers \OCA\Pipelinq\Service\DemoSeedService
 * @covers \OCA\Pipelinq\Service\Demo\DemoSeedContext
 * @covers \OCA\Pipelinq\Service\Demo\DemoSeedWriter
 * @covers \OCA\Pipelinq\Service\Demo\DemoSeedTarget
 * @uses   \OCA\Pipelinq\Service\Demo\DemoSeedValues
 */
class DemoSeedGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/DemoSeedGolden.json';

	/**
	 * Schema config key, lookup field and ticket type per seeded section.
	 *
	 * @var array<string, array{0: string, 1: string, 2: string|null}>
	 */
	private const SECTIONS = [
		'clients'         => ['client_schema', 'name', null],
		'contacts'        => ['contact_schema', 'name', null],
		'pipelines'       => ['pipeline_schema', 'title', null],
		'products'        => ['product_schema', 'name', null],
		'leads'           => ['lead_schema', 'title', null],
		'requests'        => ['ticket_schema', 'title', 'request'],
		'complaints'      => ['ticket_schema', 'title', 'complaint'],
		'contactmomenten' => ['ticket_schema', 'title', 'contactmoment'],
		'tasks'           => ['task_schema', 'subject', null],
		'contracts'       => ['contract_schema', 'title', null],
	];

	/**
	 * Calls recorded during one case, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $events = [];

	/**
	 * Every case's setup.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function definitions(): array {
		$base = ['action' => 'seed', 'register' => 'reg', 'ticketSchema' => 'ticket-id', 'stored' => 'none', 'contacts' => true, 'container' => true];
		return [
			'seed fresh'                 => $base,
			'seed over existing'         => array_merge($base, ['stored' => 'existing']),
			'seed, contacts unavailable' => array_merge($base, ['contacts' => false]),
			'seed, no register'          => array_merge($base, ['register' => '']),
			'seed, no ticket schema'     => array_merge($base, ['ticketSchema' => '']),
			'seed, no object service'    => array_merge($base, ['container' => false]),
			'remove'                     => array_merge($base, ['action' => 'remove', 'stored' => 'existing']),
			'remove, delete fails'       => array_merge($base, ['action' => 'remove', 'stored' => 'failing']),
			'remove, no register'        => array_merge($base, ['action' => 'remove', 'register' => '']),
			'remove, messy rows'         => array_merge($base, ['action' => 'remove', 'stored' => 'messy']),
			'seed over messy rows'       => array_merge($base, ['stored' => 'messy']),
		];
	}//end definitions()

	/**
	 * The case names.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$names = [];
		foreach (array_keys(self::definitions()) as $name) {
			$names[$name] = [$name];
		}

		return $names;
	}//end cases()

	/**
	 * Record one call.
	 *
	 * @param string               $call The call name.
	 * @param array<string, mixed> $args The arguments by name.
	 *
	 * @return void
	 */
	public function recordCall(string $call, array $args): void {
		$this->events[] = ['call' => $call, 'args' => $args];
	}//end recordCall()

	/**
	 * A saved entity carrying a uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity
	 */
	private static function saved(string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		return $entity;
	}//end saved()

	/**
	 * Stored rows for a schema id: one per shipped definition of the sections
	 * on that schema, with a stale pipeline so a reseed has something to relink.
	 *
	 * @param string $script   The storage script.
	 * @param string $schemaId The schema id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function stored(string $script, string $schemaId): array {
		if ($script === 'none') {
			return [];
		}

		$shipped = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/demo_seed_data.json'), true);
		$rows    = [];
		foreach (self::SECTIONS as $section => [$schemaKey, $lookupField, $ticketType]) {
			if ($schemaId !== $schemaKey . '-id' && ($ticketType === null || $schemaId !== 'ticket-id')) {
				continue;
			}

			foreach (($shipped[$section] ?? []) as $i => $definition) {
				$value    = (string) ($definition['data'][$lookupField] ?? $definition['data']['subject'] ?? '');
				$pipeline = 'stale-pipeline';
				if ($section === 'leads' && $i % 2 === 0) {
					$pipeline = 'pipelines-0';
				}

				$rows[] = [
					'id'          => $section . '-' . $i,
					$lookupField  => $value,
					'ticketType'  => $ticketType,
					'pipeline'    => $pipeline,
					'note'        => 'kept',
					'@self'       => ['id' => $section . '-' . $i],
				];
			}
		}

		if ($script === 'failing' && $schemaId === 'contract_schema-id') {
			$rows[0]['id'] = 'fails';
		}

		if ($script === 'messy' && $rows !== []) {
			$first = $rows[0];
			$byUuid = $first;
			unset($byUuid['id']);
			$byUuid['uuid'] = $first['id'] . '-by-uuid';
			$rows[] = ['id' => $first['id'] . '-duplicate'] + $first;
			$rows[] = $byUuid;
			$rows[] = array_merge($first, ['id' => $first['id'] . '-blank', array_keys($first)[1] => '']);
			array_unshift($rows, ['id' => '', 'uuid' => ''] + $first);
		}

		return $rows;
	}//end stored()

	/**
	 * Mocks for every collaborator.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function collaborators(array $case): array {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default='') use ($case): string {
				if ($key === 'register') {
					return $case['register'];
				}

				return $key . '-id';
			}
		);

		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($case): array {
				$this->recordCall(call: 'findAll', args: ['config' => $config]);
				return self::stored(script: $case['stored'], schemaId: (string) $config['filters']['schema']);
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
				$extend = ($options->extend ?? []);
				$register = $options->target->register;
				$schema = $options->target->schema;
				$uuid = $options->uuid;
				$this->recordCall(
					call: 'saveObject',
					args: ['object' => $object, 'extend' => $extend, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid]
				);
				return self::saved(uuid: 'saved-' . count($this->events));
			}
		);
		$objectService->method('deleteObject')->willReturnCallback(
			function (string $uuid, $register=null, $schema=null): bool {
				$this->recordCall(call: 'deleteObject', args: ['uuid' => $uuid, 'register' => $register, 'schema' => $schema]);
				if ($uuid === 'fails') {
					throw new RuntimeException('database gone');
				}

				if (str_starts_with($uuid, 'contactmomenten-') === true) {
					throw new RuntimeException('SCHEMA_ARCHIVAL_IMMUTABLE');
				}

				return true;
			}
		);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($case, $objectService): object {
				if ($case['container'] === false) {
					throw new RuntimeException('no ObjectService');
				}

				return $objectService;
			}
		);

		$tickets = $this->createMock(originalClassName: TicketService::class);
		$tickets->method('getSchemaId')->willReturn($case['ticketSchema']);
		$tickets->method('save')->willReturnCallback(
			function (string $ticketType, array $payload, ?string $uuid=null): ObjectEntity {
				$this->recordCall(call: 'tickets.save', args: ['ticketType' => $ticketType, 'payload' => $payload, 'uuid' => $uuid]);
				return self::saved(uuid: 'ticket-' . count($this->events));
			}
		);

		$contacts = $this->createMock(originalClassName: ContactVcardService::class);
		$contacts->method('provisionContactFromForm')->willReturnCallback(
			function (array $form, string $objectType) use ($case): ?array {
				$this->recordCall(call: 'provisionContact', args: ['name' => $form['name'] ?? null, 'objectType' => $objectType]);
				if ($case['contacts'] === false) {
					return null;
				}

				return ['contactsUid' => 'nc-' . md5((string) ($form['name'] ?? ''))];
			}
		);

		$marketing = $this->createMock(originalClassName: DemoMarketingSeeder::class);
		$marketing->method('seed')->willReturnCallback(
			function (array $definitions, array $uuids): array {
				$this->recordCall(call: 'marketing.seed', args: ['uuids' => $uuids]);
				return ['created' => ['journeys' => 5], 'skipped' => ['journeys' => 0]];
			}
		);
		$marketing->method('remove')->willReturnCallback(
			function (array $definitions): array {
				$this->recordCall(call: 'marketing.remove', args: ['sections' => array_keys($definitions)]);
				return ['journeys' => 5];
			}
		);

		$values = $this->getMockBuilder(className: DemoSeedValues::class)->onlyMethods(methods: ['resolvePlaceholders'])->getMock();
		$values->method('resolvePlaceholders')->willReturnArgument(0);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string|\Stringable $message, array $context=[]): void {
				$this->recordCall(call: 'log.error', args: ['message' => (string) $message]);
			}
		);

		return [
			'appConfig' => $appConfig,
			'container' => $container,
			'contacts'  => $contacts,
			'tickets'   => $tickets,
			'marketing' => $marketing,
			'values'    => $values,
			'logger'    => $logger,
		];
	}//end collaborators()

	/**
	 * Build the service.
	 *
	 * @param array<string, mixed> $c The collaborators.
	 *
	 * @return DemoSeedService
	 */
	private function makeService(array $c): DemoSeedService {
		return new DemoSeedService(
			context: new DemoSeedContext(appConfig: $c['appConfig'], container: $c['container'], ticketService: $c['tickets'], logger: $c['logger']),
			writer: new DemoSeedWriter(ticketService: $c['tickets'], contactVcardService: $c['contacts']),
			marketingSeeder: $c['marketing'],
			values: $c['values'],
		);
	}//end makeService()

	/**
	 * The result and the calls match the golden.
	 *
	 * @param string $name The case name.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testSeedAndRemoveMatchTheGolden(string $name): void {
		$case         = self::definitions()[$name];
		$this->events = [];
		$service      = $this->makeService(c: $this->collaborators(case: $case));
		try {
			$result = ['returned' => $service->{$case['action']}()];
		} catch (Throwable $e) {
			$result = ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

		$actual = json_decode((string) json_encode(['result' => $result, 'events' => $this->events]), true);
		if (getenv('DEMO_SEED_GOLDEN_RECORD') !== false) {
			$golden = [];
			if (file_exists(self::GOLDEN) === true) {
				$golden = json_decode((string) file_get_contents(self::GOLDEN), true);
			}

			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::GOLDEN, json_encode($golden, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true);
		$this->assertArrayHasKey(key: $name, array: $golden);
		$this->assertSame(expected: $golden[$name], actual: $actual);
	}//end testSeedAndRemoveMatchTheGolden()
}//end class
