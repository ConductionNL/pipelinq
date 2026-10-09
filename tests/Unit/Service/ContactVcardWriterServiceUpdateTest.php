<?php

/**
 * Unit tests for ContactVcardWriterService updating a card that already exists.
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
 *
 * @spec openspec/changes/round4-contact-write-back/specs/contacts-sync/spec.md#requirement-write-back-updates-the-existing-card
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\ContactVcardWriterService;
use OCA\Pipelinq\Service\RegisterResolverService;
use OCP\Constants;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IAddressBook;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The write-back of an edited client whose contact already exists.
 *
 * The addressbook below follows Nextcloud's AddressBookImpl and CardDavBackend:
 * createOrUpdate() updates only by `URI`, and a create refuses a second card
 * with a UID that is already in the addressbook. The old code passed the UID
 * without the URI, so every such edit hit that refusal and the contact kept
 * its old values (cloud check round 3, item 1).
 */
class ContactVcardWriterServiceUpdateTest extends TestCase {
	/**
	 * An addressbook with Nextcloud's create/update semantics.
	 *
	 * @param array<string, array<string, mixed>> $cards Cards keyed by URI.
	 * @param bool $system Whether this is the system addressbook.
	 *
	 * @return IAddressBook
	 */
	private function addressBook(array $cards, bool $system = false): IAddressBook {
		return new class($cards, $system) implements IAddressBook {
			/**
			 * Constructor.
			 *
			 * @param array<string, array<string, mixed>> $cards  Cards keyed by URI.
			 * @param bool                                $system System addressbook.
			 */
			public function __construct(
				public array $cards,
				private bool $system,
			) {
			}//end __construct()

			/**
			 * The key.
			 *
			 * @return string
			 */
			public function getKey(): string {
				return '1';
			}//end getKey()

			/**
			 * The URI.
			 *
			 * @return string
			 */
			public function getUri(): string {
				return $this->system === true ? 'system' : 'contacts';
			}//end getUri()

			/**
			 * The display name.
			 *
			 * @return string
			 */
			public function getDisplayName() {
				return $this->getUri();
			}//end getDisplayName()

			/**
			 * Search by property, exact match when wildcard is false.
			 *
			 * @param string $pattern          The pattern.
			 * @param array  $searchProperties The properties.
			 * @param array  $options          The options.
			 *
			 * @return array
			 */
			public function search($pattern, $searchProperties, $options) {
				$hits = [];
				foreach ($this->cards as $uri => $card) {
					foreach ($searchProperties as $prop) {
						if (($card[$prop] ?? null) === $pattern) {
							$hits[] = ['URI' => $uri] + $card;
							break;
						}
					}
				}

				return $hits;
			}//end search()

			/**
			 * Create without URI, update with URI, like AddressBookImpl.
			 *
			 * @param array $properties The properties.
			 *
			 * @return array
			 */
			public function createOrUpdate($properties) {
				if (isset($properties['URI']) === false) {
					$uid = $properties['UID'] ?? 'generated-uid';
					foreach ($this->cards as $card) {
						if (($card['UID'] ?? null) === $uid) {
							throw new \RuntimeException(
								'VCard object with uid already exists in this addressbook collection.'
							);
						}
					}

					$uri = $uid.'.vcf';
					$this->cards[$uri] = ['UID' => $uid];
				} else {
					$uri = $properties['URI'];
					unset($properties['URI']);
				}

				$this->cards[$uri] = array_merge($this->cards[$uri], $properties);
				return ['URI' => $uri] + $this->cards[$uri];
			}//end createOrUpdate()

			/**
			 * Permissions.
			 *
			 * @return int
			 */
			public function getPermissions() {
				return $this->system === true ? Constants::PERMISSION_READ : Constants::PERMISSION_ALL;
			}//end getPermissions()

			/**
			 * Delete.
			 *
			 * @param int $id The id.
			 *
			 * @return bool
			 */
			public function delete($id) {
				return false;
			}//end delete()

			/**
			 * Shared.
			 *
			 * @return bool
			 */
			public function isShared(): bool {
				return false;
			}//end isShared()

			/**
			 * System addressbook.
			 *
			 * @return bool
			 */
			public function isSystemAddressBook(): bool {
				return $this->system;
			}//end isSystemAddressBook()
		};
	}//end addressBook()

