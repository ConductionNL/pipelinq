<?php

/**
 * Failure paths of the client/contact identity unification pass.
 *
 * Covers what the pass test leaves out: a Contacts manager that cannot be
 * resolved, is disabled or fails a search; a vCard sync that answers no UID;
 * an ObjectService without runAsSystem; and failed writes of the contactsUid,
 * the re-keyed contactmoment and the sourceRecord provenance, each logged and
 * none of them stopping the pass.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\Pipelinq\Repair\IdentityCollection;
use OCA\Pipelinq\Repair\IdentityKind;
use OCA\Pipelinq\Repair\IdentityPass;
use OCA\Pipelinq\Repair\IdentityTally;
use OCA\Pipelinq\Repair\UnifyClientContactIdentity;
use OCA\Pipelinq\Service\ContactVcardService;
use OCA\Pipelinq\Service\TicketService;
use OCA\Pipelinq\Util\EntityPayload;
use OCA\Pipelinq\Util\SerialisedPayload;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * An in-memory ObjectService without runAsSystem, failing the writes it is told to.
 */
final class FailingIdentityStore {

	/**
	 * Rows per schema.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	public array $rows = [];

	/**
	 * Schemas whose writes throw.
	 *
	 * @var array<int, string>
	 */
	public array $failingSchemas = [];

	/**
	 * Writes that went through, as [schema, uuid, object].
	 *
	 * @var array<int, array{0: string, 1: mixed, 2: array<string, mixed>}>
	 */
	public array $saved = [];

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
		return $this;
	}//end setRegister()

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
	}//end setSchema()

	/**
	 * Read the scoped schema.
	 *
	 * @param array<string, mixed> $config The query.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll(array $config=[]): array {
		return ($this->rows[$this->schema] ?? []);
	}//end findAll()

	/**
	 * Record a write, or throw for a failing schema.
	 *
	 * @param array<string, mixed> $object   The object.
	 * @param array<int, mixed>    $extend   Extend.
	 * @param mixed                $register The register.
	 * @param mixed                $schema   The schema.
	 * @param mixed                $uuid     The uuid.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(array $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): array {
		$extend = $options->extend ?? [];
		$register = $options->target->register;
		$schema = $options->target->schema;
		$uuid = $options->uuid;
		if (in_array((string)$schema, $this->failingSchemas, true) === true) {
			throw new RuntimeException('write refused on ' . $schema);
		}

		$this->saved[] = [(string)$schema, $uuid, $object];
		return $object;
	}//end saveObject()
}//end class

/**
 * The identity pass keeps going when a collaborator fails.
 */
#[CoversClass(UnifyClientContactIdentity::class)]
#[UsesClass(IdentityCollection::class)]
#[UsesClass(IdentityKind::class)]
#[UsesClass(IdentityPass::class)]
#[UsesClass(IdentityTally::class)]
#[UsesClass(EntityPayload::class)]
#[UsesClass(SerialisedPayload::class)]
final class UnifyClientContactIdentityFailureTest extends TestCase {

	/**
	 * Warnings logged, as [message, context].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * The step over the given collaborators.
	 *
	 * @param mixed       $contacts What the container answers for the Contacts manager; a Throwable is thrown.
	 * @param string|null $vcardUid What the vCard sync answers.
	 * @param bool        $withSourceRecords Whether the sourceRecord schema is configured.
	 *
	 * @return UnifyClientContactIdentity
	 */
	private function step(mixed $contacts, ?string $vcardUid='u-new', bool $withSourceRecords=true): UnifyClientContactIdentity {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default='') use ($withSourceRecords): string {
				$values = ['client_schema' => 'client-s', 'contact_schema' => 'contact-s'];
				if ($withSourceRecords === true) {
					$values['sourceRecord_schema'] = 'source-s';
				}

				return ($values[$key] ?? $default);
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		if ($contacts instanceof \Throwable) {
			$container->method('get')->willThrowException($contacts);
		} else {
			$container->method('get')->willReturn($contacts);
		}

		$vcards = $this->createMock(ContactVcardService::class);
		$vcards->method('syncToContacts')->willReturn($vcardUid);

		$tickets = $this->createMock(TicketService::class);
		$tickets->method('getSchemaId')->willReturn('ticket-s');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new UnifyClientContactIdentity($this->createMock(IAppManager::class), $appConfig, $vcards, $container, $tickets, $logger);
	}//end step()

	/**
	 * A Contacts manager that finds one UID per known term, or throws for one term.
	 *
	 * @param bool $enabled Whether it says it is enabled.
	 *
	 * @return object
	 */
	private static function contacts(bool $enabled=true): object {
		return new class ($enabled) {
			/**
			 * Constructor.
			 *
			 * @param bool $enabled Whether the manager is enabled.
			 */
			public function __construct(private bool $enabled) {
			}

			/**
			 * @return bool
			 */
			public function isEnabled(): bool {
				return $this->enabled;
			}

			/**
			 * Search the address book.
			 *
			 * @param string               $term       The pattern.
			 * @param array<int, string>   $properties The properties.
			 * @param array<string, mixed> $options    Options.
			 *
			 * @return array<int, array<string, string>>
			 */
			public function search(string $term, array $properties, array $options): array {
				if ($term === 'broken@example.nl') {
					throw new RuntimeException('address book offline');
				}

				return $term === 'jan@example.nl' ? [['UID' => 'u-jan']] : [];
			}
		};
	}//end contacts()

