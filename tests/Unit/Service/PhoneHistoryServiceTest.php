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
 * A ticket is found by phone number however the number was typed (REQ-CHP-002).
 *
 * @covers \OCA\Pipelinq\Service\PhoneHistoryService
 * @uses   \OCA\Pipelinq\Service\PhoneNeedles
 * @uses   \OCA\Pipelinq\Service\PhoneNormaliser
 * @uses   \OCA\Pipelinq\Service\TicketPhoneNumber
 * @uses   \OCA\Pipelinq\Service\TicketService
 * @uses   \OCA\Pipelinq\Mcp\McpAnswer
 *
 * @spec openspec/changes/contact-moments-phone-lookup/tasks.md#task-1.4
 */
class PhoneHistoryServiceTest extends TestCase {

	/**
	 * The reads made, as the config handed to findAll.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $reads = [];

	/**
	 * The service over the given stored tickets.
	 *
	 * @param array<int, array<string, mixed>> $tickets The stored tickets.
	 * @param bool                             $fails   Whether every read throws.
	 * @param array<string, string>            $config  App config values.
	 *
	 * @return PhoneHistoryService
	 */
	private function service(array $tickets, bool $fails = false, array $config = ['register' => '5', 'ticket_schema' => '9']): PhoneHistoryService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? ($key === 'default_country' ? 'NL' : $default))
		);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $query = []) use ($tickets, $fails): array {
				$this->reads[] = $query;
				if ($fails === true) {
					throw new RuntimeException('down');
				}

				$wanted = ($query['filters']['phoneE164'] ?? null);
				return array_values(
					array_filter($tickets, static fn (array $t): bool => $wanted === null || ($t['phoneE164'] ?? null) === $wanted)
				);
			}
		);

		$logger  = $this->createMock(LoggerInterface::class);
		$tickets = new TicketService(appConfig: $appConfig, logger: $logger, objectService: $objects, mcp: new McpAnswer(logger: $logger));
		$normaliser = new PhoneNormaliser($appConfig, $logger);

		return new PhoneHistoryService($tickets, $normaliser, new TicketPhoneNumber($normaliser), $logger);
	}//end service()

	/**
	 * However the number is typed, the one ticket stored with it is found, exactly.
	 *
	 * @return void
	 */
	public function testEveryWayOfTypingFindsTheTicket(): void {
		$stored = [
			['id' => 't-1', 'title' => 'Gebeld over afval', 'from_number' => '+31612345678', 'phoneE164' => '+31612345678', 'occurredAt' => '2026-10-01T09:00:00Z'],
			['id' => 't-2', 'title' => 'Iemand anders', 'from_number' => '+31687654321', 'phoneE164' => '+31687654321', 'occurredAt' => '2026-10-02T09:00:00Z'],
		];

		foreach (['06-12345678', '+31 6 1234 5678', '0031612345678', '06 1234 5678'] as $typed) {
			$found = $this->service($stored)->findTickets($typed);
			$this->assertSame('+31612345678', $found['number'], $typed);
			$this->assertSame(['t-1'], array_column($found['tickets'], 'id'), $typed);
			$this->assertSame('exact', $found['tickets'][0]['_phoneMatch'], $typed);
		}
	}//end testEveryWayOfTypingFindsTheTicket()

	/**
	 * A ticket stored before phoneE164 existed is found by its tail, labelled possible, newest first.
	 *
	 * @return void
	 */
	public function testAnOlderTicketIsAPossibleMatchInDateOrder(): void {
		$stored = [
			['id' => 't-1', 'reporterPhone' => '06-12345678', 'occurredAt' => '2026-09-01T09:00:00Z'],
			['id' => 't-2', 'phoneE164' => '+31612345678', 'from_number' => '+31612345678', 'started_at' => '2026-10-05T09:00:00Z'],
			['id' => 't-3', 'reporterPhone' => '+32 612 34 56 78', '@self' => ['created' => '2026-10-09T09:00:00Z']],
			['id' => 't-4', 'reporterPhone' => '0201234567', 'occurredAt' => '2026-10-10T09:00:00Z'],
		];

		$found = $this->service($stored)->findTickets('+31 6 12345678');

		$this->assertSame(['t-3', 't-2', 't-1'], array_column($found['tickets'], 'id'));
		$this->assertSame(['possible', 'exact', 'possible'], array_column($found['tickets'], '_phoneMatch'));
		$this->assertSame(['phoneE164' => '+31612345678'], array_diff_key($this->reads[0]['filters'], ['register' => 1, 'schema' => 1]));
		$this->assertSame(['occurredAt' => 'desc'], $this->reads[0]['sort']);
		$this->assertSame(PhoneHistoryService::MAX_RESULTS, $this->reads[0]['limit']);
		$this->assertSame(PhoneHistoryService::TAIL_SCAN, $this->reads[1]['limit']);
	}//end testAnOlderTicketIsAPossibleMatchInDateOrder()

	/**
	 * No more than MAX_RESULTS tickets are answered.
	 *
	 * @return void
	 */
	public function testTheAnswerIsCapped(): void {
		$stored = [];
		for ($i = 0; $i < 60; $i++) {
			$stored[] = ['id' => 't-'.$i, 'phoneE164' => '+31612345678', 'occurredAt' => sprintf('2026-10-01T09:%02d:00Z', $i)];
		}

		$found = $this->service($stored)->findTickets('0612345678');

		$this->assertCount(PhoneHistoryService::MAX_RESULTS, $found['tickets']);
		$this->assertSame('t-59', $found['tickets'][0]['id']);
	}//end testTheAnswerIsCapped()

	/**
	 * A query that does not normalise, an unconfigured register and a failed read find nothing.
	 *
	 * @return void
	 */
	public function testNothingIsFoundWhenItCannotBe(): void {
		$stored = [['id' => 't-1', 'phoneE164' => '+31612345678']];

		$this->assertSame(['number' => null, 'tickets' => []], $this->service($stored)->findTickets('geen nummer'));
		$this->assertSame([], $this->reads);
		$this->assertSame(['number' => '+31612345678', 'tickets' => []], $this->service($stored, config: [])->findTickets('0612345678'));
		$this->assertSame(['number' => '+31612345678', 'tickets' => []], $this->service($stored, fails: true)->findTickets('0612345678'));
	}//end testNothingIsFoundWhenItCannotBe()
}//end class
