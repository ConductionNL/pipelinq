<?php

/**
 * Unit tests for how MergedAwayCard finds and reads a card.
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

/**
 * The exact UID lookup, the first book holding the card winning, hits that are
 * not cards, notes answered as lists or non-text, and a card without a note.
 *
 * @covers \OCA\Pipelinq\Service\Merge\MergedAwayCard
 */
class MergedAwayCardLookupTest extends TestCase {

	/**
	 * Every write, as [book name, properties].
	 *
	 * @var list<array{0: string, 1: array<string, string>}>
	 */
	private array $writes = [];

	/**
	 * Every search, as [book name, pattern, properties, options].
	 *
	 * @var list<array{0: string, 1: string, 2: array<int, string>, 3: array<string, mixed>}>
	 */
	private array $searches = [];

	/**
	 * A recording address book.
	 *
	 * @param string            $name  Its name in the records.
	 * @param list<mixed>       $hits  What a search answers.
	 *
	 * @return IAddressBook&MockObject
	 */
	private function book(string $name, array $hits): IAddressBook&MockObject {
		$book = $this->createMock(originalClassName: IAddressBook::class);
		$book->method('search')->willReturnCallback(
			function (string $pattern, array $properties, array $options) use ($name, $hits): array {
				$this->searches[] = [$name, $pattern, $properties, $options];
				return $hits;
			}
		);
		$book->method('getPermissions')->willReturn(Constants::PERMISSION_ALL);
		$book->method('isSystemAddressBook')->willReturn(false);
		$book->method('createOrUpdate')->willReturnCallback(
			function (array $properties) use ($name): array {
				$this->writes[] = [$name, $properties];
				return $properties;
			}
		);
		return $book;
	}//end book()

	/**
	 * The service over the given books.
	 *
	 * @param list<IAddressBook> $books The user's address books.
	 *
	 * @return MergedAwayCard
	 */
	private function service(array $books): MergedAwayCard {
		$contacts = $this->createMock(originalClassName: IContactsManager::class);
		$contacts->method('isEnabled')->willReturn(true);
		$contacts->method('getUserAddressBooks')->willReturn($books);
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters=[]): string => vsprintf($text, $parameters)
		);
		return new MergedAwayCard(contactsManager: $contacts, l10n: $l10n, logger: $this->createMock(originalClassName: LoggerInterface::class));
	}//end service()

	/**
	 * The card is looked up by exact UID with a limit of five; hits that are not
	 * arrays are passed over; the first book holding it is written and later books
	 * are not searched.
	 *
	 * @return void
	 */
	public function testTheFirstBookHoldingTheCardIsWritten(): void {
		$service = $this->service(
			books: [
				$this->book(name: 'a', hits: ['card-1', null]),
				$this->book(name: 'b', hits: [['UID' => 'card-1', 'URI' => 'b.vcf']]),
				$this->book(name: 'c', hits: [['UID' => 'card-1', 'URI' => 'c.vcf']]),
			]
		);

		$this->assertTrue(condition: $service->mark(contactsUid: 'card-1', intoUuid: 'client-s', intoName: 'Nieuw'));

		$this->assertSame(
			expected: [
				['a', 'card-1', ['UID'], ['limit' => 5, 'wildcard' => false]],
				['b', 'card-1', ['UID'], ['limit' => 5, 'wildcard' => false]],
			],
			actual: $this->searches
		);
		$this->assertSame(
			expected: [['b', ['URI' => 'b.vcf', 'UID' => 'card-1', 'NOTE' => '[merged-into:client-s] Merged into Nieuw']]],
			actual: $this->writes
		);
	}//end testTheFirstBookHoldingTheCardIsWritten()

	/**
	 * A note answered as a list is read by its first value; a note that is not text reads as empty.
	 *
	 * @return void
	 */
	public function testANoteIsReadByItsFirstTextValue(): void {
		$this->service(
			books: [$this->book(name: 'a', hits: [['UID' => 'card-1', 'URI' => 'a.vcf', 'NOTE' => ["Eerste\n[merged-into:x] Merged into X", 'Tweede']]])]
		)->unmark(contactsUid: 'card-1');
		$this->service(
			books: [$this->book(name: 'b', hits: [['UID' => ['card-2', 'other'], 'URI' => 'b.vcf', 'NOTE' => [['nested']]]])]
		)->mark(contactsUid: 'card-2', intoUuid: 'client-s', intoName: 'Y');

		$this->assertSame(
			expected: [
				['a', ['URI' => 'a.vcf', 'UID' => 'card-1', 'NOTE' => 'Eerste']],
				['b', ['URI' => 'b.vcf', 'UID' => 'card-2', 'NOTE' => '[merged-into:client-s] Merged into Y']],
			],
			actual: $this->writes
		);
	}//end testANoteIsReadByItsFirstTextValue()

	/**
	 * A hit whose UID only partly matches is not the card, so nothing is written.
	 *
	 * @return void
	 */
	public function testAPartialUidMatchIsNotTheCard(): void {
		$hits    = [['UID' => 'card-10', 'URI' => 'a.vcf'], ['UID' => ['x', 'card-1'], 'URI' => 'x.vcf']];
		$service = $this->service(books: [$this->book(name: 'a', hits: $hits)]);

		$this->assertFalse(condition: $service->unmark(contactsUid: 'card-1'));
		$this->assertSame(expected: [], actual: $this->writes);
	}//end testAPartialUidMatchIsNotTheCard()
}//end class
