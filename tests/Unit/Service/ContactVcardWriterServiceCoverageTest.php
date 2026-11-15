<?php

/**
 * Coverage tests for ContactVcardWriterService's guard, error and fallback paths.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\ContactVcardWriterService;
use OCA\Pipelinq\Service\RegisterResolverService;
use OCP\Constants;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IAddressBook;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Error paths and fallbacks of the vCard writer.
 *
 * @covers \OCA\Pipelinq\Service\ContactVcardWriterService
 */
class ContactVcardWriterServiceCoverageTest extends TestCase {
	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
	}//end setUp()

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
			$this->logger,
			$resolver,
			objectService: $this->objectService,
		);
	}//end service()

	/**
	 * An addressbook mock.
	 *
	 * @param bool  $writable Whether it grants update permission.
	 * @param bool  $system   Whether it is the system addressbook.
	 * @param array $hits     What a UID search returns.
	 *
	 * @return IAddressBook&MockObject
	 */
	private function book(bool $writable = true, bool $system = false, array $hits = []): IAddressBook&MockObject {
		$book = $this->createMock(IAddressBook::class);
		$book->method('isSystemAddressBook')->willReturn($system);
		$book->method('getPermissions')->willReturn(
			$writable === true ? Constants::PERMISSION_ALL : Constants::PERMISSION_READ
		);
		$book->method('search')->willReturn($hits);
		return $book;
	}//end book()

	/**
	 * No addressbooks: writeToAddressBook returns null and never writes.
	 *
	 * @return void
	 */
	public function testWriteToAddressBookWithoutAddressBooksReturnsNull(): void {
		$this->logger->expects($this->once())->method('debug')
			->with('Pipelinq: No addressbooks available for sync');
		$this->objectService->expects($this->never())->method('saveObject');

		$this->assertNull($this->service([])->writeToAddressBook(['FN' => 'A'], [], 'client'));
	}//end testWriteToAddressBookWithoutAddressBooksReturnsNull()

	/**
	 * No addressbooks: writeVcard returns null.
	 *
	 * @return void
	 */
	public function testWriteVcardWithoutAddressBooksReturnsNull(): void {
		$this->logger->expects($this->once())->method('debug')
			->with('Pipelinq: No addressbooks available for contact provisioning');

		$this->assertNull($this->service([])->writeVcard(['FN' => 'A']));
	}//end testWriteVcardWithoutAddressBooksReturnsNull()

	/**
	 * Only read-only / system addressbooks: nothing is written, a warning is logged.
	 *
	 * @return void
	 */
	public function testNoWritableAddressBookReturnsNull(): void {
		$system = $this->book(true, true);
		$readOnly = $this->book(false);
		$system->expects($this->never())->method('createOrUpdate');
		$readOnly->expects($this->never())->method('createOrUpdate');
		$this->logger->expects($this->once())->method('warning')
			->with('Pipelinq: no writable addressbook for contact sync');

		$this->assertNull(
			$this->service([$system, $readOnly])->writeToAddressBook(['FN' => 'A'], ['id' => 'o'], 'client')
		);
	}//end testNoWritableAddressBookReturnsNull()

	/**
	 * writeVcard with no writable addressbook returns null.
	 *
	 * @return void
	 */
	public function testWriteVcardNoWritableAddressBookReturnsNull(): void {
		$readOnly = $this->book(false);
		$readOnly->expects($this->never())->method('createOrUpdate');

		$this->assertNull($this->service([$readOnly])->writeVcard(['FN' => 'A']));
	}//end testWriteVcardNoWritableAddressBookReturnsNull()

	/**
	 * A linked card in a read-only addressbook is never written, nor duplicated elsewhere.
	 *
	 * @return void
	 */
	public function testLinkedCardInReadOnlyAddressBookSkipsWriteBack(): void {
		$readOnly = $this->book(false, false, [['UID' => ['uid-1'], 'URI' => 'uid-1.vcf']]);
		$writable = $this->book(true);
		$readOnly->expects($this->never())->method('createOrUpdate');
		$writable->expects($this->never())->method('createOrUpdate');
		$this->logger->expects($this->once())->method('warning')->with(
			'Pipelinq: linked contact lives in a read-only addressbook, write-back skipped',
			['uid' => 'uid-1']
		);

		$this->assertNull(
			$this->service([$readOnly, $writable])->writeToAddressBook(
				['FN' => 'A'],
				['id' => 'o', 'contactsUid' => 'uid-1'],
				'client'
			)
		);
	}//end testLinkedCardInReadOnlyAddressBookSkipsWriteBack()

	/**
	 * A UID returned as an array in the search hit still resolves the URI.
	 *
	 * @return void
	 */
	public function testArrayUidHitUpdatesByUri(): void {
		$book = $this->book(true, false, [
			['UID' => 'other', 'URI' => 'x.vcf'],
			['UID' => ['uid-9'], 'URI' => ''],
			['UID' => ['uid-9'], 'URI' => 'card-9.vcf'],
		]);
		$book->expects($this->once())->method('createOrUpdate')
			->with(['FN' => 'A', 'UID' => 'uid-9', 'URI' => 'card-9.vcf'])
			->willReturn(['UID' => 'uid-9']);

		$this->assertSame('uid-9', $this->service([$book])->writeVcard(['FN' => 'A'], 'uid-9'));
	}//end testArrayUidHitUpdatesByUri()

	/**
	 * A failing UID lookup is logged and treated as "not found": a new card is made.
	 *
	 * @return void
	 */
	public function testSearchFailureFallsBackToCreate(): void {
		$book = $this->createMock(IAddressBook::class);
		$book->method('isSystemAddressBook')->willReturn(false);
		$book->method('getPermissions')->willReturn(Constants::PERMISSION_ALL);
		$book->method('search')->willThrowException(new RuntimeException('dav down'));
		$book->expects($this->once())->method('createOrUpdate')
			->with(['FN' => 'A', 'UID' => 'uid-5'])
			->willReturn(null);
		$this->logger->expects($this->once())->method('warning')->with(
			'Pipelinq: contact lookup by UID failed',
			['uid' => 'uid-5', 'exception' => 'dav down']
		);

		// A null result falls back to the existing UID.
		$this->assertSame('uid-5', $this->service([$book])->writeVcard(['FN' => 'A'], 'uid-5'));
	}//end testSearchFailureFallsBackToCreate()

	/**
	 * A createOrUpdate failure in writeToAddressBook is logged and returns null.
	 *
	 * @return void
	 */
	public function testWriteToAddressBookCreateFailureReturnsNull(): void {
		$book = $this->book();
		$book->method('createOrUpdate')->willThrowException(new RuntimeException('boom'));
		$this->logger->expects($this->once())->method('error')->with(
			'Pipelinq: Failed to sync contact to addressbook',
			['exception' => 'boom']
		);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->assertNull($this->service([$book])->writeToAddressBook(['FN' => 'A'], ['id' => 'o'], 'client'));
	}//end testWriteToAddressBookCreateFailureReturnsNull()

	/**
	 * A createOrUpdate failure in writeVcard is logged and returns null.
	 *
	 * @return void
	 */
	public function testWriteVcardCreateFailureReturnsNull(): void {
		$book = $this->book();
		$book->method('createOrUpdate')->willThrowException(new RuntimeException('nope'));
		$this->logger->expects($this->once())->method('error')->with(
			'Pipelinq: Failed to provision contact in addressbook',
			['exception' => 'nope']
		);

		$this->assertNull($this->service([$book])->writeVcard(['FN' => 'A']));
	}//end testWriteVcardCreateFailureReturnsNull()

	/**
	 * A string result is taken as the UID and stored back on the object.
	 *
	 * @return void
	 */
	public function testStringResultIsStoredOnObject(): void {
		$book = $this->book();
		$book->expects($this->once())->method('createOrUpdate')->with(['FN' => 'A'])->willReturn('new-uid');
		$this->objectService->expects($this->once())->method('saveObject')
			->with(['id' => 'o', 'contactsUid' => 'new-uid'], [], 'reg-1', 'sch-1', null);

		$this->assertSame(
			'new-uid',
			$this->service([$book])->writeToAddressBook(['FN' => 'A'], ['id' => 'o', 'contactsUid' => ''], 'contact')
		);
	}//end testStringResultIsStoredOnObject()

	/**
	 * A result without a UID and no existing UID yields null and stores nothing.
	 *
	 * @return void
	 */
	public function testUnusableResultWithoutExistingUidReturnsNull(): void {
		$book = $this->book();
		$book->method('createOrUpdate')->willReturn(['URI' => 'x.vcf']);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->assertNull($this->service([$book])->writeToAddressBook(['FN' => 'A'], ['id' => 'o'], 'client'));
	}//end testUnusableResultWithoutExistingUidReturnsNull()

	/**
	 * A failing write-back of the UID is logged, and the UID is still returned.
	 *
	 * @return void
	 */
	public function testStoreFailureIsLoggedAndUidStillReturned(): void {
		$book = $this->book();
		$book->method('createOrUpdate')->willReturn(['UID' => 'u-7']);
		$this->objectService->expects($this->once())->method('saveObject')
			->willThrowException(new RuntimeException('save failed'));
		$this->logger->expects($this->once())->method('warning')->with(
			'Pipelinq: Failed to store contactsUid back on object',
			['exception' => 'save failed']
		);

		$this->assertSame('u-7', $this->service([$book])->writeToAddressBook(['FN' => 'A'], ['id' => 'o'], 'client'));
	}//end testStoreFailureIsLoggedAndUidStillReturned()

	/**
	 * The new-card addressbook name skips system and read-only books.
	 *
	 * @return void
	 */
	public function testNewCardAddressBookNameIsFirstWritable(): void {
		$system = $this->book(true, true);
		$system->expects($this->never())->method('getDisplayName');
		$readOnly = $this->book(false);
		$readOnly->expects($this->never())->method('getDisplayName');
		$writable = $this->book(true);
		$writable->method('getDisplayName')->willReturn('Contacts');

		$this->assertSame('Contacts', $this->service([$system, $readOnly, $writable])->newCardAddressBookName());
	}//end testNewCardAddressBookNameIsFirstWritable()

	/**
	 * Without a writable addressbook the name is empty.
	 *
	 * @return void
	 */
	public function testNewCardAddressBookNameEmptyWithoutWritable(): void {
		$this->assertSame('', $this->service([$this->book(false)])->newCardAddressBookName());
		$this->assertSame('', $this->service([])->newCardAddressBookName());
	}//end testNewCardAddressBookNameEmptyWithoutWritable()
}//end class
