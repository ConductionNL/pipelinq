<?php

/**
 * Unit tests for ClientMergeReferenceListener and PartyReferenceMover: a merge
 * moves the merged-away client's references onto the record that stays, and a
 * reversal moves exactly those back, skipping a row changed in between.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectsMergedEvent;
use OCA\Pipelinq\Listener\ClientMergeReferenceListener;
use OCA\Pipelinq\Service\Merge\PartyReferenceMover;
use OCA\Pipelinq\Service\Merge\PartyReferences;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Pipelinq\Listener\ClientMergeReferenceListener
 * @covers \OCA\Pipelinq\Service\Merge\PartyReferenceMover
 * @covers \OCA\Pipelinq\Service\Merge\PartyReferences
 */
class ClientMergeReferenceListenerTest extends TestCase {

	private const CLIENT_SCHEMA = '12';
	private const CONTACT_SCHEMA = '13';

	/**
	 * The register: uuid => [schema slug or id, data].
	 *
	 * @var array<string, array{0: string, 1: array<string, mixed>}>
	 */
	private array $rows = [];

	/**
	 * Every patch, as [uuid, data].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $patches = [];

	private LoggerInterface&MockObject $logger;

	private ClientMergeReferenceListener $listener;

	/**
	 * An in-memory object service over $rows, and the listener over it.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->rows = [
			'client-a' => [self::CLIENT_SCHEMA, ['name' => 'Bakkerij Jansen']],
			'client-s' => [self::CLIENT_SCHEMA, ['name' => 'Bakkerij Jansen B.V.']],
			'lead-1' => ['lead', ['title' => 'Oven', 'client' => 'client-a']],
			'lead-2' => ['lead', ['title' => 'Kassa', 'client' => 'client-x']],
			'ticket-1' => ['ticket', ['title' => 'Storing', 'client' => 'client-a', 'contact' => 'contact-p']],
			'contact-p' => [self::CONTACT_SCHEMA, ['name' => 'Piet', 'client' => 'client-a']],
		];

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			fn (int|string $id): ?ObjectEntityInterface => isset($this->rows[(string)$id]) === true ? $this->entity(uuid: (string)$id) : null
		);
		$objects->method('searchObjectsBySlug')->willReturnCallback(
			function (string $registerSlug, string $schemaSlug, array $filters = []): array {
				self::assertSame('pipelinq', $registerSlug);
				// Like a query layer that ignores the filter: every row of the schema comes back.
				$found = [];
				foreach ($this->rows as $uuid => [$schema]) {
					$slug = [self::CLIENT_SCHEMA => 'client', self::CONTACT_SCHEMA => 'contact'][$schema] ?? $schema;
					if ($slug === $schemaSlug) {
						$found[] = $this->entity(uuid: $uuid);
					}
				}

				return $found;
			}
		);
		$objects->method('patchObject')->willReturnCallback(
			function (string $objectId, array $data): ObjectEntityInterface {
				$this->patches[] = [$objectId, $data];
				$this->rows[$objectId][1] = array_merge($this->rows[$objectId][1], $data);
				return $this->entity(uuid: $objectId);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => ['client_schema' => self::CLIENT_SCHEMA, 'contact_schema' => self::CONTACT_SCHEMA][$key] ?? ''
		);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->listener = new ClientMergeReferenceListener(
			objects: $objects,
			mover: new PartyReferenceMover(objects: $objects),
			appConfig: $appConfig,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * An entity double over the current row.
	 *
	 * @param string $uuid The row.
	 *
	 * @return ObjectEntityInterface&MockObject
	 */
	private function entity(string $uuid): ObjectEntityInterface&MockObject {
		[$schema, $data] = $this->rows[$uuid];
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getUuid')->willReturn($uuid);
		$entity->method('getSchema')->willReturn($schema);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * The merge of client-a into client-s, or its reversal.
	 *
	 * @param bool $reversal Whether it is the reversal.
	 *
	 * @return ObjectsMergedEvent
	 */
	private static function merge(bool $reversal = false): ObjectsMergedEvent {
		return new ObjectsMergedEvent(survivorUuid: 'client-s', mergedFromUuids: ['client-a'], mergeOperationId: 'op-1', isReversal: $reversal);
	}//end merge()

	/**
	 * A merge moves the lead, the ticket and the contact onto the client that stays, and lists them.
	 *
	 * @return void
	 */
	public function testAMergeMovesTheReferencesAndListsThem(): void {
		$this->listener->handle(event: self::merge());

		self::assertSame('client-s', $this->rows['lead-1'][1]['client']);
		self::assertSame('client-s', $this->rows['ticket-1'][1]['client']);
		self::assertSame('client-s', $this->rows['contact-p'][1]['client']);
		self::assertSame('client-x', $this->rows['lead-2'][1]['client'], 'Another client\'s lead is never moved.');
		self::assertSame('contact-p', $this->rows['ticket-1'][1]['contact']);
		self::assertSame(
			[
				['schema' => 'contact', 'uuid' => 'contact-p', 'property' => 'client'],
				['schema' => 'lead', 'uuid' => 'lead-1', 'property' => 'client'],
				['schema' => 'ticket', 'uuid' => 'ticket-1', 'property' => 'client'],
			],
			$this->rows['client-a'][1][PartyReferences::LEDGER_FIELD]
		);
	}//end testAMergeMovesTheReferencesAndListsThem()

	/**
	 * A reversal moves the listed rows back, skips the one changed in between, reports it and clears the list.
	 *
	 * @return void
	 */
	public function testAReversalReturnsTheRowsAndSkipsOneChangedSince(): void {
		$this->listener->handle(event: self::merge());
		$this->rows['ticket-1'][1]['client'] = 'client-x';
		$this->rows['lead-2'][1]['client'] = 'client-s';
		$this->logger->expects(self::once())->method('warning')->with(
			'Pipelinq merge: a row changed since the merge was not moved back',
			['mergedFrom' => 'client-a', 'survivor' => 'client-s', 'operation' => 'op-1', 'schema' => 'ticket', 'uuid' => 'ticket-1', 'property' => 'client']
		);

		$this->listener->handle(event: self::merge(reversal: true));

		self::assertSame('client-a', $this->rows['lead-1'][1]['client']);
		self::assertSame('client-a', $this->rows['contact-p'][1]['client']);
		self::assertSame('client-x', $this->rows['ticket-1'][1]['client']);
		self::assertSame('client-s', $this->rows['lead-2'][1]['client'], 'A row the merge never moved stays where it is.');
		self::assertNull($this->rows['client-a'][1][PartyReferences::LEDGER_FIELD]);
	}//end testAReversalReturnsTheRowsAndSkipsOneChangedSince()

	/**
	 * A merge of a contact moves contact references only.
	 *
	 * @return void
	 */
	public function testAContactMergeMovesContactReferences(): void {
		$this->rows['contact-q'] = [self::CONTACT_SCHEMA, ['name' => 'Piet J.']];

		$this->listener->handle(event: new ObjectsMergedEvent(survivorUuid: 'contact-q', mergedFromUuids: ['contact-p'], mergeOperationId: 'op-2'));

		self::assertSame('contact-q', $this->rows['ticket-1'][1]['contact']);
		self::assertSame('client-a', $this->rows['ticket-1'][1]['client']);
		self::assertSame([['schema' => 'ticket', 'uuid' => 'ticket-1', 'property' => 'contact']], $this->rows['contact-p'][1][PartyReferences::LEDGER_FIELD]);
	}//end testAContactMergeMovesContactReferences()

	/**
	 * A merge of anything else, another event, or a vanished record moves nothing.
	 *
	 * @return void
	 */
	public function testOtherRecordsAndEventsAreIgnored(): void {
		$this->listener->handle(event: new ObjectsMergedEvent(survivorUuid: 'lead-2', mergedFromUuids: ['lead-1', 'gone'], mergeOperationId: 'op-3'));
		$this->listener->handle(event: new Event());

		self::assertSame([], $this->patches);
	}//end testOtherRecordsAndEventsAreIgnored()

	/**
	 * A failure on one merged record is logged and does not stop the merge.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedNotThrown(): void {
		$mover = $this->createMock(PartyReferenceMover::class);
		$mover->method('move')->willThrowException(new RuntimeException('register down'));
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturn($this->entity(uuid: 'client-a'));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn(self::CLIENT_SCHEMA);
		$this->logger->expects(self::once())->method('error')->with(
			'Pipelinq merge: the references of a merged record could not be moved',
			['mergedFrom' => 'client-a', 'survivor' => 'client-s', 'reversal' => false, 'error' => 'register down']
		);

		(new ClientMergeReferenceListener(objects: $objects, mover: $mover, appConfig: $appConfig, logger: $this->logger))->handle(event: self::merge());
	}//end testAFailureIsLoggedNotThrown()
}//end class
