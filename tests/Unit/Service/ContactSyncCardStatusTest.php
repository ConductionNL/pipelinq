<?php

/**
 * Unit tests for ContactSyncService::cardStatus().
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.app
 *
 * @spec openspec/changes/contact-person-page-as-board/specs/contact-person-page-as-board/spec.md#requirement-the-contact-page-shows-its-address-book-card
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\ContactImportService;
use OCA\Pipelinq\Service\ContactLinkedUidsService;
use OCA\Pipelinq\Service\ContactSyncService;
use OCA\Pipelinq\Service\ContactVcardService;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IAddressBook;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * A record's address book card: linked or not, its book, its last change.
 */
class ContactSyncCardStatusTest extends TestCase {
	/**
	 * Build the service over an address book that holds the given cards.
	 *
	 * @param array<int, array<string, mixed>> $cards The cards a UID search finds.
	 * @param bool $enabled Whether Contacts is available.
	 *
	 * @return ContactSyncService The service.
	 */
	private function service(array $cards, bool $enabled = true): ContactSyncService {
		$book = $this->createMock(originalClassName: IAddressBook::class);
		$book->method('getKey')->willReturn('7');
		$book->method('getDisplayName')->willReturn('Pipelinq CRM');

		$manager = $this->createMock(originalClassName: IContactsManager::class);
		$manager->method('isEnabled')->willReturn($enabled);
		$manager->method('search')->willReturn($cards);
		$manager->method('getUserAddressBooks')->willReturn([$book]);

		return new ContactSyncService(
			contactsManager: $manager,
			contactImportService: $this->createMock(originalClassName: ContactImportService::class),
			contactVcardService: $this->createMock(originalClassName: ContactVcardService::class),
			linkedUidsService: $this->createMock(originalClassName: ContactLinkedUidsService::class),
			appConfig: $this->createMock(originalClassName: IAppConfig::class),
			objectService: $this->createMock(originalClassName: ObjectServiceInterface::class),
		);
	}//end service()

	/**
	 * A card names its address book and its last change.
	 *
	 * @return void
	 */
	public function testALinkedCardNamesItsBookAndLastChange(): void {
		$status = $this->service(cards: [['UID' => 'u-1', 'addressbook-key' => 7, 'REV' => '20261027T091200Z']])->cardStatus(uid: 'u-1');

		$this->assertSame(
			expected: ['linked' => true, 'addressBook' => 'Pipelinq CRM', 'updatedAt' => '2026-10-27T09:12:00+00:00'],
			actual: $status
		);
	}//end testALinkedCardNamesItsBookAndLastChange()

	/**
	 * No card, no uid, or no Contacts app: not linked.
	 *
	 * @return void
	 */
	public function testNoCardIsNotLinked(): void {
		$none = ['linked' => false, 'addressBook' => '', 'updatedAt' => null];
		$this->assertSame(expected: $none, actual: $this->service(cards: [['UID' => 'other']])->cardStatus(uid: 'u-1'));
		$this->assertSame(expected: $none, actual: $this->service(cards: [])->cardStatus(uid: ''));
		$this->assertSame(expected: $none, actual: $this->service(cards: [['UID' => 'u-1']], enabled: false)->cardStatus(uid: 'u-1'));
	}//end testNoCardIsNotLinked()

	/**
	 * A card without REV is linked with no moment.
	 *
	 * @return void
	 */
	public function testACardWithoutRevHasNoMoment(): void {
		$status = $this->service(cards: [['UID' => 'u-1', 'addressbook-key' => '7']])->cardStatus(uid: 'u-1');

		$this->assertTrue(condition: $status['linked']);
		$this->assertNull(actual: $status['updatedAt']);
	}//end testACardWithoutRevHasNoMoment()
}//end class
