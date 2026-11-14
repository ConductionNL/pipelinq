<?php

/**
 * Coverage tests for ContactSyncService: contact-first create, addressbook
 * search, import and the vCard sync delegation.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/contacts-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Service\ContactImportService;
use OCA\Pipelinq\Service\ContactLinkedUidsService;
use OCA\Pipelinq\Service\ContactSyncService;
use OCA\Pipelinq\Service\ContactVcardService;
use OCA\Pipelinq\Service\VcardRevision;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Contact sync over mocked Contacts, register and collaborator services.
 *
 * @covers \OCA\Pipelinq\Service\ContactSyncService
 * @uses   \OCA\Pipelinq\Service\VcardRevision
 * @uses   \OCA\Pipelinq\AppInfo\OpenRegisterAutoloader
 */
class ContactSyncServiceCoverageTest extends TestCase {

	/**
	 * App-config values by key.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * The contacts manager.
	 *
	 * @var IContactsManager&MockObject
	 */
	private IContactsManager $contacts;

	/**
	 * The import service.
	 *
	 * @var ContactImportService&MockObject
	 */
	private ContactImportService $import;

	/**
	 * The vCard service.
	 *
	 * @var ContactVcardService&MockObject
	 */
	private ContactVcardService $vcards;

	/**
	 * The linked UIDs service.
	 *
	 * @var ContactLinkedUidsService&MockObject
	 */
	private ContactLinkedUidsService $linked;

	/**
	 * The object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * The service under test.
	 *
	 * @var ContactSyncService
	 */
	private ContactSyncService $service;

	/**
	 * Wire the service over mocked boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->config = ['register' => 'reg-1', 'client_schema' => 's-client', 'contact_schema' => 's-contact'];
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->config[$key] ?? $default
		);

		$this->contacts = $this->createMock(originalClassName: IContactsManager::class);
		$this->import = $this->createMock(originalClassName: ContactImportService::class);
		$this->vcards = $this->createMock(originalClassName: ContactVcardService::class);
		$this->linked = $this->createMock(originalClassName: ContactLinkedUidsService::class);
		$this->objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);

		$this->service = new ContactSyncService(
			contactsManager: $this->contacts,
			contactImportService: $this->import,
			contactVcardService: $this->vcards,
			linkedUidsService: $this->linked,
			appConfig: $appConfig,
			objectService: $this->objectService,
			revisions: new VcardRevision(),
		);
	}//end setUp()

	/**
	 * A stored entity echoing what was saved.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function stored(array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('obj-1');
		$entity->setObject($payload);

		return $entity;
	}//end stored()

	/**
	 * A provisioned contact identity.
	 *
	 * @param string $email The resolved email.
	 * @param string $phone The resolved phone.
	 *
	 * @return array<string, string> The provision result.
	 */
	private function provision(string $email = 'info@acme.nl', string $phone = '+31 20 123'): array {
		return ['contactsUid' => 'card-1', 'name' => 'Acme B.V.', 'email' => $email, 'phone' => $phone];
	}//end provision()

	/**
	 * An unknown object type is refused before anything is provisioned.
	 *
	 * @return void
	 */
	public function testCreateRefusesAnUnknownType(): void {
		$this->vcards->expects($this->never())->method('provisionContactFromForm');

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Invalid objectType -- must be client or contact');
		$this->service->createWithContact(objectType: 'lead', form: ['name' => 'Acme']);
	}//end testCreateRefusesAnUnknownType()

	/**
	 * A blank name is refused.
	 *
	 * @return void
	 */
	public function testCreateRefusesABlankName(): void {
		$this->vcards->expects($this->never())->method('provisionContactFromForm');

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Name is required');
		$this->service->createWithContact(objectType: 'client', form: ['name' => "  \t "]);
	}//end testCreateRefusesABlankName()

	/**
	 * A missing name is refused.
	 *
	 * @return void
	 */
	public function testCreateRefusesAMissingName(): void {
		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Name is required');
		$this->service->createWithContact(objectType: 'contact', form: ['email' => 'a@b.nl']);
	}//end testCreateRefusesAMissingName()

