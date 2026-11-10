<?php

/**
 * MailingListService: every way a list update ends.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\MailingListService;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use PHPUnit\Framework\TestCase;

/**
 * The patch merge, its validation and the save.
 */
class MailingListServiceUpdateTest extends TestCase {

	/**
	 * A valid stored list.
	 *
	 * @return array<string, mixed>
	 */
	private function stored(): array {
		return [
			'uuid' => 'l-1',
			'name' => 'News',
			'description' => 'd',
			'senderName' => 'Shop',
			'senderEmail' => 'shop@example.test',
			'replyTo' => '',
			'footerAddress' => 'Street 1',
			'publicSignup' => false,
			'optInMode' => 'double',
			'status' => 'active',
			'extra' => 'kept',
		];
	}//end stored()

	/**
	 * Every way an update ends: an unknown list is not found and nothing is
	 * written; only the six text fields are taken, trimmed, and only when
	 * set; the signup flag is cast; the opt-in mode and status are
	 * normalised; an invalid result is refused before any write; the saved
	 * list is stamped and returned; a failed save is reported.
	 *
	 * @return void
	 */
	public function testEveryWayAnUpdateEnds(): void {
		$store = new InMemoryListObjectStore(seed: ['mailingList' => [$this->stored()]]);
		$service = new MailingListService(store: $store);

		$this->assertSame(['error' => 'Not found'], $service->updateMailingList(listId: 'nope', patch: ['name' => 'X']));
		$this->assertSame([], $store->writes);

		$this->assertSame(['error' => 'A mailing list needs a valid sender address'], $service->updateMailingList(listId: 'l-1', patch: ['senderEmail' => ' not-mail ']));
		$this->assertSame([], $store->writes);

		$before = gmdate('Y-m-d\TH:i:s\Z');
		$result = $service->updateMailingList(
			listId: 'l-1',
			patch: ['name' => ' Nieuws ', 'replyTo' => ' r@example.test ', 'publicSignup' => '1', 'optInMode' => ' SOFT ', 'status' => 'gone', 'extra' => 'patched', 'description' => null, 'uuid' => 'x']
		);
		$after = gmdate('Y-m-d\TH:i:s\Z');
		$list = $result['list'];
		$stamp = $list['updatedAt'];
		unset($list['updatedAt']);
		$this->assertSame(
			['uuid' => 'l-1', 'name' => 'Nieuws', 'description' => 'd', 'senderName' => 'Shop', 'senderEmail' => 'shop@example.test', 'replyTo' => 'r@example.test', 'footerAddress' => 'Street 1', 'publicSignup' => true, 'optInMode' => 'soft', 'status' => 'active', 'extra' => 'kept'],
			$list
		);
		$this->assertTrue($stamp >= $before && $stamp <= $after);
		$this->assertSame(['mailingList' => 1], $store->writes);

		$result = $service->updateMailingList(listId: 'l-1', patch: ['publicSignup' => 0, 'optInMode' => 'bogus', 'status' => ' Paused ']);
		$this->assertFalse($result['list']['publicSignup']);
		$this->assertSame('double', $result['list']['optInMode']);
		$this->assertSame('paused', $result['list']['status']);

		$failing = new class (['mailingList' => [$this->stored()]]) extends InMemoryListObjectStore {
			/**
			 * Refuse the write.
			 *
			 * @param string               $schemaSlug The schema.
			 * @param array<string, mixed> $payload    The row.
			 * @param string|null          $id         The id.
			 *
			 * @return array<string, mixed>|null
			 */
			public function save(string $schemaSlug, array $payload, ?string $id = null): ?array {
				return null;
			}//end save()
		};
		$this->assertSame(['error' => 'The mailing list could not be saved'], (new MailingListService(store: $failing))->updateMailingList(listId: 'l-1', patch: []));
	}//end testEveryWayAnUpdateEnds()
}//end class
