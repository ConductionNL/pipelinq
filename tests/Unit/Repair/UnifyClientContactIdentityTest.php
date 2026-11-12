<?php

/**
 * Characterisation tests for UnifyClientContactIdentity::migrate().
 *
 * Pins the whole identity pass: which clients and contacts are skipped,
 * linked to an existing Nextcloud contact, linked through a new contact or
 * left unresolved, what is written back (contactsUid and the provenance
 * source record), which contactmomenten are re-keyed, and the summary, so
 * the pass can be restructured without changing them.
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

use OCA\Pipelinq\Repair\UnifyClientContactIdentity;
use OCA\Pipelinq\Service\ContactVcardService;
use OCA\Pipelinq\Service\TicketService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the client/contact identity unification pass.
 */
final class UnifyClientContactIdentityTest extends TestCase {

	/**
	 * Contact searches made, as [term, properties].
	 *
	 * @var array<int, array{0: string, 1: array<int, string>}>
	 */
	private array $searches = [];

	/**
	 * Contacts created through the vCard sync, as [objectType, objectId].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $syncs = [];

	/**
	 * Warnings logged.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * The full pass over clients, contacts and contactmomenten.
	 *
	 * @return void
	 */
	public function testThePassLinksSkipsAndRekeys(): void {
		$store = $this->store();
		$store->rows = [
			'client-s' => [
				['id' => 'c1', 'contactsUid' => 'u-old', '@self' => ['slug' => 'c1-slug']],
				['id' => 'c2', 'email' => 'jan@example.nl', '@self' => ['id' => 'c2', 'uuid' => 'c2-uuid']],
				['uuid' => 'c3', 'type' => 'organization', 'name' => 'Acme BV'],
				['id' => 'c4', 'email' => 'nobody@example.nl'],
				['name' => 'No id'],
				['id' => 'c6', 'email' => 'sync-fails@example.nl'],
			],
			'contact-s' => [
				['id' => 'k1', 'email' => 'piet@example.nl'],
				['id' => 'k2', 'contactsUid' => 'u-k2'],
			],
			'ticket-s' => [
				['id' => 'm1', 'client' => 'c2-uuid', '@self' => ['id' => 'm1']],
				['id' => 'm2', 'client' => 'c2', 'contactsUid' => 'already'],
				['id' => 'm3', 'client' => 'someone-else'],
				['id' => 'm4', 'client' => 'c1-slug'],
			],
		];

		$result = $this->step(store: $store)->migrate(objectService: $store, register: 'reg');

		$this->assertSame(
			[
				'clientsLinked' => 3,
				'contactsLinked' => 1,
				'skipped' => 2,
				'unresolved' => 2,
				'momentsRekeyed' => 2,
				'auditMap' => ['c1' => 'u-old', 'c2' => 'u-jan', 'c3' => 'u-acme', 'c4' => 'u-new-c4', 'k1' => 'u-piet', 'k2' => 'u-k2'],
			],
			$result
		);
		$this->assertSame(
			[
				['jan@example.nl', ['EMAIL', 'UID']],
				['Acme BV', ['ORG', 'X-KVK', 'UID']],
				['nobody@example.nl', ['EMAIL', 'UID']],
				['sync-fails@example.nl', ['EMAIL', 'UID']],
				['piet@example.nl', ['EMAIL', 'UID']],
			],
			$this->searches
		);
		$this->assertSame([['client', 'c4'], ['client', 'c6']], $this->syncs);
		$this->assertSame(
			[
				['reg', 'client-s', 'c2', ['id' => 'c2', 'email' => 'jan@example.nl', 'contactsUid' => 'u-jan']],
				['reg', 'source-s', null, ['sourceRecordId' => 'pipelinq-identity-unify:c2', 'nativeId' => 'c2', 'entityType' => 'account', 'currentMasterEntity' => 'u-jan']],
				['reg', 'client-s', 'c3', ['uuid' => 'c3', 'type' => 'organization', 'name' => 'Acme BV', 'contactsUid' => 'u-acme']],
				['reg', 'source-s', null, ['sourceRecordId' => 'pipelinq-identity-unify:c3', 'nativeId' => 'c3', 'entityType' => 'account', 'currentMasterEntity' => 'u-acme']],
				['reg', 'source-s', null, ['sourceRecordId' => 'pipelinq-identity-unify:c4', 'nativeId' => 'c4', 'entityType' => 'account', 'currentMasterEntity' => 'u-new-c4']],
				['reg', 'contact-s', 'k1', ['id' => 'k1', 'email' => 'piet@example.nl', 'contactsUid' => 'u-piet']],
				['reg', 'source-s', null, ['sourceRecordId' => 'pipelinq-identity-unify:k1', 'nativeId' => 'k1', 'entityType' => 'contact', 'currentMasterEntity' => 'u-piet']],
				['reg', 'ticket-s', 'm1', ['id' => 'm1', 'client' => 'c2-uuid', 'contactsUid' => 'u-jan']],
				['reg', 'ticket-s', 'm4', ['id' => 'm4', 'client' => 'c1-slug', 'contactsUid' => 'u-old']],
			],
			$store->writes()
		);
		$this->assertSame(
			[
				['reg', 'client-s', []],
				['reg', 'contact-s', []],
				['reg', 'ticket-s', ['filters' => ['ticketType' => TicketService::TYPE_CONTACTMOMENT]]],
			],
			$store->reads
		);
		$this->assertSame(['Identity unify: contact creation via ContactVcardService failed'], $this->warnings);
	}//end testThePassLinksSkipsAndRekeys()

