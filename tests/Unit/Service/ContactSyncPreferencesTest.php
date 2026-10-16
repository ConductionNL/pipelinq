<?php

/**
 * Unit tests for ContactSyncPreferences and the vCard writer honouring them.
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
 * @spec openspec/specs/contacts-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\ContactSyncPreferences;
use OCA\Pipelinq\Service\ContactVcardWriterService;
use OCA\Pipelinq\Service\RegisterResolverService;
use OCP\Constants;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IAddressBook;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A user's address book choice and write-back switch.
 */
class ContactSyncPreferencesTest extends TestCase {

	/**
	 * User values in memory, keyed by name.
	 *
	 * @var array<string, string>
	 */
	private array $values = [];

	/**
	 * An address book double.
	 *
	 * @param string $key      The key.
	 * @param string $name     The display name.
	 * @param bool   $writable Whether pipelinq may write in it.
	 * @param bool   $system   Whether it is the system address book.
	 *
	 * @return IAddressBook&MockObject
	 */
	private function book(string $key, string $name, bool $writable = true, bool $system = false): IAddressBook {
		$book = $this->createMock(IAddressBook::class);
		$book->method('getKey')->willReturn($key);
		$book->method('getDisplayName')->willReturn($name);
		$book->method('isSystemAddressBook')->willReturn($system);
		$book->method('getPermissions')->willReturn($writable === true ? Constants::PERMISSION_ALL : Constants::PERMISSION_READ);
		$book->method('search')->willReturn([]);
		return $book;
	}//end book()

	/**
	 * The preferences over the given address books, with user values in memory.
	 *
	 * @param IAddressBook[] $books The address books.
	 *
	 * @return ContactSyncPreferences
	 */
	private function preferences(array $books): ContactSyncPreferences {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			fn (string $uid, string $app, string $key, $default = '') => ($this->values[$key] ?? $default)
		);
		$config->method('setUserValue')->willReturnCallback(
			function (string $uid, string $app, string $key, $value): void {
				$this->values[$key] = (string) $value;
			}
		);
		$contacts = $this->createMock(IContactsManager::class);
		$contacts->method('getUserAddressBooks')->willReturn($books);
		return new ContactSyncPreferences($config, $contacts);
	}//end preferences()

	/**
	 * A writer signed in as pieter, over the given address books.
	 *
	 * @param IAddressBook[] $books The address books.
	 *
	 * @return ContactVcardWriterService
	 */
	private function writer(array $books): ContactVcardWriterService {
		$contacts = $this->createMock(IContactsManager::class);
		$contacts->method('getUserAddressBooks')->willReturn($books);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pieter');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return new ContactVcardWriterService(
			$contacts,
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(RegisterResolverService::class),
			objectService: $this->createMock(ObjectServiceInterface::class),
			preferences: $this->preferences($books),
			userSession: $session,
		);
	}//end writer()

	/**
	 * Without a choice, write-back is on and no book is named; only writable,
	 * non-system books are offered.
	 *
	 * @return void
	 */
	public function testDefaultsAndTheBooksOnOffer(): void {
		$read = $this->preferences(
			[
				$this->book('1', 'Contacten'),
				$this->book('2', 'Gedeeld', writable: false),
				$this->book('3', 'Systeem', system: true),
				$this->book('4', 'Pipelinq CRM'),
			]
		)->read('pieter');

		$this->assertSame('', $read['addressBook']);
		$this->assertTrue($read['writeBack']);
		$this->assertSame(
			[['key' => '1', 'name' => 'Contacten'], ['key' => '4', 'name' => 'Pipelinq CRM']],
			$read['addressBooks']
		);
	}//end testDefaultsAndTheBooksOnOffer()

	/**
	 * A saved choice reads back; a book the user cannot write in is stored as no choice.
	 *
	 * @return void
	 */
	public function testSaveKeepsOnlyAWritableBook(): void {
		$preferences = $this->preferences([$this->book('1', 'Contacten'), $this->book('2', 'Gedeeld', writable: false)]);

		$saved = $preferences->save('pieter', '1', false);
		$this->assertSame('1', $saved['addressBook']);
		$this->assertFalse($saved['writeBack']);

		$this->assertSame('', $preferences->save('pieter', '2', true)['addressBook']);
	}//end testSaveKeepsOnlyAWritableBook()

	/**
	 * A new card goes to the book the user chose, not the first writable one.
	 *
	 * @return void
	 */
	public function testANewCardGoesToTheChosenBook(): void {
		$first = $this->book('1', 'Contacten');
		$chosen = $this->book('4', 'Pipelinq CRM');
		$this->values[ContactSyncPreferences::KEY_ADDRESS_BOOK] = '4';

		$first->expects($this->never())->method('createOrUpdate');
		$chosen->expects($this->once())->method('createOrUpdate')->willReturn(['UID' => 'new-uid']);

		$this->writer([$first, $chosen])->writeVcard(['FN' => 'Anna de Vries']);
	}//end testANewCardGoesToTheChosenBook()

	/**
	 * With write-back off, a linked card is left as it is.
	 *
	 * @return void
	 */
	public function testWriteBackOffLeavesTheLinkedCard(): void {
		$book = $this->book('1', 'Contacten');
		$this->values[ContactSyncPreferences::KEY_WRITE_BACK] = '0';

		$book->expects($this->never())->method('createOrUpdate');

		$uid = $this->writer([$book])->writeToAddressBook(
			['FN' => 'Anna de Vries'],
			['id' => 'client-1', 'contactsUid' => 'uid-1'],
			'client'
		);

		$this->assertSame('uid-1', $uid);
	}//end testWriteBackOffLeavesTheLinkedCard()
}//end class
