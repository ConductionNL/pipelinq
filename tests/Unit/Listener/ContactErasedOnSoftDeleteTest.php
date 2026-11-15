<?php

/**
 * Contact erasure on a soft delete.
 *
 * An OpenRegister API delete is a SOFT delete: DeleteObject::delete() sets the
 * deletion metadata (openregister lib/Service/Object/DeleteObject.php:361) and
 * saves through MagicMapper::update() (:374), which dispatches
 * ObjectUpdatedEvent with the trashed entity as the new object and the live one
 * as the old (lib/Db/MagicMapper.php:9922). ObjectDeletedEvent only fires on the
 * purge (lib/Db/MagicMapper.php:10001). So the erasure has to run on the update
 * that moves a contact into the trash, and the purge after it must change
 * nothing more.
 *
 * The events are constructed with the constructor and accessors of
 * openregister's own classes (ObjectUpdatedEvent(newObject, oldObject),
 * getNewObject(), getOldObject(); ObjectDeletedEvent(object), getObject()).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/erase-on-soft-delete/specs/consent-in-integriq/spec.md#requirement-a-soft-delete-erases-the-contact-in-integriq-req-cii-008
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Pipelinq\Listener\ContactErasedListener;
use OCA\Pipelinq\Service\ClientManagementIntegration;
use OCA\Pipelinq\Service\ConsentService;
use OCA\Pipelinq\Service\ContactAddressLookup;
use OCA\Pipelinq\Service\SchemaMapService;
use OCA\Pipelinq\Tests\Unit\Support\FakeIntegriq;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * A contact moved to the trash is erased in integriq, once.
 */
class ContactErasedOnSoftDeleteTest extends TestCase {

	/**
	 * Integriq's opt-out store, as the fake keeps it.
	 *
	 * @var FakeIntegriq
	 */
	private FakeIntegriq $integriq;

	/**
	 * Pipelinq's own consent store.
	 *
	 * @var InMemoryObjectService
	 */
	private InMemoryObjectService $store;

	/**
	 * The listener under test, wired to the real integration and consent service.
	 *
	 * @var ContactErasedListener
	 */
	private ContactErasedListener $listener;

	/**
	 * Build the listener on the real erasure path.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->integriq = new FakeIntegriq();
		$this->integriq->seed(['address' => 'jan@example.nl', 'state' => 'opted-out', 'scope' => 'channel', 'channel' => 'email', 'contactRef' => 'c-1', 'evidence' => ['text' => 'unsubscribe link']]);
		$this->store = new InMemoryObjectService();
		$this->store->put('messagingConsentRecord', ['contactId' => 'c-1', 'channel' => 'sms', 'state' => 'opted-in', 'recordedAt' => '2026-09-01T00:00:00Z']);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->store);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $a, string $k, string $d = '') => $d);
		$logger = new NullLogger();
		$client = FakeIntegriq::client($appConfig, $this->integriq);
		$consent = new ConsentService($container, $appConfig, $logger, $client, new ContactAddressLookup($container, $appConfig, $logger));
		$integration = new ClientManagementIntegration($consent, $logger, $client);

		$schemaMap = $this->createMock(SchemaMapService::class);
		$schemaMap->method('resolveEntityType')->willReturnCallback(static fn (?string $id) => ($id === '17' ? 'contact' : 'lead'));
		$this->listener = new ContactErasedListener($integration, $schemaMap);
	}//end setUp()

	/**
	 * An entity as OpenRegister holds it, live or in the trash.
	 *
	 * @param string $uuid    The object uuid.
	 * @param string $schema  The schema id.
	 * @param bool   $trashed Whether it carries deletion metadata.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(string $uuid, string $schema, bool $trashed): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setSchema($schema);
		if ($trashed === true) {
			// The keys DeleteObject::delete() writes (openregister DeleteObject.php:340-346).
			$entity->setDeleted(['deletedBy' => 'admin', 'deletedAt' => '2026-10-07T12:00:00+00:00', 'objectId' => $uuid]);
		}

		return $entity;
	}//end entity()

	/**
	 * A soft delete clears the contact link and the evidence and keeps the opt-out.
	 *
	 * @return void
	 */
	public function testASoftDeleteErasesTheContactAndKeepsTheOptOut(): void {
		$event = new ObjectUpdatedEvent(newObject: $this->entity('c-1', '17', true), oldObject: $this->entity('c-1', '17', false));
		$this->listener->handle($event);

		self::assertCount(1, $this->integriq->changeEvents);
		$request = $this->integriq->changeEvents[0]->toRequest();
		self::assertSame('erase-contact', $request['state']);
		self::assertSame('c-1', $request['contactRef']);

		$row = $this->integriq->rowsFor('jan@example.nl')[0];
		self::assertSame('opted-out', $row['state'], 'the opt-out stays');
		self::assertSame('', $row['contactRef'], 'the contact link is gone');
		self::assertSame([], $row['evidence'], 'the evidence is gone');
		self::assertSame([], $this->store->ofSchema('messagingConsentRecord'), 'pipelinq history is deleted');
	}//end testASoftDeleteErasesTheContactAndKeepsTheOptOut()

