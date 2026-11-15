<?php

/**
 * Characterisation tests for EntityActivityService::getActivity().
 *
 * Pins the feed an entity's activity panel reads: the entity-type guard,
 * the type filter, the contactmoment link fields, the note field
 * fallbacks, the newest-first order, the page envelope and the two
 * failure paths, so the method can be restructured without changing them.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\EntityActivityService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the entity activity feed.
 */
class EntityActivityServiceTest extends TestCase {

	/**
	 * The findAll configs the service sent.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * The find calls the service made, as [id, register, schema].
	 *
	 * @var array<int, array{0: string, 1: mixed, 2: mixed}>
	 */
	private array $finds = [];

	/**
	 * Log lines, as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logs = [];

	/**
	 * The contactmoment rows findAll answers.
	 *
	 * @var array<int, mixed>
	 */
	private array $moments = [];

	/**
	 * The notes on the entity find answers, or null for no entity.
	 *
	 * @var array<int, mixed>|null
	 */
	private ?array $notes = null;

	/**
	 * Whether findAll throws.
	 *
	 * @var boolean
	 */
	private bool $findAllFails = false;

	/**
	 * Whether find throws.
	 *
	 * @var boolean
	 */
	private bool $findFails = false;

	/**
	 * An unknown entity type is refused before anything is read.
	 *
	 * @return void
	 */
	public function testAnUnknownEntityTypeIsRefused(): void {
		$service = $this->service(register: 'reg-1');

		try {
			$service->getActivity('invoice', 'e-1', 'all', 1, 20);
			$this->fail('an unknown entity type must be refused');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('Invalid entity type', $e->getMessage());
		}

		$this->assertSame([], $this->queries);
		$this->assertSame([], $this->finds);
	}//end testAnUnknownEntityTypeIsRefused()

	/**
	 * Without a register the feed is empty and nothing is read.
	 *
	 * @return void
	 */
	public function testWithoutARegisterTheFeedIsEmpty(): void {
		$this->moments = [['id' => 'm-1', 'occurredAt' => '2026-01-01']];

		$this->assertSame(
			['total' => 0, 'page' => 3, 'pages' => 1, 'results' => []],
			$this->service(register: '')->getActivity('client', 'e-1', 'all', 3, 20)
		);
		$this->assertSame(
			['total' => 0, 'page' => 1, 'pages' => 1, 'results' => []],
			$this->service(register: '')->getActivity('client', 'e-1', 'all', -4, 20)
		);
		$this->assertSame([], $this->queries);
	}//end testWithoutARegisterTheFeedIsEmpty()

	/**
	 * A client's feed merges its contactmomenten and notes, newest first.
	 *
	 * @return void
	 */
	public function testAClientFeedMergesMomentsAndNotesNewestFirst(): void {
		$this->moments = [
			['id' => 'm-1', 'title' => 'Call', 'channel' => 'phone', 'direction' => 'inbound', 'assignee' => 'ann', 'occurredAt' => '2026-01-02T10:00:00Z', 'description' => 'Asked'],
			$this->entity(payload: ['title' => 'Mail', 'occurredAt' => '2026-01-04T10:00:00Z'], uuid: 'm-2'),
		];
		$this->notes = [
			['id' => 'n-1', 'title' => 'Note', 'author' => 'bob', 'createdAt' => '2026-01-03T10:00:00Z', 'message' => 'First'],
			['id' => 'n-2', 'user' => 'cas', 'timestamp' => '2026-01-01T10:00:00Z', 'content' => 'Second'],
			['text' => 'Third'],
			'not a note',
		];

		$feed = $this->service(register: 'reg-1')->getActivity('client', 'c-9', 'all', 1, 20);

		$this->assertSame(
			[
				'total' => 5,
				'page' => 1,
				'pages' => 1,
				'results' => [
					['type' => 'interaction', 'id' => 'm-2', 'subject' => 'Mail', 'channel' => '', 'direction' => '', 'agent' => '', 'timestamp' => '2026-01-04T10:00:00Z', 'summary' => ''],
					['type' => 'note', 'id' => 'n-1', 'subject' => 'Note', 'agent' => 'bob', 'timestamp' => '2026-01-03T10:00:00Z', 'summary' => 'First'],
					['type' => 'interaction', 'id' => 'm-1', 'subject' => 'Call', 'channel' => 'phone', 'direction' => 'inbound', 'agent' => 'ann', 'timestamp' => '2026-01-02T10:00:00Z', 'summary' => 'Asked'],
					['type' => 'note', 'id' => 'n-2', 'subject' => '', 'agent' => 'cas', 'timestamp' => '2026-01-01T10:00:00Z', 'summary' => 'Second'],
				],
			],
			$this->withoutUndatedNotes(feed: $feed)
		);
		$this->assertSame(
			[['filters' => ['register' => 'reg-1', 'schema' => 'ticket-schema', 'ticketType' => TicketService::TYPE_CONTACTMOMENT, 'client' => 'c-9'], 'limit' => 500]],
			$this->queries
		);
		$this->assertSame([['c-9', 'reg-1', 'client-schema']], $this->finds);
	}//end testAClientFeedMergesMomentsAndNotesNewestFirst()