	/**
	 * Without a client schema nothing is linked and no contactmoment is re-keyed.
	 *
	 * @return void
	 */
	public function testWithoutSchemasNothingIsReadOrRekeyed(): void {
		$store = $this->store();
		$store->rows = ['ticket-s' => [['id' => 'm1', 'client' => 'c2']]];

		$result = $this->step(store: $store, schemas: false)->migrate(objectService: $store, register: 'reg');

		$this->assertSame(
			['clientsLinked' => 0, 'contactsLinked' => 0, 'skipped' => 0, 'unresolved' => 0, 'momentsRekeyed' => 0, 'auditMap' => []],
			$result
		);
		$this->assertSame([], $store->reads);
	}//end testWithoutSchemasNothingIsReadOrRekeyed()

	/**
	 * A failed read leaves that collection out with a warning.
	 *
	 * @return void
	 */
	public function testAFailedReadLeavesTheCollectionOut(): void {
		$store = $this->store();
		$store->rows = ['client-s' => 'store down', 'contact-s' => [['id' => 'k2', 'contactsUid' => 'u-k2']]];

		$result = $this->step(store: $store)->migrate(objectService: $store, register: 'reg');

		$this->assertSame([0, 1, 0], [$result['clientsLinked'], $result['skipped'], $result['momentsRekeyed']]);
		$this->assertSame(['Identity unify: failed to read objects'], $this->warnings);
	}//end testAFailedReadLeavesTheCollectionOut()