	/**
	 * A contact that cannot be provisioned is refused and nothing is saved.
	 *
	 * @return void
	 */
	public function testCreateRefusesWhenTheContactCannotBeProvisioned(): void {
		$this->vcards->method('provisionContactFromForm')->willReturn(null);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Could not provision the Nextcloud contact -- is the Contacts app enabled?');
		$this->service->createWithContact(objectType: 'client', form: ['name' => 'Acme']);
	}//end testCreateRefusesWhenTheContactCannotBeProvisioned()

	/**
	 * A client is saved with the provisioned identity, minus id and envelope.
	 *
	 * @return void
	 */
	public function testCreateSavesTheClientWithTheProvisionedIdentity(): void {
		$form = ['id' => 'forged', '@self' => ['x' => 1], 'name' => 'acme', 'type' => 'organization', 'email' => 'old@acme.nl', 'phone' => '000'];
		$this->vcards->expects($this->once())->method('provisionContactFromForm')
			->with($form, 'client')
			->willReturn($this->provision());
		$expected = ['name' => 'Acme B.V.', 'type' => 'organization', 'email' => 'info@acme.nl', 'phone' => '+31 20 123', 'contactsUid' => 'card-1'];
		$this->objectService->expects($this->once())->method('saveObject')
			->with($expected, [], 'reg-1', 's-client', null)
			->willReturnCallback(fn (array $object): ObjectEntity => $this->stored(payload: $object));

		$created = $this->service->createWithContact(objectType: 'client', form: $form);

		$this->assertSame(expected: 'card-1', actual: $created['contactsUid']);
		$this->assertSame(expected: 'Acme B.V.', actual: $created['name']);
		$this->assertSame(expected: 'info@acme.nl', actual: $created['email']);
		$this->assertSame(expected: '+31 20 123', actual: $created['phone']);
		$this->assertSame(expected: 'obj-1', actual: $created['id']);
	}//end testCreateSavesTheClientWithTheProvisionedIdentity()

	/**
	 * An empty provisioned email or phone leaves the form's own value.
	 *
	 * @return void
	 */
	public function testCreateKeepsFormEmailAndPhoneWhenTheContactHasNone(): void {
		$this->vcards->method('provisionContactFromForm')->willReturn($this->provision(email: '', phone: ''));
		$this->objectService->expects($this->once())->method('saveObject')
			->with(
				['name' => 'Acme B.V.', 'email' => 'form@acme.nl', 'phone' => '06', 'contactsUid' => 'card-1'],
				[],
				'reg-1',
				's-contact',
				null
			)
			->willReturnCallback(fn (array $object): ObjectEntity => $this->stored(payload: $object));

		$created = $this->service->createWithContact(objectType: 'contact', form: ['name' => 'Acme', 'email' => 'form@acme.nl', 'phone' => '06']);

		$this->assertSame(expected: 'form@acme.nl', actual: $created['email']);
		$this->assertSame(expected: '06', actual: $created['phone']);
	}//end testCreateKeepsFormEmailAndPhoneWhenTheContactHasNone()

	/**
	 * Without email or phone anywhere, none are added.
	 *
	 * @return void
	 */
	public function testCreateAddsNoEmptyEmailOrPhone(): void {
		$this->vcards->method('provisionContactFromForm')->willReturn($this->provision(email: '', phone: ''));
		$this->objectService->expects($this->once())->method('saveObject')
			->with(['name' => 'Acme B.V.', 'contactsUid' => 'card-1'], [], 'reg-1', 's-client', null)
			->willReturnCallback(fn (array $object): ObjectEntity => $this->stored(payload: $object));

		$created = $this->service->createWithContact(objectType: 'client', form: ['name' => 'Acme']);

		$this->assertArrayNotHasKey(key: 'email', array: $created);
		$this->assertArrayNotHasKey(key: 'phone', array: $created);
	}//end testCreateAddsNoEmptyEmailOrPhone()