	/**
	 * A request links its contactmomenten through parentTicket and keeps
	 * its notes on the ticket schema; a lead has no contactmoment link.
	 *
	 * @return void
	 */
	public function testTheLinkFieldAndSchemaFollowTheEntityType(): void {
		$this->service(register: 'reg-1')->getActivity('request', 'r-1', 'all', 1, 20);
		$this->service(register: 'reg-1')->getActivity('lead', 'l-1', 'all', 1, 20);

		$this->assertSame(
			[['filters' => ['register' => 'reg-1', 'schema' => 'ticket-schema', 'ticketType' => TicketService::TYPE_CONTACTMOMENT, 'parentTicket' => 'r-1'], 'limit' => 500]],
			$this->queries
		);
		$this->assertSame([['r-1', 'reg-1', 'ticket-schema'], ['l-1', 'reg-1', 'lead-schema']], $this->finds);
	}//end testTheLinkFieldAndSchemaFollowTheEntityType()

	/**
	 * The type filter narrows the sources; an unknown filter reads all.
	 *
	 * @return void
	 */
	public function testTheTypeFilterNarrowsTheSources(): void {
		$service = $this->service(register: 'reg-1');

		$service->getActivity('client', 'c-1', 'notes', 1, 20);
		$this->assertSame([[], 1], [$this->queries, count($this->finds)]);

		$service->getActivity('client', 'c-1', ' ContactMomenten ', 1, 20);
		$this->assertSame([1, 1], [count($this->queries), count($this->finds)]);

		$service->getActivity('client', 'c-1', 'everything', 1, 20);
		$this->assertSame([2, 2], [count($this->queries), count($this->finds)]);
	}//end testTheTypeFilterNarrowsTheSources()

	/**
	 * Pages are sliced from the sorted feed; the page is clamped to the
	 * last page and the limit to the default and the maximum.
	 *
	 * @return void
	 */
	public function testThePageEnvelope(): void {
		for ($i = 1; $i <= 25; $i++) {
			$this->moments[] = ['id' => 'm-' . $i, 'occurredAt' => sprintf('2026-01-%02dT00:00:00Z', $i)];
		}

		$service = $this->service(register: 'reg-1');
		$second = $service->getActivity('client', 'c-1', 'contactmomenten', 2, 10);
		$this->assertSame([25, 2, 3], [$second['total'], $second['page'], $second['pages']]);
		$this->assertSame(['m-15', 'm-6'], [$second['results'][0]['id'], $second['results'][9]['id']]);

		$beyond = $service->getActivity('client', 'c-1', 'contactmomenten', 9, 10);
		$this->assertSame([3, ['m-5', 'm-4', 'm-3', 'm-2', 'm-1']], [$beyond['page'], array_column($beyond['results'], 'id')]);

		$default = $service->getActivity('client', 'c-1', 'contactmomenten', 1, 0);
		$this->assertSame([2, 20], [$default['pages'], count($default['results'])]);

		$max = $service->getActivity('client', 'c-1', 'contactmomenten', 1, 1000);
		$this->assertSame([1, 25], [$max['pages'], count($max['results'])]);
	}//end testThePageEnvelope()