	/**
	 * An in-memory OpenRegister ObjectService with the fluent register/schema scope.
	 *
	 * @return object
	 */
	private function store(): object {
		return new class {
			/**
			 * Rows per schema; a string is the message the read throws.
			 *
			 * @var array<string, mixed>
			 */
			public array $rows = [];

			/**
			 * Reads, as [register, schema, config].
			 *
			 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
			 */
			public array $reads = [];

			/**
			 * Writes, as [register, schema, uuid, object].
			 *
			 * @var array<int, array{0: mixed, 1: mixed, 2: mixed, 3: array<string, mixed>}>
			 */
			public array $saved = [];

			/**
			 * The scoped register.
			 *
			 * @var string
			 */
			private string $register = '';

			/**
			 * The scoped schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Scope to a register.
			 *
			 * @param string $register The register.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				$this->register = $register;
				return $this;
			}

			/**
			 * Scope to a schema.
			 *
			 * @param string $schema The schema.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * Read the scoped schema.
			 *
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config = []): array {
				$this->reads[] = [$this->register, $this->schema, $config];
				$rows = ($this->rows[$this->schema] ?? []);
				if (is_string($rows) === true) {
					throw new RuntimeException($rows);
				}

				return $rows;
			}

			/**
			 * Record a write.
			 *
			 * @param array<string, mixed> $object   The object.
			 * @param array<int, mixed>    $extend   Extend.
			 * @param mixed                $register The register.
			 * @param mixed                $schema   The schema.
			 * @param mixed                $uuid     The uuid.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, array $extend = [], $register = null, $schema = null, $uuid = null): array {
				$this->saved[] = [$register, $schema, $uuid, $object];
				return $object;
			}

			/**
			 * Run a write as the system.
			 *
			 * @param callable $operation The write.
			 *
			 * @return mixed
			 */
			public function runAsSystem(callable $operation): mixed {
				return $operation();
			}

			/**
			 * The writes, source records reduced to their identity fields.
			 *
			 * @return array<int, array{0: mixed, 1: mixed, 2: mixed, 3: array<string, mixed>}>
			 */
			public function writes(): array {
				$keep = ['sourceRecordId', 'nativeId', 'entityType', 'currentMasterEntity'];
				return array_map(
					static function (array $write) use ($keep): array {
						if ($write[1] === 'source-s') {
							$write[3] = array_intersect_key($write[3], array_flip($keep));
						}

						return $write;
					},
					$this->saved
				);
			}
		};
	}//end store()

	/**
	 * The repair step over the store and recording collaborators.
	 *
	 * @param object $store   The ObjectService.
	 * @param bool   $schemas Whether the client and contact schemas are configured.
	 *
	 * @return UnifyClientContactIdentity
	 */
	private function step(object $store, bool $schemas = true): UnifyClientContactIdentity {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($schemas): string {
				$values = ['sourceRecord_schema' => 'source-s'];
				if ($schemas === true) {
					$values += ['client_schema' => 'client-s', 'contact_schema' => 'contact-s'];
				}

				return ($values[$key] ?? $default);
			}
		);

		$contacts = new class ($this) {
			/**
			 * Constructor.
			 *
			 * @param UnifyClientContactIdentityTest $test The test recording the searches.
			 */
			public function __construct(private UnifyClientContactIdentityTest $test) {
			}

			/**
			 * Search the address book.
			 *
			 * @param string             $term       The pattern.
			 * @param array<int, string> $properties The properties.
			 * @param array<string, mixed> $options  Options.
			 *
			 * @return array<int, array<string, string>>
			 */
			public function search(string $term, array $properties, array $options): array {
				$this->test->recordSearch(term: $term, properties: $properties);
				return match ($term) {
					'jan@example.nl' => [['FN' => 'Jan'], ['UID' => 'u-jan']],
					'Acme BV' => [['UID' => 'u-acme']],
					'piet@example.nl' => [['UID' => 'u-piet']],
					default => [],
				};
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($contacts);

		$vcards = $this->createMock(ContactVcardService::class);
		$vcards->method('syncToContacts')->willReturnCallback(
			function (string $objectType, string $objectId): ?string {
				$this->syncs[] = [$objectType, $objectId];
				if ($objectId === 'c6') {
					throw new RuntimeException('vcard down');
				}

				return 'u-new-' . $objectId;
			}
		);

		$tickets = $this->createMock(TicketService::class);
		$tickets->method('getSchemaId')->willReturn('ticket-s');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);

		return new UnifyClientContactIdentity($this->createMock(IAppManager::class), $appConfig, $vcards, $container, $tickets, $logger);
	}//end step()

	/**
	 * Record a contact search.
	 *
	 * @param string             $term       The pattern.
	 * @param array<int, string> $properties The properties.
	 *
	 * @return void
	 */
	public function recordSearch(string $term, array $properties): void {
		$this->searches[] = [$term, $properties];
	}//end recordSearch()
}//end class
