<?php

/**
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\NotesServiceInterface;
use OCA\Pipelinq\Service\ClientHistorySummaryService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A client's history can be summarised on request (REQ-CIN-002).
 *
 * @covers \OCA\Pipelinq\Service\ClientHistorySummaryService
 *
 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-2.1
 */
class ClientHistorySummaryServiceTest extends TestCase {

	/**
	 * The moment every test judges at.
	 */
	private const NOW = '2026-11-13T12:00:00+00:00';

	/**
	 * Rows the viewer may read, per schema.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * The client's notes.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $notes = [];

	/**
	 * The reads made, as [config, rbac, multitenancy].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $reads = [];

	/**
	 * The prompts hermiq got, as [prompt, userId].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $prompts = [];

	/**
	 * The service.
	 *
	 * @param bool        $hermiq    Whether hermiq is installed.
	 * @param string|null $answer    What hermiq answers; null makes it throw.
	 * @param bool        $readFails Whether every OpenRegister read throws.
	 *
	 * @return ClientHistorySummaryService
	 */
	private function service(bool $hermiq = true, ?string $answer = 'They called twice about an invoice.', bool $readFails = false): ClientHistorySummaryService {
		$config    = ['register' => 'r', 'ticket_schema' => 'ticket', 'lead_schema' => 'lead'];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use ($readFails): array {
				$this->reads[] = [$config, $_rbac, $_multitenancy];
				if ($readFails === true) {
					throw new RuntimeException('down');
				}

				$filters = $config['filters'];
				return array_values(array_filter(
					($this->rows[$filters['schema']] ?? []),
					static fn (array $row): bool => ($row['client'] ?? null) === $filters['client']
				));
			}
		);

