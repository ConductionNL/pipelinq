<?php

/**
 * Unit tests for LinkedEmails.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\LinkedEmails;
use OCA\Pipelinq\Service\MailMessageKey;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for LinkedEmails: finding the link id of a mail message already linked to an object.
 *
 * @covers \OCA\Pipelinq\Service\LinkedEmails
 * @uses \OCA\Pipelinq\Service\MailMessageKey
 */
class LinkedEmailsTest extends TestCase {

	/**
	 * A link service stand-in that answers getLinkedEmails() with a fixed page and records its calls.
	 *
	 * @param mixed $page The page to return, or a Throwable to throw.
	 *
	 * @return object
	 */
	private function linkService(mixed $page): object {
		return new class($page) {
			/**
			 * The recorded calls.
			 *
			 * @var array<int,array<int,mixed>>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param mixed $page The page to return.
			 */
			public function __construct(private mixed $page) {
			}//end __construct()

			/**
			 * The linked emails of an object.
			 *
			 * @param string      $objectUuid The object.
			 * @param string|null $cursor     The page cursor.
			 * @param int         $limit      The page size.
			 *
			 * @return mixed
			 */
			public function getLinkedEmails(string $objectUuid, ?string $cursor, int $limit): mixed {
				$this->calls[] = [$objectUuid, $cursor, $limit];
				if ($this->page instanceof \Throwable) {
					throw $this->page;
				}

				return $this->page;
			}//end getLinkedEmails()
		};
	}//end linkService()

	/**
	 * The id of the first row with the same account and message id is returned; the page is asked once, 100 rows.
	 *
	 * @return void
	 */
	public function testExistingLinkIdFindsTheMatchingRow(): void {
		$service = $this->linkService(
			page: ['items' => [
				'junk',
				['id' => 1, 'mailAccountId' => 7, 'mailMessageId' => 99],
				['id' => '5', 'mailAccountId' => '7', 'mailMessageId' => '42'],
				['id' => 6, 'mailAccountId' => 7, 'mailMessageId' => 42],
			]]
		);

		$id = (new LinkedEmails(linkService: $service))->existingLinkId(
			objectUuid: 'obj-1',
			message: new MailMessageKey(mailAccountId: 7, mailMessageId: 42)
		);

		self::assertSame(expected: 5, actual: $id);
		self::assertSame(expected: [['obj-1', null, 100]], actual: $service->calls);
	}//end testExistingLinkIdFindsTheMatchingRow()

	/**
	 * No matching row, a malformed page, or a failing service give null.
	 *
	 * @return void
	 */
	public function testExistingLinkIdIsNullWithoutAMatch(): void {
		$key = new MailMessageKey(mailAccountId: 1, mailMessageId: 2);

		$pages = [
			['items' => [['id' => 3, 'mailAccountId' => 1, 'mailMessageId' => 3]]],
			['items' => 'x'],
			[],
			null,
			new RuntimeException('down'),
		];
		foreach ($pages as $page) {
			$links = new LinkedEmails(linkService: $this->linkService(page: $page));
			self::assertNull(actual: $links->existingLinkId(objectUuid: 'o', message: $key));
		}
	}//end testExistingLinkIdIsNullWithoutAMatch()

	/**
	 * A service without getLinkedEmails() is treated as having no links.
	 *
	 * @return void
	 */
	public function testAServiceWithoutTheMethodHasNoLinks(): void {
		self::assertNull(
			actual: (new LinkedEmails(linkService: new \stdClass()))->existingLinkId(
				objectUuid: 'o',
				message: new MailMessageKey(mailAccountId: 1, mailMessageId: 2)
			)
		);
	}//end testAServiceWithoutTheMethodHasNoLinks()
}//end class
