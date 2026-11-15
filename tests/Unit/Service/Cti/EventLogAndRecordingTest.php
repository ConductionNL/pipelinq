<?php

/**
 * Characterisation tests for CtiService::attachRecording() and listEventLog().
 *
 * Pins whether a recording write is reported as done, and the event log
 * query (filters, paging, order) and its failure paths, so both methods
 * can be restructured without changing them.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Cti
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Cti;

use OCA\Pipelinq\Service\Cti\AdapterRegistry;
use OCA\Pipelinq\Service\CtiContactMatcher;
use OCA\Pipelinq\Service\CtiDispositionService;
use OCA\Pipelinq\Service\CtiService;
use OCA\Pipelinq\Service\PhoneNormaliser;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the recording attachment and the event log listing.
 */
class EventLogAndRecordingTest extends TestCase {

	/**
	 * Ticket saves, as [ticketType, payload, uuid].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>, 2: string|null}>
	 */
	private array $saves = [];

	/**
	 * Event log queries.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * Log lines, as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logs = [];

	/**
	 * What findAll answers; a string is the message it throws.
	 *
	 * @var mixed
	 */
	private mixed $rows = [];

	/**
	 * A recording is written on the contactmoment and reported as written.
	 *
	 * @return void
	 */
	public function testARecordingIsWrittenAndReported(): void {
		$this->assertTrue($this->service(configured: true)->attachRecording('cm-1', 'https://rec/1', '2027-01-01T00:00:00Z'));
		$this->assertSame(
			[[TicketService::TYPE_CONTACTMOMENT, ['recording_url' => 'https://rec/1', 'recording_retention_expires_at' => '2027-01-01T00:00:00Z'], 'cm-1']],
			$this->saves
		);
		$this->assertSame([], $this->logs);
	}//end testARecordingIsWrittenAndReported()

	/**
	 * Without a ticket store nothing is written and false is reported.
	 *
	 * @return void
	 */
	public function testWithoutATicketStoreARecordingIsNotWritten(): void {
		$this->assertFalse($this->service(configured: false)->attachRecording('cm-1', 'https://rec/1', 'x'));
		$this->assertSame([], $this->saves);
		$this->assertSame([['warning', 'CTI attachRecording: ticket store is not configured', ['interactionId' => 'cm-1']]], $this->logs);
	}//end testWithoutATicketStoreARecordingIsNotWritten()

	/**
	 * A failing write is logged and reported as not written.
	 *
	 * @return void
	 */
	public function testAFailingRecordingWriteIsReported(): void {
		$this->assertFalse($this->service(configured: true, saveFails: true)->attachRecording('cm-2', 'https://rec/2', 'x'));
		$this->assertSame([['error', 'CTI attachRecording failed', ['exception' => 'store down', 'interactionId' => 'cm-2']]], $this->logs);
	}//end testAFailingRecordingWriteIsReported()

	/**
	 * The event log is read newest first with the non-empty filters and the page.
	 *
	 * @return void
	 */
	public function testTheEventLogQuery(): void {
		$this->rows = [['id' => 'e-1'], ['id' => 'e-2']];

		$rows = $this->service(configured: true)->listEventLog(['platform' => 'asterisk', 'event_type' => '', 'external_call_id' => null, 'signature_valid' => false], 10, 20);

		$this->assertSame([['id' => 'e-1'], ['id' => 'e-2']], $rows);
		$this->assertSame(
			[[
				'filters' => ['register' => 'reg-1', 'schema' => 'log-1', 'platform' => 'asterisk', 'signature_valid' => false],
				'limit' => 10,
				'offset' => 20,
				'sort' => ['received_at' => 'desc'],
			]],
			$this->queries
		);

		$this->service(configured: true)->listEventLog();
		$this->assertSame([['register' => 'reg-1', 'schema' => 'log-1'], 50, 0], [$this->queries[1]['filters'], $this->queries[1]['limit'], $this->queries[1]['offset']]);
	}//end testTheEventLogQuery()

	/**
	 * Without an event log schema, a non-list answer or a failing read the
	 * listing is empty; a failure is logged.
	 *
	 * @return void
	 */
	public function testTheEventLogIsEmptyWhenItCannotBeRead(): void {
		$this->assertSame([], $this->service(configured: true, logSchema: '')->listEventLog());
		$this->assertSame([], $this->queries);

		$this->rows = 'not a list';
		$this->assertSame([], $this->service(configured: true)->listEventLog());

		$this->rows = null;
		$this->logs = [];
		$this->assertSame([], $this->service(configured: true, findFails: true)->listEventLog());
		$this->assertSame([['warning', 'CTI listEventLog failed', ['exception' => 'log down']]], $this->logs);
	}//end testTheEventLogIsEmptyWhenItCannotBeRead()

	/**
	 * A real CtiService over recording doubles.
	 *
	 * @param bool   $configured Whether the ticket store is configured.
	 * @param bool   $saveFails  Whether a ticket save throws.
	 * @param string $logSchema  The event log schema.
	 * @param bool   $findFails  Whether the event log read throws.
	 *
	 * @return CtiService
	 */
	private function service(bool $configured, bool $saveFails = false, string $logSchema = 'log-1', bool $findFails = false): CtiService {
		$tickets = $this->createMock(TicketService::class);
		$tickets->method('isConfigured')->willReturn($configured);
		$tickets->method('save')->willReturnCallback(
			function (string $ticketType, array $payload, ?string $uuid = null) use ($saveFails): object {
				if ($saveFails === true) {
					throw new RuntimeException('store down');
				}

				$this->saves[] = [$ticketType, $payload, $uuid];
				return new \stdClass();
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (['register' => 'reg-1', 'ctiEventLog_schema' => $logSchema][$key] ?? $default)
		);

		$log = new class ($this, $findFails) {
			/**
			 * Constructor.
			 *
			 * @param EventLogAndRecordingTest $test  The test recording the queries.
			 * @param bool                     $fails Whether a read throws.
			 */
			public function __construct(private EventLogAndRecordingTest $test, private bool $fails) {
			}

			/**
			 * Read the event log.
			 *
			 * @param array<string, mixed> $config The query.
			 *
			 * @return mixed
			 */
			public function findAll(array $config): mixed {
				if ($this->fails === true) {
					throw new RuntimeException('log down');
				}

				return $this->test->answer(config: $config);
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($log);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		return new CtiService(
			$container,
			$appConfig,
			$this->createMock(AdapterRegistry::class),
			new PhoneNormaliser($appConfig, $this->createMock(LoggerInterface::class)),
			$this->createMock(CtiContactMatcher::class),
			$this->createMock(CtiDispositionService::class),
			$tickets,
			$logger,
		);
	}//end service()

	/**
	 * Record an event log query and answer it.
	 *
	 * @param array<string, mixed> $config The query.
	 *
	 * @return mixed The rows.
	 */
	public function answer(array $config): mixed {
		$this->queries[] = $config;
		return $this->rows;
	}//end answer()
}//end class