	/**
	 * An unconfigured register refuses the create after provisioning.
	 *
	 * @return void
	 */
	public function testCreateRefusesWithoutARegister(): void {
		$this->config['register'] = '';
		$this->vcards->method('provisionContactFromForm')->willReturn($this->provision());
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Pipelinq register or schema is not configured');
		$this->service->createWithContact(objectType: 'client', form: ['name' => 'Acme']);
	}//end testCreateRefusesWithoutARegister()

	/**
	 * An unconfigured schema for the type refuses the create.
	 *
	 * @return void
	 */
	public function testCreateRefusesWithoutASchema(): void {
		unset($this->config['contact_schema']);
		$this->vcards->method('provisionContactFromForm')->willReturn($this->provision());
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Pipelinq register or schema is not configured');
		$this->service->createWithContact(objectType: 'contact', form: ['name' => 'Acme']);
	}//end testCreateRefusesWithoutASchema()

	/**
	 * Without the Contacts app a search finds nothing and does not query.
	 *
	 * @return void
	 */
	public function testSearchWithoutContactsFindsNothing(): void {
		$this->contacts->method('isEnabled')->willReturn(false);
		$this->contacts->expects($this->never())->method('search');
		$this->linked->expects($this->never())->method('getLinkedContactsUids');

		$this->assertSame(expected: [], actual: $this->service->searchContacts(query: 'acme'));
	}//end testSearchWithoutContactsFindsNothing()

	/**
	 * Search results are flattened, skip UID-less cards and flag linked ones.
	 *
	 * @return void
	 */
	public function testSearchFormatsResultsAndFlagsLinkedCards(): void {
		$this->contacts->method('isEnabled')->willReturn(true);
		$this->contacts->expects($this->once())->method('search')
			->with('acme', ['FN', 'EMAIL', 'TEL', 'ORG'], ['limit' => 50])
			->willReturn(
				[
					['UID' => 'u-1', 'FN' => 'Jan Jansen', 'EMAIL' => ['jan@acme.nl', 'j@home.nl'], 'TEL' => ['06-1'], 'ORG' => 'Acme', 'addressbook-key' => '3'],
					['FN' => 'No uid'],
					['UID' => 'u-2', 'EMAIL' => []],
				]
			);
		$this->linked->method('getLinkedContactsUids')->willReturn(['u-2']);

		$results = $this->service->searchContacts(query: 'acme');

		$this->assertSame(
			expected: [
				[
					'uid' => 'u-1',
					'name' => 'Jan Jansen',
					'email' => 'jan@acme.nl',
					'phone' => '06-1',
					'org' => 'Acme',
					'addressBookKey' => '3',
					'alreadyLinked' => false,
				],
				[
					'uid' => 'u-2',
					'name' => '',
					'email' => '',
					'phone' => '',
					'org' => '',
					'addressBookKey' => '',
					'alreadyLinked' => true,
				],
			],
			actual: $results
		);
	}//end testSearchFormatsResultsAndFlagsLinkedCards()

	/**
	 * A search with no hits returns an empty list.
	 *
	 * @return void
	 */
	public function testSearchWithNoHitsIsEmpty(): void {
		$this->contacts->method('isEnabled')->willReturn(true);
		$this->contacts->method('search')->willReturn([]);
		$this->linked->method('getLinkedContactsUids')->willReturn(['u-1']);

		$this->assertSame(expected: [], actual: $this->service->searchContacts(query: 'nobody'));
	}//end testSearchWithNoHitsIsEmpty()

	/**
	 * Import without the Contacts app is refused.
	 *
	 * @return void
	 */
	public function testImportWithoutContactsIsRefused(): void {
		$this->contacts->method('isEnabled')->willReturn(false);
		$this->contacts->expects($this->never())->method('search');

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Nextcloud Contacts is not available');
		$this->service->importContact(uid: 'u-1', addressBookKey: '3');
	}//end testImportWithoutContactsIsRefused()

	/**
	 * Import of a card whose UID is not found is refused.
	 *
	 * @return void
	 */
	public function testImportOfAnUnknownCardIsRefused(): void {
		$this->contacts->method('isEnabled')->willReturn(true);
		$this->contacts->expects($this->once())->method('search')
			->with('u-1', ['UID'], ['limit' => 1, 'types' => true])
			->willReturn([['UID' => 'u-10'], ['FN' => 'no uid']]);
		$this->import->expects($this->never())->method('importAsClient');

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Contact not found in Nextcloud addressbook');
		$this->service->importContact(uid: 'u-1', addressBookKey: '3');
	}//end testImportOfAnUnknownCardIsRefused()