	/**
	 * Build the service over a list of addressbooks.
	 *
	 * @param IAddressBook[] $books The addressbooks.
	 *
	 * @return ContactVcardWriterService
	 */
	private function service(array $books): ContactVcardWriterService {
		$contacts = $this->createMock(IContactsManager::class);
		$contacts->method('getUserAddressBooks')->willReturn($books);

		$resolver = $this->createMock(RegisterResolverService::class);
		$resolver->method('resolve')->willReturn('reg-1');

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('sch-1');

		return new ContactVcardWriterService(
			$contacts,
			$appConfig,
			$this->createMock(LoggerInterface::class),
			$resolver,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);
	}//end service()

	/**
	 * An edit of a client with an existing contact updates that card.
	 *
	 * @return void
	 */
	public function testEditUpdatesTheExistingCard(): void {
		$book = $this->addressBook(
			['uid-1.vcf' => ['UID' => 'uid-1', 'FN' => 'Old name', 'TEL' => '010-1111111']]
		);

		$uid = $this->service([$book])->writeToAddressBook(
			['FN' => 'New name', 'TEL' => '010-2222222'],
			['id' => 'o-1', 'contactsUid' => 'uid-1'],
			'client'
		);

		$this->assertSame('uid-1', $uid);
		$this->assertCount(1, $book->cards);
		$this->assertSame('New name', $book->cards['uid-1.vcf']['FN']);
		$this->assertSame('010-2222222', $book->cards['uid-1.vcf']['TEL']);
	}//end testEditUpdatesTheExistingCard()

	/**
	 * The card is updated in the addressbook that holds it, not the first one.
	 *
	 * @return void
	 */
	public function testEditFindsTheCardInASecondAddressBook(): void {
		$system = $this->addressBook([], true);
		$first = $this->addressBook([]);
		$second = $this->addressBook(['abc.vcf' => ['UID' => 'uid-2', 'FN' => 'Old']]);

		$uid = $this->service([$system, $first, $second])->writeToAddressBook(
			['FN' => 'New'],
			['id' => 'o-1', 'contactsUid' => 'uid-2'],
			'client'
		);

		$this->assertSame('uid-2', $uid);
		$this->assertSame([], $first->cards, 'no duplicate card in the first addressbook');
		$this->assertSame('New', $second->cards['abc.vcf']['FN']);
	}//end testEditFindsTheCardInASecondAddressBook()

	/**
	 * The contact-first create path updates an existing card the same way.
	 *
	 * @return void
	 */
	public function testWriteVcardUpdatesAnExistingCard(): void {
		$book = $this->addressBook(['uid-3.vcf' => ['UID' => 'uid-3', 'FN' => 'Old']]);

		$uid = $this->service([$book])->writeVcard(['FN' => 'New'], 'uid-3');

		$this->assertSame('uid-3', $uid);
		$this->assertSame('New', $book->cards['uid-3.vcf']['FN']);
	}//end testWriteVcardUpdatesAnExistingCard()

	/**
	 * A linked contact whose card is gone gets a new card under its UID, never
	 * in the read-only system addressbook.
	 *
	 * @return void
	 */
	public function testMissingCardIsRecreatedInAWritableAddressBook(): void {
		$system = $this->addressBook([], true);
		$book = $this->addressBook([]);

		$uid = $this->service([$system, $book])->writeToAddressBook(
			['FN' => 'Back again'],
			['id' => 'o-1', 'contactsUid' => 'uid-4'],
			'client'
		);

		$this->assertSame('uid-4', $uid);
		$this->assertSame([], $system->cards);
		$this->assertSame('Back again', $book->cards['uid-4.vcf']['FN']);
	}//end testMissingCardIsRecreatedInAWritableAddressBook()
}//end class
