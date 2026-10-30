<?php

/**
 * Unit tests for ClientHistorySummaryService: what goes into the summary, as
 * whom it is read, and what happens without hermiq.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\ActivityTimelineReader;
use OCA\Pipelinq\Service\ClientHistorySummaryService;
use OCA\Pipelinq\Service\Competitor\RelevanceScorer;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A stand-in for hermiq's provider factory that records the prompt.
 */
class HermiqProviderStub {

	/**
	 * The prompts it was given.
	 *
	 * @var list<string>
	 */
	public array $prompts = [];

	/**
	 * The user ids it was called for.
	 *
	 * @var list<string|null>
	 */
	public array $users = [];

	/**
	 * Constructor.
	 *
	 * @param string $answer What it answers.
	 */
	public function __construct(
		private string $answer = 'Twelve calls this quarter and two open tickets.',
	) {
	}//end __construct()

	/**
	 * Answer a prompt.
	 *
	 * @param string $prompt The prompt.
	 * @param string|null $userId The acting user.
	 * @param bool $allowNextcloud Unused.
	 * @param string|null $organisation Unused.
	 *
	 * @return string
	 */
	public function generateText(string $prompt, ?string $userId = null, bool $allowNextcloud = true, ?string $organisation = null): string {
		unset($allowNextcloud, $organisation);
		$this->prompts[] = $prompt;
		$this->users[] = $userId;
		return $this->answer;
	}//end generateText()
}//end class

/**
 * Tests for the client history summary.
 *
 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-a-clients-history-can-be-summarised-on-request-req-cin-002
 */
class ClientHistorySummaryServiceTest extends TestCase {

	/**
	 * Tickets the viewer may read.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $tickets = [];

	/**
	 * Leads the viewer may read.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $leads = [];

	/**
	 * The client's notes.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $notes = [];

	/**
	 * The `_rbac` flag of every findAll() call.
	 *
	 * @var list<bool>
	 */
	private array $rbacFlags = [];