		$notes = $this->createMock(NotesServiceInterface::class);
		$notes->method('getNotes')->willReturnCallback(
			function (string $objectType, string $objectId): array {
				$this->assertSame('pipelinq_client', $objectType);
				$this->assertSame('client-1', $objectId);
				return $this->notes;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		if ($hermiq === true) {
			$prompts  = &$this->prompts;
			$provider = new class($answer, $prompts) {
				/**
				 * @param string|null              $answer  The answer.
				 * @param array<int,array<int,mixed>> $prompts The prompts seen.
				 */
				public function __construct(private ?string $answer, private array &$prompts) {
				}

				/**
				 * @param string      $prompt         The prompt.
				 * @param string|null $userId         The user.
				 * @param bool        $allowNextcloud Unused.
				 * @param string|null $organisation   Unused.
				 *
				 * @return string
				 */
				public function generateText(string $prompt, ?string $userId = null, bool $allowNextcloud = true, ?string $organisation = null): string {
					$this->prompts[] = [$prompt, $userId];
					if ($this->answer === null) {
						throw new RuntimeException('no provider configured');
					}

					return $this->answer;
				}
			};
			$container->method('get')->with(ClientHistorySummaryService::PROVIDER_CLASS)->willReturn($provider);
		} else {
			$container->method('get')->willThrowException(new class('absent') extends RuntimeException implements NotFoundExceptionInterface {
			});
		}

		return new ClientHistorySummaryService($objects, $notes, $appConfig, $container, $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * A ticket row.
	 *
	 * @param string $title     The title.
	 * @param string $date      When it occurred.
	 * @param string $type      The ticket type.
	 *
	 * @return array<string,mixed>
	 */
	private function ticket(string $title, string $date, string $type = 'interaction'): array {
		return ['id' => $title, 'client' => 'client-1', 'title' => $title, 'ticketType' => $type, 'status' => 'closed', 'occurredAt' => $date];
	}//end ticket()

	/**
	 * Without hermiq nothing is read and summarising is not available.
	 *
	 * @return void
	 */
	public function testWithoutHermiqNothingIsReadAndSummarisingIsNotAvailable(): void {
		$this->rows['ticket'] = [$this->ticket('Call', '2026-11-10T09:00:00+00:00')];
		$service = $this->service(hermiq: false);

		$this->assertFalse($service->available());
		$this->assertSame(
			['available' => false, 'summary' => null, 'recordsRead' => 0, 'generatedAt' => null],
			$service->summarise('client-1', 'anna', new DateTimeImmutable(self::NOW))
		);
		$this->assertSame([], $this->reads);
	}//end testWithoutHermiqNothingIsReadAndSummarisingIsNotAvailable()

	/**
	 * With hermiq the records of the window go in newest first, and the answer comes back with the count and time.
	 *
	 * @return void
	 */
	public function testSummaryIsWrittenFromTheWindowNewestFirst(): void {
		$this->rows['ticket'] = [
			$this->ticket('Old call', '2026-07-01T09:00:00+00:00'),
			$this->ticket('Invoice question', '2026-11-01T09:00:00+00:00'),
			$this->ticket('Broken delivery', '2026-11-12T09:00:00+00:00', 'complaint'),
			$this->ticket('Someone else', '2026-11-12T09:00:00+00:00'),
		];
		$this->rows['ticket'][3]['client'] = 'client-2';
		$this->rows['lead']                = [['id' => 'l1', 'client' => 'client-1', 'title' => 'Renewal', 'status' => 'open', 'value' => 1200, '@self' => ['updated' => '2026-11-05T10:00:00+00:00']]];
		$this->notes = [['message' => "Prefers\nmail", 'timestamp' => '2026-11-08T10:00:00+00:00']];

		$service = $this->service();
		$this->assertTrue($service->available());
		$result = $service->summarise('client-1', 'anna', new DateTimeImmutable(self::NOW));

		$this->assertSame(
			['available' => true, 'summary' => 'They called twice about an invoice.', 'recordsRead' => 4, 'generatedAt' => self::NOW],
			$result
		);
		$this->assertCount(1, $this->prompts);
		[$prompt, $user] = $this->prompts[0];
		$this->assertSame('anna', $user);
		$this->assertStringNotContainsString('Old call', $prompt);
		$this->assertStringNotContainsString('Someone else', $prompt);

		$lines = array_slice(explode("\n", $prompt), 3);
		$this->assertSame(
			[
				'2026-11-12 Complaint: Broken delivery; status closed',
				'2026-11-08 Note: Prefers mail',
				'2026-11-05 Lead: Renewal; status open; value 1200',
				'2026-11-01 Contact moment: Invoice question; status closed',
			],
			$lines
		);
	}//end testSummaryIsWrittenFromTheWindowNewestFirst()

	/**
	 * Every read runs as the viewer, so a record they may not read never reaches the model.
	 *
	 * @return void
	 */
	public function testRecordsAreReadAsTheViewer(): void {
		$this->rows['ticket'] = [$this->ticket('Call', '2026-11-10T09:00:00+00:00')];
		$this->service()->summarise('client-1', 'anna', new DateTimeImmutable(self::NOW));

		$this->assertCount(2, $this->reads);
		foreach ($this->reads as [$config, $rbac, $multitenancy]) {
			$this->assertTrue($rbac);
			$this->assertTrue($multitenancy);
			$this->assertSame('client-1', $config['filters']['client']);
			$this->assertSame('r', $config['filters']['register']);
		}

		$this->assertSame(['ticket', 'lead'], array_map(static fn (array $read): string => $read[0]['filters']['schema'], $this->reads));
	}//end testRecordsAreReadAsTheViewer()

	/**
	 * At most forty records go in, the newest.
	 *
	 * @return void
	 */
	public function testInputIsBoundedToTheNewestForty(): void {
		for ($day = 1; $day <= 50; $day++) {
			$date = (new DateTimeImmutable(self::NOW))->modify('-' . $day . ' days')->format(DATE_ATOM);
			$this->rows['ticket'][] = $this->ticket('Call ' . $day, $date);
		}

		$result = $this->service()->summarise('client-1', 'anna', new DateTimeImmutable(self::NOW));

		$this->assertSame(40, $result['recordsRead']);
		$this->assertStringContainsString('Call 40;', $this->prompts[0][0]);
		$this->assertStringNotContainsString('Call 41;', $this->prompts[0][0]);
	}//end testInputIsBoundedToTheNewestForty()

	/**
	 * With nothing to read hermiq is not asked.
	 *
	 * @return void
	 */
	public function testNothingToReadAsksNothing(): void {
		$result = $this->service()->summarise('client-1', 'anna', new DateTimeImmutable(self::NOW));

		$this->assertSame(['available' => true, 'summary' => null, 'recordsRead' => 0, 'generatedAt' => self::NOW], $result);
		$this->assertSame([], $this->prompts);
	}//end testNothingToReadAsksNothing()

	/**
	 * A silent hermiq leaves the summary empty but still says what was read.
	 *
	 * @return void
	 */
	public function testSilentHermiqLeavesNoSummary(): void {
		$this->rows['ticket'] = [$this->ticket('Call', '2026-11-10T09:00:00+00:00')];

		$thrown = $this->service(answer: null)->summarise('client-1', 'anna', new DateTimeImmutable(self::NOW));
		$blank  = $this->service(answer: "  \n")->summarise('client-1', 'anna', new DateTimeImmutable(self::NOW));

		$this->assertNull($thrown['summary']);
		$this->assertSame(1, $thrown['recordsRead']);
		$this->assertNull($blank['summary']);
	}//end testSilentHermiqLeavesNoSummary()

	/**
	 * A failed read counts as nothing read rather than an error.
	 *
	 * @return void
	 */
	public function testAFailedReadCountsAsNothing(): void {
		$this->notes = [['message' => 'Called back', 'timestamp' => '2026-11-10T09:00:00+00:00']];

		$result = $this->service(readFails: true)->summarise('client-1', 'anna', new DateTimeImmutable(self::NOW));

		$this->assertSame(1, $result['recordsRead']);
		$this->assertSame('They called twice about an invoice.', $result['summary']);
	}//end testAFailedReadCountsAsNothing()
}//end class
