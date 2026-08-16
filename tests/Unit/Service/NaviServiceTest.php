<?php

/**
 * Unit tests for the Pipelinq NaviService.
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
 * @spec openspec/changes/dashboard/tasks.md#task-1.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\NaviConversationStore;
use OCA\Pipelinq\Service\NaviService;
use OCA\Pipelinq\Service\TicketService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Covers intent detection, context building, empty-result degradation,
 * response shaping and the conversation store behind follow-up questions. The
 * OpenRegister ObjectService is faked through the DI container so the tests run
 * without the OR app installed.
 */
class NaviServiceTest extends TestCase {
	/**
	 * The clarification a query with no recognisable intent produces. A
	 * follow-up that inherited its intent must NOT produce this.
	 *
	 * @var string
	 */
	private const CLARIFICATION = 'I am not sure how to answer that yet.';

	/**
	 * A conversation identifier of the shape NaviController mints.
	 *
	 * @var string
	 */
	private const CONVERSATION_ID = '0123456789abcdef0123456789abcdef';

	/**
	 * The preference rows every service built in a test shares, keyed
	 * `<userId>:<key>`. Only these rows survive between two services, exactly
	 * as only the database survives between two HTTP requests.
	 *
	 * @var array<string, string>
	 */
	private array $rows = [];

