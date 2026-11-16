<?php

/**
 * Unit tests for listing, creating and exposing mailing lists.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\MailingListService;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use PHPUnit\Framework\TestCase;

/**
 * The paged list, the create path, the public-signup gate and the public projection.
 *
 * @covers \OCA\Pipelinq\Service\MailingListService
 * @uses   \OCA\Pipelinq\Service\Support\PageCount
 * @uses   \OCA\Pipelinq\Service\Marketing\ListObjectStore
 * @uses   \OCA\Pipelinq\Service\Marketing\ListPage
 */
class MailingListServiceCreateTest extends TestCase {

	/**
	 * A valid create payload.
	 *
	 * @return array<string, mixed>
	 */
	private function payload(): array {
		return [
			'name' => ' News ',
			'senderName' => ' Shop ',
			'senderEmail' => ' shop@example.test ',
			'footerAddress' => ' Street 1 ',
		];
	}//end payload()

	/**
	 * Pages are clamped and sliced, with the page count rounded up.
	 *
	 * @return void
	 */
	public function testListPagesAndClamps(): void {
		$rows = [];
		for ($i = 1; $i <= 5; $i++) {
			$rows[] = ['uuid' => 'l-'.$i, 'name' => 'L'.$i];
		}

		$service = new MailingListService(store: new InMemoryListObjectStore(seed: ['mailingList' => $rows]));

		$second = $service->listMailingLists(page: 2, limit: 2);
		$this->assertSame(['l-3', 'l-4'], array_column($second['data'], 'uuid'));
		$this->assertSame(['page' => 2, 'limit' => 2, 'total' => 5, 'pages' => 3], $second['pagination']);

		$clamped = $service->listMailingLists(page: 0, limit: 0);
		$this->assertSame(['page' => 1, 'limit' => 1, 'total' => 5, 'pages' => 5], $clamped['pagination']);
		$this->assertSame(['l-1'], array_column($clamped['data'], 'uuid'));

		$this->assertSame(100, $service->listMailingLists(page: 1, limit: 500)['pagination']['limit']);
	}//end testListPagesAndClamps()

	/**
	 * An empty store lists nothing on zero pages.
	 *
	 * @return void
	 */
	public function testAnEmptyStoreHasNoPages(): void {
		$service = new MailingListService(store: new InMemoryListObjectStore());

		$this->assertSame(
			['data' => [], 'pagination' => ['page' => 1, 'limit' => 20, 'total' => 0, 'pages' => 0]],
			$service->listMailingLists(page: 1, limit: 20)
		);
	}//end testAnEmptyStoreHasNoPages()

	/**
	 * A create trims the text, applies the defaults and stamps the caller.
	 *
	 * @return void
	 */
	public function testCreateTrimsDefaultsAndStampsTheCaller(): void {
		$store = new InMemoryListObjectStore();
		$service = new MailingListService(store: $store);

		$payload = $this->payload();
		$payload['createdBy'] = 'someone-else';
		$before = gmdate('Y-m-d\TH:i:s\Z');
		$list = $service->createMailingList(payload: $payload, createdByUid: 'alice')['list'];
		$after = gmdate('Y-m-d\TH:i:s\Z');

		$this->assertTrue($list['createdAt'] >= $before && $list['createdAt'] <= $after);
		$this->assertSame($list['createdAt'], $list['updatedAt']);
		unset($list['createdAt'], $list['updatedAt']);
		$this->assertSame(
			[
				'name' => 'News',
				'description' => '',
				'optInMode' => 'double',
				'senderName' => 'Shop',
				'senderEmail' => 'shop@example.test',
				'replyTo' => '',
				'publicSignup' => false,
				'footerAddress' => 'Street 1',
				'status' => 'active',
				'createdBy' => 'alice',
				'uuid' => 'obj-1',
			],
			$list
		);
		$this->assertSame(['mailingList' => 1], $store->writes);
	}//end testCreateTrimsDefaultsAndStampsTheCaller()

	/**
	 * Explicit settings are normalised on create.
	 *
	 * @return void
	 */
	public function testCreateNormalisesTheSettings(): void {
		$service = new MailingListService(store: new InMemoryListObjectStore());
		$payload = $this->payload() + [
			'optInMode' => ' Soft ',
			'status' => 'PAUSED',
			'publicSignup' => 'yes',
			'description' => 'About',
			'replyTo' => ' r@example.test ',
		];

		$list = $service->createMailingList(payload: $payload, createdByUid: 'alice')['list'];

		$this->assertSame('soft', $list['optInMode']);
		$this->assertSame('paused', $list['status']);
		$this->assertTrue($list['publicSignup']);
		$this->assertSame('About', $list['description']);
		$this->assertSame('r@example.test', $list['replyTo']);
	}//end testCreateNormalisesTheSettings()

	/**
	 * An invalid payload is refused before anything is written.
	 *
	 * @return void
	 */
	public function testAnInvalidCreateWritesNothing(): void {
		$store = new InMemoryListObjectStore();
		$service = new MailingListService(store: $store);
		$payload = $this->payload();
		$payload['footerAddress'] = '  ';

		$this->assertSame(
			['error' => 'A mailing list needs a postal address for the footer'],
			$service->createMailingList(payload: $payload, createdByUid: 'alice')
		);
		$this->assertSame([], $store->writes);
	}//end testAnInvalidCreateWritesNothing()

	/**
	 * A refused write is reported.
	 *
	 * @return void
	 */
	public function testAFailedCreateIsReported(): void {
		$failing = new class () extends InMemoryListObjectStore {
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

		$this->assertSame(
			['error' => 'The mailing list could not be saved'],
			(new MailingListService(store: $failing))->createMailingList(payload: $this->payload(), createdByUid: 'alice')
		);
	}//end testAFailedCreateIsReported()

	/**
	 * Only an existing, active list with public signup accepts signups.
	 *
	 * @return void
	 */
	public function testPublicSignupNeedsAnActiveOpenList(): void {
		$service = new MailingListService(store: new InMemoryListObjectStore());

		$this->assertFalse($service->acceptsPublicSignup(null));
		$this->assertFalse($service->acceptsPublicSignup(['status' => 'active']));
		$this->assertFalse($service->acceptsPublicSignup(['publicSignup' => true, 'status' => 'paused']));
		$this->assertTrue($service->acceptsPublicSignup(['publicSignup' => true, 'status' => 'active']));
		$this->assertTrue($service->acceptsPublicSignup(['publicSignup' => 1]));
	}//end testPublicSignupNeedsAnActiveOpenList()

	/**
	 * The public projection exposes only id, name and description.
	 *
	 * @return void
	 */
	public function testThePublicProjectionHidesTheSender(): void {
		$service = new MailingListService(store: new InMemoryListObjectStore());

		$this->assertSame(
			['id' => 'l-1', 'name' => 'News', 'description' => ''],
			$service->publicProjection(['uuid' => 'l-1', 'name' => 'News', 'senderEmail' => 'shop@example.test'])
		);
		$this->assertSame(['id' => 'x', 'name' => '', 'description' => ''], $service->publicProjection(['id' => 'x']));
	}//end testThePublicProjectionHidesTheSender()
}//end class
