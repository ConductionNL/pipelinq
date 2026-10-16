<?php

/**
 * Pipelinq ContactSyncPreferences.
 *
 * A user's own choices for the sync with Nextcloud Contacts: the address book
 * new cards go to, and whether changes in pipelinq are written back to the
 * linked card.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/contacts-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\Constants;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IAddressBook;
use OCP\IConfig;

/**
 * Reads and writes a user's contacts sync preferences (user values, so every
 * colleague keeps their own).
 *
 * @spec openspec/specs/contacts-sync/spec.md
 */
class ContactSyncPreferences {

	/**
	 * The user value naming the address book new cards go to ('' = the first writable one).
	 */
	public const KEY_ADDRESS_BOOK = 'contacts_address_book';

	/**
	 * The user value that switches write-back to the linked card on ('1') or off ('0').
	 */
	public const KEY_WRITE_BACK = 'contacts_write_back';

	/**
	 * Constructor.
	 *
	 * @param IConfig          $config          The config, for user values.
	 * @param IContactsManager $contactsManager The contacts manager.
	 */
	public function __construct(
		private IConfig $config,
		private IContactsManager $contactsManager,
	) {
	}//end __construct()

	/**
	 * The user's preferences, with the address books they can pick from.
	 *
	 * @param string $uid The user id.
	 *
	 * @return array{addressBook: string, writeBack: bool, addressBooks: array<int, array{key: string, name: string}>}
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 */
	public function read(string $uid): array {
		return [
			'addressBook'  => $this->addressBookKey(uid: $uid),
			'writeBack'    => $this->writesBack(uid: $uid),
			'addressBooks' => $this->writableAddressBooks(),
		];
	}//end read()

	/**
	 * Store the user's preferences. An address book that is not one of the
	 * user's writable books is stored as '' (the first writable one).
	 *
	 * @param string $uid         The user id.
	 * @param string $addressBook The chosen address book key.
	 * @param bool   $writeBack   Whether changes are written back to the card.
	 *
	 * @return array{addressBook: string, writeBack: bool, addressBooks: array<int, array{key: string, name: string}>}
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 */
	public function save(string $uid, string $addressBook, bool $writeBack): array {
		$known = array_column($this->writableAddressBooks(), 'key');
		if (in_array($addressBook, $known, true) === false) {
			$addressBook = '';
		}

		$this->config->setUserValue($uid, Application::APP_ID, self::KEY_ADDRESS_BOOK, $addressBook);
		$this->config->setUserValue($uid, Application::APP_ID, self::KEY_WRITE_BACK, $writeBack === true ? '1' : '0');

		return $this->read(uid: $uid);
	}//end save()

	/**
	 * The address book key the user chose, or '' for the first writable one.
	 *
	 * @param string $uid The user id.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 */
	public function addressBookKey(string $uid): string {
		return $this->config->getUserValue($uid, Application::APP_ID, self::KEY_ADDRESS_BOOK, '');
	}//end addressBookKey()

	/**
	 * Whether changes are written back to the linked card. On unless the user switched it off.
	 *
	 * @param string $uid The user id.
	 *
	 * @return bool True when write-back is on.
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 */
	public function writesBack(string $uid): bool {
		return $this->config->getUserValue($uid, Application::APP_ID, self::KEY_WRITE_BACK, '1') !== '0';
	}//end writesBack()

	/**
	 * The current user's address books pipelinq may write cards in.
	 *
	 * @return array<int, array{key: string, name: string}> The books.
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 */
	private function writableAddressBooks(): array {
		$books = [];
		foreach ($this->contactsManager->getUserAddressBooks() as $book) {
			if ($this->isWritable(addressBook: $book) === false) {
				continue;
			}

			$books[] = [
				'key'  => (string) $book->getKey(),
				'name' => $book->getDisplayName(),
			];
		}

		return $books;
	}//end writableAddressBooks()

	/**
	 * Whether pipelinq may write cards in this address book.
	 *
	 * @param IAddressBook $addressBook The address book.
	 *
	 * @return bool True for a writable, non-system address book.
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 */
	public function isWritable(IAddressBook $addressBook): bool {
		if ($addressBook->isSystemAddressBook() === true) {
			return false;
		}

		return ($addressBook->getPermissions() & Constants::PERMISSION_UPDATE) !== 0;
	}//end isWritable()
}//end class
