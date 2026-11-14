<?php

/**
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit
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
use OCA\Pipelinq\Mcp\McpAnswer;
use OCA\Pipelinq\Service\PhoneHistoryService;
use OCA\Pipelinq\Service\PhoneNormaliser;
use OCA\Pipelinq\Service\TicketPhoneNumber;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Phone history over tickets without an id or a date, rows that are not
 * tickets, and the exact reads and log line OpenRegister sees.
 *
 * @covers \OCA\Pipelinq\Service\PhoneHistoryService
 * @uses   \OCA\Pipelinq\Service\PhoneNeedles
 * @uses   \OCA\Pipelinq\Service\PhoneNormaliser
 * @uses   \OCA\Pipelinq\Service\TicketPhoneNumber
 * @uses   \OCA\Pipelinq\Service\TicketService
 * @uses   \OCA\Pipelinq\Mcp\McpAnswer
 * @uses   \OCA\Pipelinq\Util\PayloadToArray
 * @uses   \OCA\Pipelinq\Util\EntityPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 *
 * @spec openspec/changes/contact-moments-phone-lookup/tasks.md#task-1.4
 */
class PhoneHistoryServiceCoverageTest extends TestCase {

	/**
	 * The config handed to each findAll call.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $reads = [];

	/**
	 * A service whose exact read answers $exact and whose tail scan answers $scan.
	 *
	 * @param array<int, mixed>    $exact  Rows for the phoneE164 read.
	 * @param array<int, mixed>    $scan   Rows for the tail scan.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return PhoneHistoryService
	 */
	private function service(array $exact, array $scan, ?LoggerInterface $logger = null): PhoneHistoryService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (['register' => '5', 'ticket_schema' => '9', 'default_country' => 'NL'][$key] ?? $default)
		);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->exactly(2))->method('findAll')->willReturnCallback(
			function (array $query = []) use ($exact, $scan): array {
				$this->reads[] = $query;
				return isset($query['filters']['phoneE164']) === true ? $exact : $scan;
			}
		);

		$logger  ??= $this->createMock(LoggerInterface::class);
		$tickets    = new TicketService(appConfig: $appConfig, logger: $logger, objectService: $objects, mcp: new McpAnswer(logger: $logger));
		$normaliser = new PhoneNormaliser($appConfig, $logger);

		return new PhoneHistoryService($tickets, $normaliser, new TicketPhoneNumber($normaliser), $logger);
	}//end service()

	/**
	 * The two reads are exactly a phoneE164 read capped at MAX_RESULTS and an unfiltered tail scan.
	 *
	 * @return void
	 */
	public function testTheTwoReadsAreScopedToTheTicketSchema(): void {
		$this->service([], [])->findTickets('0612345678');

		$this->assertSame(
			[
				[
					'filters' => ['register' => '5', 'schema' => '9', 'phoneE164' => '+31612345678'],
					'sort' => ['occurredAt' => 'desc'],
					'limit' => 50,
				],
				[
					'filters' => ['register' => '5', 'schema' => '9'],
					'sort' => ['occurredAt' => 'desc'],
					'limit' => 500,
				],
			],
			$this->reads
		);
	}//end testTheTwoReadsAreScopedToTheTicketSchema()

	/**
	 * A ticket without id or uuid is keyed by its content, so the tail scan does not report it twice.
	 *
	 * @return void
	 */
	public function testATicketWithoutAnIdIsNotReportedTwice(): void {
		$anonymous = ['phoneE164' => '+31612345678', 'from_number' => '+31612345678', 'occurredAt' => '2026-10-01T09:00:00Z'];

		$found = $this->service([$anonymous], [$anonymous])->findTickets('0612345678');

		$this->assertCount(1, $found['tickets']);
		$this->assertSame('exact', $found['tickets'][0]['_phoneMatch']);
		$this->assertSame('2026-10-01T09:00:00Z', $found['tickets'][0]['occurredAt']);
	}//end testATicketWithoutAnIdIsNotReportedTwice()

	/**
	 * The same ticket read twice is reported once, by its id, even when the two reads render it differently.
	 *
	 * @return void
	 */
	public function testTheSameIdIsReportedOnceHoweverItIsRendered(): void {
		$exact = ['id' => 't-1', 'phoneE164' => '+31612345678', 'from_number' => '+31612345678', 'occurredAt' => '2026-10-01T09:00:00Z'];
		$scan  = array_merge($exact, ['@self' => ['created' => '2026-09-30T08:00:00Z']]);

		$found = $this->service([$exact], [$scan])->findTickets('0612345678');

		$this->assertSame([$exact + ['_phoneMatch' => 'exact']], $found['tickets']);
	}//end testTheSameIdIsReportedOnceHoweverItIsRendered()

	/**
	 * Two different tickets without an id are both reported.
	 *
	 * @return void
	 */
	public function testDifferentTicketsWithoutAnIdAreBothReported(): void {
		$first  = ['phoneE164' => '+31612345678', 'from_number' => '+31612345678', 'occurredAt' => '2026-10-02T09:00:00Z'];
		$second = ['reporterPhone' => '06 1234 5678', 'occurredAt' => '2026-10-01T09:00:00Z'];

		$found = $this->service([$first], [$first, $second])->findTickets('0612345678');

		$this->assertSame(['exact', 'possible'], array_column($found['tickets'], '_phoneMatch'));
	}//end testDifferentTicketsWithoutAnIdAreBothReported()

	/**
	 * A ticket keyed by uuid is deduplicated the same way an id-keyed one is.
	 *
	 * @return void
	 */
	public function testATicketKeyedByUuidIsNotReportedTwice(): void {
		$ticket = ['uuid' => 'u-1', 'phoneE164' => '+31612345678', 'from_number' => '+31612345678'];

		$found = $this->service([$ticket], [$ticket, ['uuid' => 'u-2', 'reporterPhone' => '06 12345678']])->findTickets('0612345678');

		$this->assertSame(['u-1' => 'exact', 'u-2' => 'possible'], array_column($found['tickets'], '_phoneMatch', 'uuid'));
	}//end testATicketKeyedByUuidIsNotReportedTwice()

	/**
	 * Rows that carry no payload are skipped, entity rows are read through jsonSerialize, undated tickets sort last.
	 *
	 * @return void
	 */
	public function testRowsWithoutAPayloadAreSkippedAndUndatedTicketsSortLast(): void {
		$entity = new class implements \JsonSerializable {
			/**
			 * The ticket payload.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'e-1', 'reporterPhone' => '+31 6 1234 5678', 'started_at' => '2026-10-03T09:00:00Z'];
			}
		};

		$scan = [
			'not a ticket',
			[],
			['id' => 'undated', 'reporterPhone' => '0612345678', '@self' => 'broken'],
			['id' => 'empty-date', 'reporterPhone' => '0612345678', 'occurredAt' => '', '@self' => ['created' => '']],
			$entity,
			['id' => 'other', 'reporterPhone' => '0201234567', 'occurredAt' => '2026-10-04T09:00:00Z'],
		];

		$found = $this->service([], $scan)->findTickets('06-12345678');

		$this->assertSame(['e-1', 'undated', 'empty-date'], array_column($found['tickets'], 'id'));
		$this->assertSame(['possible', 'possible', 'possible'], array_column($found['tickets'], '_phoneMatch'));
	}//end testRowsWithoutAPayloadAreSkippedAndUndatedTicketsSortLast()

	/**
	 * A failed read is logged with the exception message and the search answers no tickets.
	 *
	 * @return void
	 */
	public function testAFailedReadIsLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning')
			->with('Phone history: reading tickets failed', ['exception' => 'register offline']);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (['register' => '5', 'ticket_schema' => '9', 'default_country' => 'NL'][$key] ?? $default)
		);
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->exactly(2))->method('findAll')->willThrowException(new RuntimeException('register offline'));

		$tickets    = new TicketService(appConfig: $appConfig, logger: $logger, objectService: $objects, mcp: new McpAnswer(logger: $logger));
		$normaliser = new PhoneNormaliser($appConfig, $logger);
		$service    = new PhoneHistoryService($tickets, $normaliser, new TicketPhoneNumber($normaliser), $logger);

		$this->assertSame(['number' => '+31612345678', 'tickets' => []], $service->findTickets('0612345678'));
	}//end testAFailedReadIsLogged()
}//end class