	/**
	 * Reset the shared preference rows between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->rows = [];
	}

	/**
	 * Build a service with deterministic config and a fake ObjectService.
	 *
	 * Request / contactmoment fixtures are keyed `ticket_schema:<ticketType>`:
	 * both are subtypes of the unified `ticket` schema and are narrowed with a
	 * `ticketType` filter rather than with a schema of their own.
	 *
	 * Every call returns a NEW service over the SHARED preference rows, so two
	 * services stand in for two HTTP requests.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $byCollection Per-collection fixture rows.
	 * @param bool $configMissing Force the register/schema config blank.
	 * @param IConfig|null $preferences Preference backend; the shared
	 *                                  in-memory one when omitted.
	 *
	 * @return NaviService
	 */
	private function buildService(
		array $byCollection = [],
		bool $configMissing = false,
		?IConfig $preferences = null,
	): NaviService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $appId, string $key, string $default = '') use ($configMissing): string {
				if ($configMissing === true) {
					return '';
				}
				if ($key === 'register') {
					return 'register-1';
				}
				return $key;
			}
		);

		$objectService = new class($byCollection) {
			/**
			 * @param array<string, array<int, array<string, mixed>>> $byCollection
			 */
			public function __construct(
				private array $byCollection,
			) {
			}

			/**
			 * @param array{filters?: array<string, mixed>} $config
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config): array {
				$filters = ($config['filters'] ?? []);
				$key = (string)($filters['schema'] ?? '');
				if (isset($filters['ticketType']) === true) {
					$key .= ':' . (string)$filters['ticketType'];
				}
				return $this->byCollection[$key] ?? [];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$logger = $this->createMock(LoggerInterface::class);

		// TicketService stub serving the `ticket_schema:<ticketType>` fixtures;
		// it mirrors the real resolver's fail-soft contract (unconfigured -> []).
		// Only the OpenRegister-facing methods are doubled: detectTypeInText is
		// pure and runs for real, so these tests read the production vocabulary
		// rather than a copy of it.
		$ticketService = $this->createPartialMock(
			TicketService::class,
			['getRegisterId', 'getSchemaId', 'isConfigured', 'findByType']
		);
		$ticketService->method('getRegisterId')->willReturn($configMissing === true ? '' : 'register-1');
		$ticketService->method('getSchemaId')->willReturn($configMissing === true ? '' : 'ticket_schema');
		$ticketService->method('isConfigured')->willReturn($configMissing === false);
		$ticketService->method('findByType')->willReturnCallback(
			static function (string $ticketType) use ($byCollection, $configMissing): array {
				if ($configMissing === true) {
					return [];
				}
				return $byCollection['ticket_schema:' . $ticketType] ?? [];
			}
		);

		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(1000000);

		// The real store, so these tests exercise the production addressing and
		// owner check rather than a test double of them.
		$conversationStore = new NaviConversationStore(
			config: ($preferences ?? $this->preferenceBackend()),
			timeFactory: $timeFactory,
			logger: $logger
		);

		return new NaviService(
			appConfig: $appConfig,
			logger: $logger,
			ticketService: $ticketService,
			conversationStore: $conversationStore,
			objectService: $objectService,
		);
	}

	/**
	 * A preference backend over the shared `$this->rows`, so every service a
	 * test builds reads and writes the same durable rows.
	 *
	 * @return IConfig
	 */
	private function preferenceBackend(): IConfig {
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

		return $config;
	}

	/**
	 * detectIntent recognises trend / count / breakdown / conversion / unknown.
	 *
	 * @return void
	 */
	public function testDetectIntentClassifiesKnownPhrases(): void {
		$service = $this->buildService();

		$this->assertSame('trend', $service->detectIntent(query: 'Show me the trend over time'));
		$this->assertSame('trend', $service->detectIntent(query: 'Wat is het verloop van leads?'));
		$this->assertSame('count', $service->detectIntent(query: 'Hoeveel verzoeken zijn er deze maand?'));
		$this->assertSame('breakdown', $service->detectIntent(query: 'Group requests by category'));
		$this->assertSame('breakdown', $service->detectIntent(query: 'verzoeken per categorie'));
		$this->assertSame('conversion', $service->detectIntent(query: 'What is my conversion rate this quarter?'));
		$this->assertSame('conversion', $service->detectIntent(query: 'Hoeveel leads zijn er gewonnen?'));
		$this->assertSame('unknown', $service->detectIntent(query: 'Bake me a cake'));
	}

	/**
	 * processQuery returns resultType: "text" when ObjectService yields no data.
	 *
	 * @return void
	 */
	public function testProcessQueryReturnsTextOnEmptyResult(): void {
		$service = $this->buildService(byCollection: ['lead_schema' => []]);

		$response = $service->processQuery(
			query: 'Show me the trend of leads',
			userId: 'alice'
		);

		$this->assertSame('text', $response['resultType']);
		$this->assertNotSame('', $response['textResponse']);
		$this->assertArrayNotHasKey('chartData', $response);
		$this->assertArrayNotHasKey('tableData', $response);
		$this->assertNotEmpty($response['suggestedFollowUps']);
	}

	/**
	 * formatResponse emits chartData when the intermediate envelope contains it.
	 *
	 * @return void
	 */
	public function testFormatResponseWithChartData(): void {
		$service = $this->buildService();

		$envelope = $service->formatResponse(
			query: 'Show trend',
			llmResponse: [
				'resultType' => 'chart',
				'chartData' => ['type' => 'line', 'series' => [['name' => 'Leads', 'data' => [1, 2, 3]]]],
				'textResponse' => 'Trend',
				'suggestedFollowUps' => ['A', 'B', 'C', 'D'],
			],
			rawData: []
		);

		$this->assertSame('chart', $envelope['resultType']);
		$this->assertArrayHasKey('chartData', $envelope);
		$this->assertSame('line', $envelope['chartData']['type']);
		$this->assertCount(NaviService::MAX_FOLLOWUPS, $envelope['suggestedFollowUps']);
	}

	/**
	 * formatResponse emits tableData when the intermediate envelope contains it.
	 *
	 * @return void
	 */
	public function testFormatResponseWithTableData(): void {
		$service = $this->buildService();

		$envelope = $service->formatResponse(
			query: 'Conversion',
			llmResponse: [
				'resultType' => 'table',
				'tableData' => ['columns' => ['Metric', 'Value'], 'rows' => [['Won', 3]]],
				'textResponse' => 'Done',
				'suggestedFollowUps' => [],
			],
			rawData: []
		);

		$this->assertSame('table', $envelope['resultType']);
		$this->assertArrayHasKey('tableData', $envelope);
		$this->assertCount(1, $envelope['tableData']['rows']);
		$this->assertSame([], $envelope['suggestedFollowUps']);
	}

	/**
	 * processQuery with a conversion query against won/lost rows returns a
	 * table result whose rate matches the simple won/total computation.
	 *
	 * @return void
	 */
	public function testProcessQueryConversionRate(): void {
		$service = $this->buildService(byCollection: [
			'lead_schema' => [
				['status' => 'won'],
				['status' => 'won'],
				['status' => 'lost'],
				['status' => 'open'],
			],
		]);

		$response = $service->processQuery(
			query: 'What is my conversion rate?',
			userId: 'alice'
		);

		$this->assertSame('table', $response['resultType']);
		$this->assertArrayHasKey('tableData', $response);
		$row = $this->findTableRow($response['tableData']['rows'], 'Conversion rate (%)');
		$this->assertNotNull($row);
		$this->assertSame(50.0, $row[1]);
	}

	/**
	 * A breakdown query reads request TICKETS (ticketType: request on the
	 * unified ticket schema) and groups them by category.
	 *
	 * @return void
	 */
	public function testProcessQueryBreakdownReadsRequestTickets(): void {
		$service = $this->buildService(byCollection: [
			'ticket_schema:request' => [
				['category' => 'belastingen'],
				['category' => 'belastingen'],
				['category' => 'vergunningen'],
			],
			// Contactmoment tickets share the schema but must not leak in.
			'ticket_schema:interaction' => [
				['category' => 'should-not-appear'],
			],
		]);

		$response = $service->processQuery(
			query: 'Group requests by category',
			userId: 'alice'
		);

		$this->assertSame('chart', $response['resultType']);
		$this->assertSame(['belastingen', 'vergunningen'], $response['chartData']['labels']);
		$this->assertSame([2, 1], $response['chartData']['series'][0]['data']);
	}

	/**
	 * Empty / whitespace query degrades to a text prompt — no exception.
	 *
	 * @return void
	 */
	public function testProcessQueryHandlesBlankInput(): void {
		$service = $this->buildService();
		$response = $service->processQuery(query: '   ', userId: 'alice');

		$this->assertSame('text', $response['resultType']);
		$this->assertNotEmpty($response['suggestedFollowUps']);
	}

	/**
	 * The fixtures for the follow-up tests: four request tickets and a single
	 * lead. The counts differ so the ANSWER reveals which subject was counted.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function followUpFixtures(): array {
		return [
			'ticket_schema:request' => [
				['category' => 'permits'],
				['category' => 'permits'],
				['category' => 'taxes'],
				['category' => 'waste'],
			],
			'lead_schema' => [
				['status' => 'open'],
			],
		];
	}

	/**
	 * THE test for the second half of the follow-up scenario: the response must
	 * reflect the accumulated conversation context.
	 *
	 * "And how many of those are overdue?" names no subject, so counted on its
	 * own words it falls back to leads and answers "Found 1 records" — that is
	 * what the control test below observes. As the second turn of a
	 * conversation whose first turn counted REQUESTS, "those" must resolve to
	 * the requests, and the answer must be "Found 4 records".
	 *
	 * The two fixture sets are deliberately different sizes, so the count in
	 * the answer names which subject was actually queried.
	 *
	 * The two turns run on SEPARATE service instances, because in production
	 * they are separate HTTP requests and nothing but the stored record crosses
	 * between them.
	 *
	 * @return void
	 */
	public function testFollowUpInheritsPreviousTurnSubject(): void {
		$first = $this->buildService(byCollection: $this->followUpFixtures())->processQuery(
			query: 'How many requests are open?',
			userId: 'alice',
			conversationId: self::CONVERSATION_ID
		);
		$this->assertStringContainsString('Found 4 records', $first['textResponse']);

		$followUp = $this->buildService(byCollection: $this->followUpFixtures())->processQuery(
			query: 'And how many of those are overdue?',
			userId: 'alice',
			conversationId: self::CONVERSATION_ID
		);

		$this->assertSame('text', $followUp['resultType']);
		$this->assertStringContainsString('Found 4 records', $followUp['textResponse']);
	}

	/**
	 * Control for the test above: the same follow-up asked outside any
	 * conversation counts the lead fallback instead, which is what proves the
	 * inherited answer came from the stored context and not from the wording of
	 * the follow-up itself.
	 *
	 * @return void
	 */
	public function testFollowUpWithoutConversationFallsBackToLeads(): void {
		$service = $this->buildService(byCollection: $this->followUpFixtures());

		$response = $service->processQuery(
			query: 'And how many of those are overdue?',
			userId: 'alice'
		);

		$this->assertSame('text', $response['resultType']);
		$this->assertStringContainsString('Found 1 records', $response['textResponse']);
	}

	/**
	 * A follow-up with no intent keyword of its own inherits the previous
	 * turn's intent instead of degrading to the clarification prompt.
	 *
	 * "And what about last month?" classifies as `unknown` on its own words, so
	 * outside a conversation it earns the clarification (asserted by the
	 * control below). Inside one it is answered as the count its predecessor
	 * was, over that predecessor's subject.
	 *
	 * The two turns run on SEPARATE service instances, mirroring two requests.
	 *
	 * @return void
	 */
	public function testFollowUpWithoutIntentKeywordInheritsPreviousIntent(): void {
		$this->buildService(byCollection: $this->followUpFixtures())->processQuery(
			query: 'How many requests are open?',
			userId: 'alice',
			conversationId: self::CONVERSATION_ID
		);

		$followUp = $this->buildService(byCollection: $this->followUpFixtures())->processQuery(
			query: 'And what about last month?',
			userId: 'alice',
			conversationId: self::CONVERSATION_ID
		);

		$this->assertStringNotContainsString(self::CLARIFICATION, $followUp['textResponse']);
		$this->assertStringContainsString('Found 4 records', $followUp['textResponse']);
	}

	/**
	 * Control for the intent-inheritance test: the same wording outside a
	 * conversation still gets the clarification prompt.
	 *
	 * @return void
	 */
	public function testQueryWithoutIntentKeywordGetsClarification(): void {
		$service = $this->buildService(byCollection: $this->followUpFixtures());

		$response = $service->processQuery(query: 'And what about last month?', userId: 'alice');

		$this->assertStringContainsString(self::CLARIFICATION, $response['textResponse']);
	}

	/**
	 * Cross-user isolation. The Navi endpoint is `#[NoAdminRequired]`, so any
	 * authenticated user can send any conversation identifier. Bob replays the
	 * exact identifier Alice's conversation is stored under, against the SAME
	 * cache, and must see none of Alice's turns: his follow-up falls back to
	 * the clarification instead of inheriting Alice's request count.
	 *
	 * @return void
	 */
	public function testConversationIsNotReadableByAnotherUser(): void {
		$this->buildService(byCollection: $this->followUpFixtures())->processQuery(
			query: 'How many requests are open?',
			userId: 'alice',
			conversationId: self::CONVERSATION_ID
		);
		$this->assertNotSame([], $this->rows, 'the conversation must have been stored at all');

		$response = $this->buildService(byCollection: $this->followUpFixtures())->processQuery(
			query: 'And what about last month?',
			userId: 'bob',
			conversationId: self::CONVERSATION_ID
		);

		$this->assertStringContainsString(self::CLARIFICATION, $response['textResponse']);
		$this->assertStringNotContainsString('Found 4 records', $response['textResponse']);
		$this->assertCount(
			2,
			$this->rows,
			'one identifier used by two users must occupy two separate per-user rows'
		);
	}

	/**
	 * The owner recorded inside the conversation record is a second,
	 * independent barrier: even a preference backend that hands back Alice's
	 * record for EVERY user — the worst case a misconfiguration or a poisoned
	 * row could produce — must not let Bob inherit her context.
	 *
	 * @return void
	 */
	public function testConversationRecordOwnedByAnotherUserIsIgnored(): void {
		$aliceRecord = (string)json_encode([
			'owner' => 'alice',
			'conversationId' => self::CONVERSATION_ID,
			'updatedAt' => 1000000,
			'turns' => [['query' => 'How many requests are open?', 'intent' => 'count']],
		]);

		$leakyBackend = $this->createMock(IConfig::class);
		$leakyBackend->method('getUserValue')->willReturn($aliceRecord);

		$bob = $this->buildService(byCollection: $this->followUpFixtures(), preferences: $leakyBackend);
		$response = $bob->processQuery(
			query: 'And what about last month?',
			userId: 'bob',
			conversationId: self::CONVERSATION_ID
		);

		$this->assertStringContainsString(self::CLARIFICATION, $response['textResponse']);
		$this->assertStringNotContainsString('Found 4 records', $response['textResponse']);
	}

	/**
	 * However many turns a chat runs for, the user's stored record stays a
	 * single row holding at most NaviConversationStore::MAX_TURNS turns. Each
	 * turn runs on its own service instance, as it would in production.
	 *
	 * @return void
	 */
	public function testConversationRecordStaysBoundedAcrossManyTurns(): void {
		$turns = (NaviConversationStore::MAX_TURNS + 5);
		for ($turn = 0; $turn < $turns; $turn++) {
			$this->buildService(byCollection: $this->followUpFixtures())->processQuery(
				query: 'How many requests are open?',
				userId: 'alice',
				conversationId: self::CONVERSATION_ID
			);
		}

		$this->assertCount(1, $this->rows, 'one user must occupy exactly one preference row');
		$record = json_decode((string)reset($this->rows), true);
		$this->assertSame('alice', $record['owner']);
		$this->assertCount(NaviConversationStore::MAX_TURNS, $record['turns']);
	}

	/**
	 * @param array<int, array<int, mixed>> $rows
	 * @param string $label
	 *
	 * @return array<int, mixed>|null
	 */
	private function findTableRow(array $rows, string $label): ?array {
		foreach ($rows as $row) {
			if (($row[0] ?? null) === $label) {
				return $row;
			}
		}
		return null;
	}
}