	/**
	 * The purge after a soft delete changes nothing more.
	 *
	 * @return void
	 */
	public function testThePurgeAfterASoftDeleteChangesNothingMore(): void {
		$this->listener->handle(new ObjectUpdatedEvent(newObject: $this->entity('c-1', '17', true), oldObject: $this->entity('c-1', '17', false)));
		$rowsAfterSoftDelete = $this->integriq->rows;
		$historyAfterSoftDelete = $this->store->ofSchema('messagingConsentRecord');

		$this->listener->handle(new ObjectDeletedEvent($this->entity('c-1', '17', true)));

		self::assertSame($rowsAfterSoftDelete, $this->integriq->rows, 'integriq rows are unchanged by the purge');
		self::assertSame($historyAfterSoftDelete, $this->store->ofSchema('messagingConsentRecord'));
		self::assertSame('opted-out', $this->integriq->rowsFor('jan@example.nl')[0]['state']);
	}//end testThePurgeAfterASoftDeleteChangesNothingMore()

	/**
	 * Updates that are not a move into the trash erase nothing.
	 *
	 * A restore (trashed to live) is one of them: the evidence was dropped at
	 * the soft delete and a restore cannot bring it back.
	 *
	 * @return void
	 */
	public function testOtherUpdatesEraseNothing(): void {
		$cases = [
			'an ordinary edit' => [false, false, '17'],
			'a restore' => [false, true, '17'],
			'an edit inside the trash' => [true, true, '17'],
			'a soft-deleted lead' => [true, false, '99'],
		];
		foreach ($cases as $why => [$newTrashed, $oldTrashed, $schema]) {
			$this->listener->handle(
				new ObjectUpdatedEvent(newObject: $this->entity('c-1', $schema, $newTrashed), oldObject: $this->entity('c-1', $schema, $oldTrashed))
			);
			self::assertSame([], $this->integriq->changeEvents, $why . ' erases nothing');
		}

		self::assertSame('c-1', $this->integriq->rowsFor('jan@example.nl')[0]['contactRef']);
	}//end testOtherUpdatesEraseNothing()

	/**
	 * An update event without an old object is not read as a soft delete of a live one.
	 *
	 * @return void
	 */
	public function testAnUpdateWithoutAnOldObjectStillErasesWhenTheNewOneIsTrashed(): void {
		$this->listener->handle(new ObjectUpdatedEvent(newObject: $this->entity('c-1', '17', true), oldObject: null));

		self::assertCount(1, $this->integriq->changeEvents);
	}//end testAnUpdateWithoutAnOldObjectStillErasesWhenTheNewOneIsTrashed()
}//end class