	/**
	 * The warning messages, in order.
	 *
	 * @return array<int, string>
	 */
	private function warningMessages(): array {
		return array_column($this->warnings, 0);
	}//end warningMessages()

	public function testAnUnresolvableContactsManagerFallsBackOnTheVcardSync(): void {
		$store = new FailingIdentityStore();
		$store->rows = ['client-s' => [['id' => 'c1', 'email' => 'jan@example.nl']]];

		$result = $this->step(contacts: new RuntimeException('no contacts app'))->migrate(objectService: $store, register: 'reg');

		// No search ran: the client is linked to the UID the vCard sync made.
		$this->assertSame(['c1' => 'u-new'], $result['auditMap']);
		$this->assertSame(1, $result['clientsLinked']);
		$this->assertSame([['Identity unify: Contacts IManager not resolvable', ['exception' => 'no contacts app']]], $this->warnings);
	}//end testAnUnresolvableContactsManagerFallsBackOnTheVcardSync()

	public function testADisabledContactsManagerIsNotSearched(): void {
		$store = new FailingIdentityStore();
		$store->rows = ['client-s' => [['id' => 'c1', 'email' => 'jan@example.nl']]];

		$result = $this->step(contacts: self::contacts(enabled: false))->migrate(objectService: $store, register: 'reg');

		// u-jan would have been found by a search; the disabled manager is skipped silently.
		$this->assertSame(['c1' => 'u-new'], $result['auditMap']);
		$this->assertSame([], $this->warnings);
	}//end testADisabledContactsManagerIsNotSearched()

	public function testAFailedSearchCountsAsNoMatch(): void {
		$store = new FailingIdentityStore();
		$store->rows = ['client-s' => [['id' => 'c1', 'email' => 'broken@example.nl']]];

		$result = $this->step(contacts: self::contacts())->migrate(objectService: $store, register: 'reg');

		$this->assertSame(['c1' => 'u-new'], $result['auditMap']);
		$this->assertSame(
			[['Identity unify: contact search failed', ['term' => 'broken@example.nl', 'exception' => 'address book offline']]],
			$this->warnings
		);
	}//end testAFailedSearchCountsAsNoMatch()

	public function testAVcardSyncWithoutAUidLeavesTheObjectUnresolved(): void {
		$store = new FailingIdentityStore();
		$store->rows = ['client-s' => [['id' => 'c1', 'email' => 'nobody@example.nl']], 'contact-s' => [['id' => 'k1', 'email' => 'nobody@example.nl']]];

		$result = $this->step(contacts: self::contacts(), vcardUid: '')->migrate(objectService: $store, register: 'reg');

		$this->assertSame([0, 0, 2, []], [$result['clientsLinked'], $result['contactsLinked'], $result['unresolved'], $result['auditMap']]);
		$this->assertSame([], $store->saved);
	}//end testAVcardSyncWithoutAUidLeavesTheObjectUnresolved()

	public function testFailedWritesAreLoggedAndThePassGoesOn(): void {
		$store = new FailingIdentityStore();
		$store->failingSchemas = ['client-s', 'ticket-s', 'source-s'];
		$store->rows = [
			'client-s' => [['id' => 'c1', 'email' => 'jan@example.nl']],
			'contact-s' => [['id' => 'k1', 'email' => 'jan@example.nl']],
			'ticket-s' => [['id' => 'm1', 'client' => 'c1']],
		];

		$result = $this->step(contacts: self::contacts())->migrate(objectService: $store, register: 'reg');

		// The contact write went through without runAsSystem; the rest failed and were logged.
		$this->assertSame([['contact-s', 'k1', ['id' => 'k1', 'email' => 'jan@example.nl', 'contactsUid' => 'u-jan']]], $store->saved);
		$this->assertSame(0, $result['momentsRekeyed']);
		$this->assertSame(['c1' => 'u-jan', 'k1' => 'u-jan'], $result['auditMap']);
		$this->assertSame(
			[
				'Identity unify: failed to persist contactsUid on object',
				'Identity unify: sourceRecord provenance write failed',
				'Identity unify: sourceRecord provenance write failed',
				'Identity unify: failed to re-key contactmoment',
			],
			$this->warningMessages()
		);
		$this->assertSame(['objectId' => 'c1', 'exceptionClass' => RuntimeException::class, 'exception' => 'write refused on client-s'], $this->warnings[0][1]);
		$this->assertSame('c1', $this->warnings[1][1]['nativeId']);
		$this->assertSame(['momentId' => 'm1', 'exceptionClass' => RuntimeException::class, 'exception' => 'write refused on ticket-s'], $this->warnings[3][1]);
	}//end testFailedWritesAreLoggedAndThePassGoesOn()

	public function testWithoutASourceRecordSchemaNoProvenanceIsWritten(): void {
		$store = new FailingIdentityStore();
		$store->rows = ['client-s' => [['id' => 'c1', 'email' => 'jan@example.nl']]];

		$this->step(contacts: self::contacts(), withSourceRecords: false)->migrate(objectService: $store, register: 'reg');

		$this->assertSame(['client-s'], array_column($store->saved, 0));
	}//end testWithoutASourceRecordSchemaNoProvenanceIsWritten()
}//end class