	/**
	 * Import defaults to a client and hands over the exact-UID card.
	 *
	 * @return void
	 */
	public function testImportAsClientByDefault(): void {
		$card = ['UID' => 'u-1', 'FN' => 'Acme'];
		$this->contacts->method('isEnabled')->willReturn(true);
		$this->contacts->method('search')->willReturn([['UID' => 'u-other'], $card]);
		$this->import->expects($this->once())->method('importAsClient')->with($card, 'u-1')->willReturn(['id' => 'client-1']);
		$this->import->expects($this->never())->method('importAsContact');

		$this->assertSame(expected: ['id' => 'client-1'], actual: $this->service->importContact(uid: 'u-1', addressBookKey: '3'));
	}//end testImportAsClientByDefault()

	/**
	 * Import as a contact passes the client id along.
	 *
	 * @return void
	 */
	public function testImportAsContactPassesTheClient(): void {
		$card = ['UID' => 'u-1', 'FN' => 'Jan'];
		$this->contacts->method('isEnabled')->willReturn(true);
		$this->contacts->method('search')->willReturn([$card]);
		$this->import->expects($this->never())->method('importAsClient');
		$this->import->expects($this->once())->method('importAsContact')->with($card, 'u-1', 'client-7')->willReturn(['id' => 'contact-1']);

		$result = $this->service->importContact(uid: 'u-1', addressBookKey: '3', type: 'contact', clientId: 'client-7');

		$this->assertSame(expected: ['id' => 'contact-1'], actual: $result);
	}//end testImportAsContactPassesTheClient()

	/**
	 * Import as a contact without a client passes null.
	 *
	 * @return void
	 */
	public function testImportAsContactWithoutAClient(): void {
		$card = ['UID' => 'u-1'];
		$this->contacts->method('isEnabled')->willReturn(true);
		$this->contacts->method('search')->willReturn([$card]);
		$this->import->expects($this->once())->method('importAsContact')->with($card, 'u-1', null)->willReturn([]);

		$this->assertSame(expected: [], actual: $this->service->importContact(uid: 'u-1', addressBookKey: '', type: 'contact'));
	}//end testImportAsContactWithoutAClient()

	/**
	 * Sync to Contacts delegates and returns the card UID.
	 *
	 * @return void
	 */
	public function testSyncDelegatesToTheVcardService(): void {
		$this->vcards->expects($this->once())->method('syncToContacts')->with('client', 'obj-1')->willReturn('card-1');

		$this->assertSame(expected: 'card-1', actual: $this->service->syncToContacts(objectType: 'client', objectId: 'obj-1'));
	}//end testSyncDelegatesToTheVcardService()

	/**
	 * Sync to Contacts passes a null result through.
	 *
	 * @return void
	 */
	public function testSyncPassesNullThrough(): void {
		$this->vcards->expects($this->once())->method('syncToContacts')->with('contact', 'obj-2')->willReturn(null);

		$this->assertNull(actual: $this->service->syncToContacts(objectType: 'contact', objectId: 'obj-2'));
	}//end testSyncPassesNullThrough()

	/**
	 * A card status reads an array-valued REV and the matching book.
	 *
	 * @return void
	 */
	public function testCardStatusReadsAnArrayRev(): void {
		$this->contacts->method('isEnabled')->willReturn(true);
		$this->contacts->method('search')->willReturn([['UID' => 'u-1', 'REV' => ['20261027T091200Z'], 'addressbook-key' => '9']]);
		$this->contacts->method('getUserAddressBooks')->willReturn([]);

		$status = $this->service->cardStatus(uid: 'u-1');

		$this->assertSame(
			expected: ['linked' => true, 'addressBook' => '', 'updatedAt' => '2026-10-27T09:12:00+00:00'],
			actual: $status
		);
	}//end testCardStatusReadsAnArrayRev()
}//end class