	/**
	 * The fixed reference instant.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $now;

	/**
	 * Set the clock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->now = new DateTimeImmutable('2026-10-30T12:00:00+00:00');
	}//end setUp()

	/**
	 * Build the service.
	 *
	 * @param object|null $hermiq The provider factory, or null for an instance without hermiq.
	 *
	 * @return ClientHistorySummaryService
	 */
	private function service(?object $hermiq): ClientHistorySummaryService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($hermiq) {
				if ($id === RelevanceScorer::PROVIDER_CLASS && $hermiq !== null) {
					return $hermiq;
				}

				throw new class ('no ' . $id) extends RuntimeException implements NotFoundExceptionInterface {
				};
			}
		);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config, bool $_rbac = true) {
				$this->rbacFlags[] = $_rbac;
				return match ($config['filters']['schema']) {
					'sch-ticket' => $this->tickets,
					'sch-lead' => $this->leads,
					default => [],
				};
			}
		);

		$reader = $this->createMock(ActivityTimelineReader::class);
		$reader->method('notes')->willReturnCallback(fn (): array => $this->notes);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'register' => 'reg',
				'ticket_schema' => 'sch-ticket',
				'lead_schema' => 'sch-lead',
				default => $default,
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$l10n->method('getLanguageCode')->willReturn('nl');

		return new ClientHistorySummaryService($container, $objectService, $reader, $appConfig, $l10n, $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * Spec scenario: twelve contact moments and two open tickets this quarter
	 * give a summary that says it read 14 records, and when.
	 *
	 * @return void
	 */
	public function testSummaryNamesRecordsReadAndTime(): void {
		for ($i = 1; $i <= 12; $i++) {
			$this->tickets[] = ['ticketType' => 'interaction', 'title' => 'Call ' . $i, 'occurredAt' => sprintf('2026-10-%02dT10:00:00+00:00', $i)];
		}

		$this->tickets[] = ['ticketType' => 'request', 'title' => 'New invoice address', 'status' => 'in_progress', 'occurredAt' => '2026-10-20T10:00:00+00:00'];
		$this->tickets[] = ['ticketType' => 'complaint', 'title' => 'Late delivery', 'status' => 'new', '@self' => ['created' => '2026-10-21T10:00:00+00:00']];
		$hermiq = new HermiqProviderStub();

		$result = $this->service($hermiq)->summarise('client-1', 'agent-1', $this->now);

		$this->assertSame('Twelve calls this quarter and two open tickets.', $result['summary']);
		$this->assertSame(14, $result['recordsRead']);
		$this->assertSame('2026-10-30T12:00:00+00:00', $result['generatedAt']);
		$this->assertSame(['agent-1'], $hermiq->users);
		$this->assertStringContainsString('[complaint] Late delivery | new', $hermiq->prompts[0]);
		$this->assertStringContainsString('"nl"', $hermiq->prompts[0]);
	}//end testSummaryNamesRecordsReadAndTime()

	/**
	 * Spec scenario: a record the viewer may not read stays out. The reads run
	 * with RBAC on, and what OpenRegister withholds never reaches the prompt.
	 *
	 * @return void
	 */
	public function testRecordsTheViewerCannotReadStayOut(): void {
		$this->tickets = [['ticketType' => 'interaction', 'title' => 'Call about the renewal', 'occurredAt' => '2026-10-20T10:00:00+00:00']];
		$hermiq = new HermiqProviderStub();

		$this->service($hermiq)->summarise('client-1', 'agent-1', $this->now);

		$this->assertNotContains(false, $this->rbacFlags, 'Every read runs with the viewer\'s rights');
		$this->assertStringNotContainsString('complaint', $hermiq->prompts[0]);
	}//end testRecordsTheViewerCannotReadStayOut()

	/**
	 * Only the last 90 days go in, newest first, at most 40 records, with notes and leads.
	 *
	 * @return void
	 */
	public function testInputIsWindowedOrderedAndBounded(): void {
		for ($i = 0; $i < 50; $i++) {
			$this->tickets[] = ['ticketType' => 'interaction', 'title' => 'Call ' . $i, 'occurredAt' => $this->now->modify('-' . $i . ' days')->format(DATE_ATOM)];
		}

		$this->tickets[] = ['ticketType' => 'interaction', 'title' => 'Too old', 'occurredAt' => '2026-01-01T10:00:00+00:00'];
		$this->leads = [['title' => 'Renewal 2027', 'status' => 'open', '@self' => ['updated' => $this->now->modify('-1 hour')->format(DATE_ATOM)]]];
		$this->notes = [['message' => 'Prefers mail', 'timestamp' => $this->now->modify('-2 hours')->format(DATE_ATOM)]];

		$records = $this->service(new HermiqProviderStub())->records('client-1', $this->now);

		$this->assertCount(ClientHistorySummaryService::MAX_RECORDS, $records);
		$this->assertSame('interaction', $records[0]['kind']);
		$this->assertSame('lead', $records[1]['kind']);
		$this->assertSame('note', $records[2]['kind']);
		$this->assertNotContains('Too old', array_column($records, 'text'));
	}//end testInputIsWindowedOrderedAndBounded()

	/**
	 * Without hermiq the service says so and refuses to summarise.
	 *
	 * @return void
	 */
	public function testWithoutHermiqThereIsNoSummary(): void {
		$service = $this->service(null);

		$this->assertFalse($service->available());
		$this->expectException(RuntimeException::class);
		$service->summarise('client-1', 'agent-1', $this->now);
	}//end testWithoutHermiqThereIsNoSummary()

	/**
	 * A quiet client gets a plain line and no model call.
	 *
	 * @return void
	 */
	public function testNoRecordsMeansNoModelCall(): void {
		$hermiq = new HermiqProviderStub();

		$result = $this->service($hermiq)->summarise('client-1', 'agent-1', $this->now);

		$this->assertSame(0, $result['recordsRead']);
		$this->assertSame([], $hermiq->prompts);
	}//end testNoRecordsMeansNoModelCall()

	/**
	 * An empty answer is an error, not an empty summary.
	 *
	 * @return void
	 */
	public function testEmptyAnswerIsAnError(): void {
		$this->tickets = [['ticketType' => 'interaction', 'title' => 'Call', 'occurredAt' => '2026-10-20T10:00:00+00:00']];

		$this->expectException(RuntimeException::class);
		$this->service(new HermiqProviderStub(''))->summarise('client-1', 'agent-1', $this->now);
	}//end testEmptyAnswerIsAnError()
}//end class