	/**
	 * An empty feed has one page.
	 *
	 * @return void
	 */
	public function testAnEmptyFeedHasOnePage(): void {
		$this->assertSame(
			['total' => 0, 'page' => 1, 'pages' => 1, 'results' => []],
			$this->service(register: 'reg-1')->getActivity('client', 'c-1', 'all', 4, 20)
		);
	}//end testAnEmptyFeedHasOnePage()

	/**
	 * A failing query or entity load is logged and leaves that source out.
	 *
	 * @return void
	 */
	public function testAFailingSourceIsLoggedAndLeftOut(): void {
		$this->findAllFails = true;
		$this->findFails = true;

		$feed = $this->service(register: 'reg-1')->getActivity('client', 'c-1', 'all', 1, 20);

		$this->assertSame(0, $feed['total']);
		$this->assertSame(
			[
				['error', 'EntityActivityService: failed to query schema', ['exception' => 'query down', 'schemaId' => 'ticket-schema']],
				['warning', 'EntityActivityService: failed to load entity for notes', ['exception' => 'load down', 'entityId' => 'c-1']],
			],
			$this->logs
		);
	}//end testAFailingSourceIsLoggedAndLeftOut()

	/**
	 * Without a ticket or entity schema, no source is read.
	 *
	 * @return void
	 */
	public function testWithoutSchemasNothingIsRead(): void {
		$this->assertSame(0, $this->service(register: 'reg-1', schemas: false)->getActivity('client', 'c-1', 'all', 1, 20)['total']);
		$this->assertSame([[], []], [$this->queries, $this->finds]);
	}//end testWithoutSchemasNothingIsRead()

	/**
	 * The feed without the note that has no timestamp (its position among
	 * equal empty timestamps depends on the sort, not on this service).
	 *
	 * @param array<string, mixed> $feed The feed.
	 *
	 * @return array<string, mixed>
	 */
	private function withoutUndatedNotes(array $feed): array {
		$undated = array_values(array_filter($feed['results'], static fn (array $item): bool => $item['timestamp'] === ''));
		$this->assertSame([['type' => 'note', 'id' => '', 'subject' => '', 'agent' => '', 'timestamp' => '', 'summary' => 'Third']], $undated);
		$feed['results'] = array_values(array_filter($feed['results'], static fn (array $item): bool => $item['timestamp'] !== ''));
		return $feed;
	}//end withoutUndatedNotes()

	/**
	 * An OpenRegister entity double.
	 *
	 * @param array<string, mixed> $payload The object.
	 * @param string               $uuid    Its UUID.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(array $payload, string $uuid): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getObject')->willReturn($payload);
		$entity->method('getUuid')->willReturn($uuid);
		return $entity;
	}//end entity()

	/**
	 * The service over recording doubles.
	 *
	 * @param string $register The configured register.
	 * @param bool   $schemas  Whether the schemas are configured.
	 *
	 * @return EntityActivityService
	 */
	private function service(string $register, bool $schemas = true): EntityActivityService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($register, $schemas): string {
				if ($key === 'register') {
					return $register;
				}

				if ($schemas === true && str_ends_with($key, '_schema') === true) {
					return str_replace('_', '-', $key);
				}

				return $default;
			}
		);

		$tickets = $this->createMock(TicketService::class);
		$tickets->method('getSchemaId')->willReturn($schemas === true ? 'ticket-schema' : '');

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				$this->queries[] = $config;
				if ($this->findAllFails === true) {
					throw new RuntimeException('query down');
				}

				return $this->moments;
			}
		);
		$objects->method('find')->willReturnCallback(
			function ($id, $extend = [], $files = false, $register = null, $schema = null): ?ObjectEntityInterface {
				$this->finds[] = [$id, $register, $schema];
				if ($this->findFails === true) {
					throw new RuntimeException('load down');
				}

				if ($this->notes === null) {
					return null;
				}

				return $this->entity(payload: ['notes' => $this->notes], uuid: (string)$id);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		return new EntityActivityService($appConfig, $logger, $tickets, $objects);
	}//end service()
}//end class
