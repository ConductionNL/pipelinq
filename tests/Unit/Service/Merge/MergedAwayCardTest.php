<?php

/**
 * Tests for MergedAwayCard: the merged-away record's address book card is kept,
 * notes where the record went, and loses that note again on a reversal.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Merge
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

namespace OCA\Pipelinq\Tests\Unit\Service\Merge;

use OCA\Pipelinq\Service\Merge\MergedAwayCard;
use OCP\Constants;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IAddressBook;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Pipelinq\Service\Merge\MergedAwayCard
 */
class MergedAwayCardTest extends TestCase {

	/**
	 * Every write, as the properties passed to createOrUpdate().
	 *
	 * @var list<array<string, string>>
	 */
	private array $writes = [];

	private IContactsManager&MockObject $contacts;

	private LoggerInterface&MockObject $logger;

	/**
	 * A contacts manager with no books yet, a translator and a logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->contacts = $this->createMock(IContactsManager::class);
		$this->contacts->method('isEnabled')->willReturn(true);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return MergedAwayCard
	 */
	private function service(): MergedAwayCard {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);
		return new MergedAwayCard(contactsManager: $this->contacts, l10n: $l10n, logger: $this->logger);
	}//end service()

	/**
	 * An address book answering the given cards to a UID search, recording writes.
	 *
	 * @param list<array<string, mixed>> $cards       The cards it holds.
	 * @param int                        $permissions Its permissions.
	 * @param bool                       $system      Whether it is the system address book.
	 *
	 * @return IAddressBook&MockObject
	 */
	private function book(array $cards, int $permissions = Constants::PERMISSION_ALL, bool $system = false): IAddressBook&MockObject {
		$book = $this->createMock(IAddressBook::class);
		$book->method('search')->willReturnCallback(
			static function (string $pattern, array $searchProperties, array $options) use ($cards): array {
				self::assertSame(['UID'], $searchProperties);
				self::assertFalse($options['wildcard']);
				return $cards;
			}
		);
		$book->method('getPermissions')->willReturn($permissions);
		$book->method('isSystemAddressBook')->willReturn($system);
		$book->method('createOrUpdate')->willReturnCallback(
			function (array $properties): array {
				$this->writes[] = $properties;
				return $properties;
			}
		);
		return $book;
	}//end book()

	/**
	 * A merge appends one line naming the record that stays and keeps the existing note.
	 *
	 * @return void
	 */
	public function testAMergeNotesWhereTheRecordWent(): void {
		$this->contacts->method('getUserAddressBooks')->willReturn([
			$this->book(cards: [['UID' => 'card-1x', 'URI' => 'other.vcf']]),
			$this->book(cards: [['UID' => ['card-1'], 'URI' => 'card-1.vcf', 'NOTE' => 'Klant sinds 2019']]),
		]);

		self::assertTrue($this->service()->mark(contactsUid: 'card-1', intoUuid: 'client-s', intoName: 'Bakker Installatietechniek'));

		self::assertSame(
			[['URI' => 'card-1.vcf', 'UID' => 'card-1', 'NOTE' => "Klant sinds 2019\n[merged-into:client-s] Merged into Bakker Installatietechniek"]],
			$this->writes
		);
	}//end testAMergeNotesWhereTheRecordWent()

	/**
	 * A second merge of the same card replaces the earlier note instead of adding another.
	 *
	 * @return void
	 */
	public function testASecondMergeReplacesTheNote(): void {
		$this->contacts->method('getUserAddressBooks')->willReturn([
			$this->book(cards: [['UID' => 'card-1', 'URI' => 'card-1.vcf', 'NOTE' => "[merged-into:client-old] Merged into Oud\nKlant"]]),
		]);

		$this->service()->mark(contactsUid: 'card-1', intoUuid: 'client-s', intoName: 'Nieuw');

		self::assertSame("Klant\n[merged-into:client-s] Merged into Nieuw", $this->writes[0]['NOTE']);
	}//end testASecondMergeReplacesTheNote()

	/**
	 * A reversal takes the merge line off and leaves the rest of the note.
	 *
	 * @return void
	 */
	public function testAReversalTakesTheNoteOff(): void {
		$this->contacts->method('getUserAddressBooks')->willReturn([
			$this->book(cards: [['UID' => 'card-1', 'URI' => 'card-1.vcf', 'NOTE' => "Klant sinds 2019\n  [merged-into:client-s] Merged into X"]]),
		]);

		self::assertTrue($this->service()->unmark(contactsUid: 'card-1'));

		self::assertSame([['URI' => 'card-1.vcf', 'UID' => 'card-1', 'NOTE' => 'Klant sinds 2019']], $this->writes);
	}//end testAReversalTakesTheNoteOff()

	/**
	 * A card without a merge note is found and left unwritten.
	 *
	 * @return void
	 */
	public function testAnUnmarkedCardIsNotWrittenAgain(): void {
		$this->contacts->method('getUserAddressBooks')->willReturn([
			$this->book(cards: [['UID' => 'card-1', 'URI' => 'card-1.vcf', 'NOTE' => 'Klant']]),
		]);

		self::assertTrue($this->service()->unmark(contactsUid: 'card-1'));
		self::assertSame([], $this->writes);
	}//end testAnUnmarkedCardIsNotWrittenAgain()

	/**
	 * No UID, Contacts off, or a card that is nowhere: nothing is looked up or written.
	 *
	 * @return void
	 */
	public function testAMissingCardIsReportedNotWritten(): void {
		$this->contacts->method('getUserAddressBooks')->willReturn([
			$this->book(cards: [['UID' => 'card-1', 'URI' => '']]),
		]);

		self::assertFalse($this->service()->mark(contactsUid: ' ', intoUuid: 'client-s', intoName: 'X'));
		self::assertFalse($this->service()->unmark(contactsUid: 'card-1'));
		self::assertSame([], $this->writes);

		$off = $this->createMock(IContactsManager::class);
		$off->method('isEnabled')->willReturn(false);
		$off->expects(self::never())->method('getUserAddressBooks');
		$l10n = $this->createMock(IL10N::class);
		self::assertFalse((new MergedAwayCard(contactsManager: $off, l10n: $l10n, logger: $this->logger))->unmark(contactsUid: 'card-1'));
	}//end testAMissingCardIsReportedNotWritten()

	/**
	 * A card in a read-only or system address book is not written, and that is logged.
	 *
	 * @return void
	 */
	public function testAReadOnlyBookIsNotWritten(): void {
		$card = ['UID' => 'card-1', 'URI' => 'card-1.vcf'];
		$this->contacts->method('getUserAddressBooks')->willReturnOnConsecutiveCalls(
			[$this->book(cards: [$card], permissions: Constants::PERMISSION_READ)],
			[$this->book(cards: [$card], system: true)]
		);
		$this->logger->expects(self::exactly(2))->method('warning')
			->with('Pipelinq merge: the card of a merged record is in a read-only address book', ['uid' => 'card-1']);

		self::assertFalse($this->service()->mark(contactsUid: 'card-1', intoUuid: 'client-s', intoName: 'X'));
		self::assertFalse($this->service()->mark(contactsUid: 'card-1', intoUuid: 'client-s', intoName: 'X'));
		self::assertSame([], $this->writes);
	}//end testAReadOnlyBookIsNotWritten()

	/**
	 * A failing search or write is logged and reported, never thrown.
	 *
	 * @return void
	 */
	public function testFailuresAreLoggedNotThrown(): void {
		$searchFails = $this->createMock(IAddressBook::class);
		$searchFails->method('search')->willThrowException(new RuntimeException('dav down'));
		$writeFails = $this->createMock(IAddressBook::class);
		$writeFails->method('search')->willReturn([['UID' => 'card-1', 'URI' => 'card-1.vcf']]);
		$writeFails->method('getPermissions')->willReturn(Constants::PERMISSION_ALL);
		$writeFails->method('createOrUpdate')->willThrowException(new RuntimeException('locked'));
		$this->contacts->method('getUserAddressBooks')->willReturn([$searchFails, $writeFails]);
		$messages = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message, array $context) use (&$messages): void {
				$messages[] = [$message, $context['error']];
			}
		);

		self::assertFalse($this->service()->mark(contactsUid: 'card-1', intoUuid: 'client-s', intoName: 'X'));
		self::assertSame(
			[
				['Pipelinq merge: looking up the card of a merged record failed', 'dav down'],
				['Pipelinq merge: the card of a merged record could not be written', 'locked'],
			],
			$messages
		);
	}//end testFailuresAreLoggedNotThrown()
}//end class
