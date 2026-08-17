<?php

/**
 * Unit tests for the Pipelinq NaviConversationStore.
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/dashboard/spec.md#conversational-follow-up
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\NaviConversationStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the property the distributed-cache implementation silently failed:
 * a conversation written while serving one request must be readable while
 * serving the next, on an instance with no memcache configured.
 */
class NaviConversationStoreTest extends TestCase {
	/**
	 * A conversation identifier of the shape NaviController mints.
	 *
	 * @var string
	 */
	private const CONVERSATION_ID = '0123456789abcdef0123456789abcdef';

	/**
	 * A second, unrelated conversation identifier.
	 *
	 * @var string
	 */
	private const OTHER_CONVERSATION_ID = 'fedcba9876543210fedcba9876543210';

	/**
	 * The rows the fake preference backend holds, keyed `<userId>:<key>`.
	 *
	 * @var array<string, string>
	 */
	private array $rows = [];

	/**
	 * The clock every store in a test reads, so the TTL can be moved.
	 *
	 * @var int
	 */
	private int $now = 1000000;

	/**
	 * Reset the shared backing store between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->rows = [];
		$this->now = 1000000;
	}

	/**
	 * Build a store over the SHARED `$this->rows` backend.
	 *
	 * Every call returns a NEW store with NEW collaborators; only the rows
	 * survive, exactly as only the database survives between two HTTP
	 * requests. Nothing in the store is allowed to rely on in-process state.
	 *
	 * @return NaviConversationStore
	 */
	private function buildStore(): NaviConversationStore {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			function (string $userId, string $appName, string $key, $default = ''): string {
				return ($this->rows[$userId . ':' . $key] ?? (string)$default);
			}
		);
		$config->method('setUserValue')->willReturnCallback(
			function (string $userId, string $appName, string $key, $value, $preCondition = null): void {
				$this->rows[$userId . ':' . $key] = (string)$value;
			}
		);

		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return new NaviConversationStore(
			config: $config,
			timeFactory: $timeFactory,
			logger: $this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * THE regression test for the defect CI caught. The first implementation
	 * used `ICacheFactory::createDistributed()`, which on an instance with no
	 * memcache returns a `NullCache`: the write silently succeeded and the read
	 * silently returned nothing, so every follow-up was answered as a first
	 * question.
	 *
	 * Two separate store instances over one backing store stand in for two HTTP
	 * requests. Nothing but the stored row crosses between them.
	 *
	 * @return void
	 */
	public function testTurnsWrittenInOneRequestAreReadableInTheNext(): void {
		$requestA = $this->buildStore();
		$requestA->append(
			userId: 'alice',
			conversationId: self::CONVERSATION_ID,
			history: [],
			turn: ['query' => 'How many requests are open?', 'intent' => 'count']
		);

		$requestB = $this->buildStore();
		$turns = $requestB->read(userId: 'alice', conversationId: self::CONVERSATION_ID);

		$this->assertCount(1, $turns);
		$this->assertSame('count', $turns[0]['intent']);
		$this->assertSame('How many requests are open?', $turns[0]['query']);
	}

	/**
	 * The conversation accumulates across requests rather than being replaced:
	 * three separate stores append one turn each and the third read sees all
	 * of them, in order.
	 *
	 * @return void
	 */
	public function testConversationAccumulatesAcrossRequests(): void {
		$history = [];
		foreach (['first', 'second', 'third'] as $query) {
			$store = $this->buildStore();
			$store->append(
				userId: 'alice',
				conversationId: self::CONVERSATION_ID,
				history: $history,
				turn: ['query' => $query, 'intent' => 'count']
			);
			$history = $store->read(userId: 'alice', conversationId: self::CONVERSATION_ID);
		}

		$this->assertSame(
			['first', 'second', 'third'],
			array_column($history, 'query')
		);
	}

	/**
	 * A record belonging to a different conversation does not answer for this
	 * one: a new conversation starts empty.
	 *
	 * @return void
	 */
	public function testDifferentConversationStartsFresh(): void {
		$this->buildStore()->append(
			userId: 'alice',
			conversationId: self::CONVERSATION_ID,
			history: [],
			turn: ['query' => 'How many requests are open?', 'intent' => 'count']
		);

		$this->assertSame(
			[],
			$this->buildStore()->read(userId: 'alice', conversationId: self::OTHER_CONVERSATION_ID)
		);
	}

	/**
	 * The TTL is enforced on read, because user preferences carry no expiry of
	 * their own: a record read one second inside the window still answers, and
	 * one second outside it does not.
	 *
	 * @return void
	 */
	public function testRecordIsIgnoredOnceItAgesPastTheTtl(): void {
		$this->buildStore()->append(
			userId: 'alice',
			conversationId: self::CONVERSATION_ID,
			history: [],
			turn: ['query' => 'How many requests are open?', 'intent' => 'count']
		);

		$this->now += (NaviConversationStore::TTL - 1);
		$this->assertCount(
			1,
			$this->buildStore()->read(userId: 'alice', conversationId: self::CONVERSATION_ID),
			'a record inside the TTL must still answer'
		);

		$this->now += 2;
		$this->assertSame(
			[],
			$this->buildStore()->read(userId: 'alice', conversationId: self::CONVERSATION_ID),
			'a record past the TTL must be ignored'
		);
	}

	/**
	 * One user's conversation is unreachable from another user's session even
	 * with the identifier in hand: the record is addressed by user, so Bob's
	 * read never touches Alice's row.
	 *
	 * @return void
	 */
	public function testAnotherUserCannotReadTheConversation(): void {
		$this->buildStore()->append(
			userId: 'alice',
			conversationId: self::CONVERSATION_ID,
			history: [],
			turn: ['query' => 'How many requests are open?', 'intent' => 'count']
		);

		$this->assertNotSame([], $this->rows, 'the conversation must have been stored at all');
		$this->assertSame(
			[],
			$this->buildStore()->read(userId: 'bob', conversationId: self::CONVERSATION_ID)
		);
	}

	/**
	 * The owner stored inside the record is a second, independent barrier: a
	 * row that somehow surfaces under the wrong user is refused rather than
	 * answered.
	 *
	 * @return void
	 */
	public function testRecordOwnedByAnotherUserIsRefused(): void {
		$this->rows['bob:' . NaviConversationStore::PREFERENCE_KEY] = (string)json_encode([
			'owner' => 'alice',
			'conversationId' => self::CONVERSATION_ID,
			'updatedAt' => $this->now,
			'turns' => [['query' => 'How many requests are open?', 'intent' => 'count']],
		]);

		$this->assertSame(
			[],
			$this->buildStore()->read(userId: 'bob', conversationId: self::CONVERSATION_ID)
		);
	}

	/**
	 * The stored record stays small: at most MAX_TURNS turns, each query
	 * truncated to MAX_QUERY_LENGTH, and always exactly one row per user.
	 *
	 * @return void
	 */
	public function testStoredRecordIsBounded(): void {
		$history = [];
		$longText = str_repeat('a', (NaviConversationStore::MAX_QUERY_LENGTH + 50));

		for ($turn = 0; $turn < (NaviConversationStore::MAX_TURNS + 5); $turn++) {
			$store = $this->buildStore();
			$store->append(
				userId: 'alice',
				conversationId: self::CONVERSATION_ID,
				history: $history,
				turn: ['query' => $longText, 'intent' => 'count']
			);
			$history = $store->read(userId: 'alice', conversationId: self::CONVERSATION_ID);
		}

		$this->assertCount(1, $this->rows, 'a user must occupy exactly one preference row');
		$this->assertCount(NaviConversationStore::MAX_TURNS, $history);
		$this->assertSame(
			NaviConversationStore::MAX_QUERY_LENGTH,
			mb_strlen($history[0]['query']),
			'a retained query must be truncated'
		);
	}

	/**
	 * Without a conversation identifier nothing is read and nothing is written,
	 * so a one-shot query leaves no trace.
	 *
	 * @return void
	 */
	public function testWithoutAConversationIdNothingIsStored(): void {
		$store = $this->buildStore();
		$store->append(
			userId: 'alice',
			conversationId: null,
			history: [],
			turn: ['query' => 'How many requests are open?', 'intent' => 'count']
		);

		$this->assertSame([], $this->rows);
		$this->assertSame([], $store->read(userId: 'alice', conversationId: null));
	}

	/**
	 * carryForward leaves a self-classifying turn alone, hands an `unknown`
	 * turn the previous intent, and always exposes the previous query so a
	 * subject can be inherited.
	 *
	 * @return void
	 */
	public function testCarryForwardInheritsOnlyWhatIsMissing(): void {
		$store = $this->buildStore();
		$history = [['query' => 'How many requests are open?', 'intent' => 'count']];

		$this->assertSame(
			['intent' => 'count', 'query' => 'How many requests are open?'],
			$store->carryForward(detectedIntent: 'unknown', history: $history)
		);
		$this->assertSame(
			['intent' => 'trend', 'query' => 'How many requests are open?'],
			$store->carryForward(detectedIntent: 'trend', history: $history)
		);
		$this->assertSame(
			['intent' => 'unknown', 'query' => ''],
			$store->carryForward(detectedIntent: 'unknown', history: [])
		);
		$this->assertSame(
			['intent' => 'unknown', 'query' => 'stale'],
			$store->carryForward(
				detectedIntent: 'unknown',
				history: [['query' => 'stale', 'intent' => 'not-an-intent']]
			)
		);
	}
}
