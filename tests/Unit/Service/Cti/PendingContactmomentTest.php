<?php

/**
 * Characterisation tests for CtiService::createPendingContactmoment().
 *
 * Pins the ticket payload, the number normalisation fallback, the merge of
 * the extra fields and both refusal paths (ticket store not configured,
 * save failure) so the method can be restructured without changing them.
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
 * Tests for the pending contactmoment a CTI call creates.
 */
class PendingContactmomentTest extends TestCase {

	/**
	 * Ticket saves seen, as [ticketType, payload].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $saves = [];

	/**
	 * Log lines seen, as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logs = [];

	/**
	 * An unconfigured ticket store logs a warning, saves nothing and gives null.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredTicketStoreCreatesNothing(): void {
		$service = $this->service(configured: false, saveError: null);

		$this->assertNull($this->createInbound(service: $service));
		$this->assertSame([], $this->saves);
		$this->assertSame([['warning', 'CTI: ticket register/schema not configured.', []]], $this->logs);
	}//end testAnUnconfiguredTicketStoreCreatesNothing()

	/**
	 * The payload carries the normalised numbers and the extra fields win.
	 *
	 * @return void
	 */
	public function testThePayloadCarriesTheNormalisedNumbersAndTheExtras(): void {
		$service = $this->service(configured: true, saveError: null);

		$this->assertSame('cm-1', $this->createInbound(service: $service));
		$this->assertCount(1, $this->saves);
		$this->assertSame(TicketService::TYPE_CONTACTMOMENT, $this->saves[0][0]);
		$payload = $this->saves[0][1];
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $payload['started_at']);
		unset($payload['started_at']);
		$this->assertSame(
			[
				'channel' => 'telephony',
				'direction' => 'inbound',
				'from_number' => '+31612345678',
				'to_number' => 'reception',
				'cti_extension' => '201',
				'assignee' => 'ann',
				'title' => 'CTI inbound call',
				'telephony_platform' => 'asterisk',
				'assignee_note' => 'extra',
			],
			$payload
		);
		$this->assertSame([], $this->logsAt(level: 'error'));
	}//end testThePayloadCarriesTheNormalisedNumbersAndTheExtras()

	/**
	 * An extra field with the same key replaces the built one.
	 *
	 * @return void
	 */
	public function testAnExtraFieldReplacesTheBuiltOne(): void {
		$service = $this->service(configured: true, saveError: null);

		$service->createPendingContactmoment(
			direction: 'outbound',
			fromNumber: '+31201234567',
			toNumber: '0612345678',
			userId: 'bob',
			extension: '7',
			extra: ['title' => 'Custom'],
		);

		$payload = $this->saves[0][1];
		$this->assertSame('outbound', $payload['direction']);
		$this->assertSame('+31201234567', $payload['from_number']);
		$this->assertSame('+31612345678', $payload['to_number']);
		$this->assertSame('Custom', $payload['title']);
		$this->assertSame(
			['channel', 'direction', 'from_number', 'to_number', 'cti_extension', 'assignee', 'started_at', 'title'],
			array_keys($payload)
		);
	}//end testAnExtraFieldReplacesTheBuiltOne()

	/**
	 * A failing save is logged as an error and gives null.
	 *
	 * @return void
	 */
	public function testAFailingSaveIsLoggedAndGivesNull(): void {
		$service = $this->service(configured: true, saveError: 'store down');

		$this->assertNull($this->createInbound(service: $service));
		$this->assertSame(
			[['error', 'CTI createPendingContactmoment failed', ['exception' => 'store down']]],
			$this->logsAt(level: 'error')
		);
	}//end testAFailingSaveIsLoggedAndGivesNull()

	/**
	 * Create the inbound contactmoment the tests share.
	 *
	 * @param CtiService $service The service.
	 *
	 * @return string|null The new id.
	 */
	private function createInbound(CtiService $service): ?string {
		return $service->createPendingContactmoment(
			direction: 'inbound',
			fromNumber: '06 1234 5678',
			toNumber: 'reception',
			userId: 'ann',
			extension: '201',
			extra: ['telephony_platform' => 'asterisk', 'assignee_note' => 'extra'],
		);
	}//end createInbound()

	/**
	 * The logged lines of one level.
	 *
	 * @param string $level The level.
	 *
	 * @return array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private function logsAt(string $level): array {
		return array_values(array_filter($this->logs, static fn (array $log): bool => $log[0] === $level));
	}//end logsAt()

	/**
	 * A CtiService over a recording ticket store and logger.
	 *
	 * @param bool        $configured Whether the ticket store is configured.
	 * @param string|null $saveError  The message a save throws, or null to save.
	 *
	 * @return CtiService
	 */
	private function service(bool $configured, ?string $saveError): CtiService {
		$tickets = $this->createMock(TicketService::class);
		$tickets->method('isConfigured')->willReturn($configured);
		$tickets->method('save')->willReturnCallback(
			function (string $ticketType, array $payload) use ($saveError): object {
				if ($saveError !== null) {
					throw new RuntimeException($saveError);
				}

				$this->saves[] = [$ticketType, $payload];
				return new class {
					/**
					 * The saved id.
					 *
					 * @return string
					 */
					public function getUuid(): string {
						return 'cm-1';
					}//end getUuid()
				};
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (['default_country_code' => '+31'][$key] ?? $default)
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

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
