<?php

/**
 * Characterisation tests for CtiService::completeContactmoment().
 *
 * Pins the final metadata written on a contactmoment ticket when a call
 * ends, the default outcome, and the two paths that write nothing.
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
use OCA\Pipelinq\Service\Cti\CallCompletion;
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
 * Tests for completing a contactmoment when a call ends.
 */
class CompleteContactmomentTest extends TestCase {

	/**
	 * Ticket saves, as [ticketType, payload, uuid].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>, 2: string|null}>
	 */
	private array $saves = [];

	/**
	 * Errors logged, as [message, context].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $errors = [];

	/**
	 * The call's final metadata is written on the ticket; an empty outcome
	 * is recorded as resolved.
	 *
	 * @return void
	 */
	public function testTheFinalMetadataIsWritten(): void {
		$service = $this->service(configured: true, saveFails: false);

		$this->complete(service: $service, interactionId: 'cm-1', outcome: '');
		$this->complete(service: $service, interactionId: 'cm-2', outcome: 'callback');

		$payloads = array_map(
			function (array $save): array {
				$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $save[1]['ended_at']);
				unset($save[1]['ended_at']);
				return $save;
			},
			$this->saves
		);
		$this->assertSame(
			[
				[TicketService::TYPE_CONTACTMOMENT, ['duration_seconds' => 95, 'disposition_subject' => 'Billing', 'disposition_outcome' => 'resolved', 'disposition_notes' => 'Paid'], 'cm-1'],
				[TicketService::TYPE_CONTACTMOMENT, ['duration_seconds' => 95, 'disposition_subject' => 'Billing', 'disposition_outcome' => 'callback', 'disposition_notes' => 'Paid'], 'cm-2'],
			],
			$payloads
		);
		$this->assertSame(['duration_seconds', 'ended_at', 'disposition_subject', 'disposition_outcome', 'disposition_notes'], array_keys($this->saves[0][1]));
	}//end testTheFinalMetadataIsWritten()

	/**
	 * Without a ticket store nothing is written or logged.
	 *
	 * @return void
	 */
	public function testWithoutATicketStoreNothingIsWritten(): void {
		$this->complete(service: $this->service(configured: false, saveFails: false), interactionId: 'cm-1', outcome: 'x');

		$this->assertSame([[], []], [$this->saves, $this->errors]);
	}//end testWithoutATicketStoreNothingIsWritten()

	/**
	 * A failing write is logged with the interaction.
	 *
	 * @return void
	 */
	public function testAFailingWriteIsLogged(): void {
		$this->complete(service: $this->service(configured: true, saveFails: true), interactionId: 'cm-9', outcome: 'x');

		$this->assertSame([['CTI completeContactmoment failed', ['exception' => 'store down', 'interactionId' => 'cm-9']]], $this->errors);
	}//end testAFailingWriteIsLogged()

	/**
	 * Complete a call the tests share.
	 *
	 * @param CtiService $service       The service.
	 * @param string     $interactionId The contactmoment.
	 * @param string     $outcome       The disposition outcome.
	 *
	 * @return void
	 */
	private function complete(CtiService $service, string $interactionId, string $outcome): void {
		$service->completeContactmoment($interactionId, new CallCompletion(durationSeconds: 95, outcome: $outcome, subject: 'Billing', notes: 'Paid'));
	}//end complete()

	/**
	 * A real CtiService over recording doubles.
	 *
	 * @param bool $configured Whether the ticket store is configured.
	 * @param bool $saveFails  Whether a save throws.
	 *
	 * @return CtiService
	 */
	private function service(bool $configured, bool $saveFails): CtiService {
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
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->errors[] = [$message, $context];
			}
		);

		return new CtiService(
			$this->createMock(ContainerInterface::class),
			$appConfig,
			$this->createMock(AdapterRegistry::class),
			new PhoneNormaliser($appConfig, $this->createMock(LoggerInterface::class)),
			$this->createMock(CtiContactMatcher::class),
			$this->createMock(CtiDispositionService::class),
			$tickets,
			$logger,
		);
	}//end service()
}//end class
