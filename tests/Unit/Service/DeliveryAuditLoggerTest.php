<?php

/**
 * Unit tests for DeliveryAuditLogger — append-only inserts, retention
 * calculation, payload hash, all eight event types.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-audit-009
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\DeliveryAuditLogger;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for DeliveryAuditLogger.
 */
class DeliveryAuditLoggerTest extends TestCase {
	/**
	 * Captured saveObject payloads.
	 *
	 * @var array<int, array>
	 */
	private array $captured = [];

	/**
	 * Build the logger with a captured ObjectService::saveObject.
	 *
	 * @return DeliveryAuditLogger
	 */
	private function buildLogger(): DeliveryAuditLogger {
		$this->captured = [];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(
			function (...$args) {
				foreach ($args as $arg) {
					if (is_array($arg) === true && isset($arg['event']) === true) {
						$this->captured[] = $arg;
						break;
					}
				}
				return $this->captured[(count($this->captured) - 1)] ?? [];
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				return match ($key) {
					'register' => 'reg-1',
					'deliveryAuditLog_schema' => 'sch-audit',
					'selectielijst.paspoort' => '20',
					default => $default,
				};
			}
		);

		return new DeliveryAuditLogger(
			$container,
			$appConfig,
			$this->createMock(LoggerInterface::class)
		);
	}//end buildLogger()

	/**
	 * logQueued inserts a row with event=queued.
	 *
	 * @return void
	 */
	public function testLogQueued(): void {
		$logger = $this->buildLogger();
		$logger->logQueued('msg-1', 'hash-1');
		$this->assertCount(1, $this->captured);
		$this->assertSame('queued', $this->captured[0]['event']);
		$this->assertSame('msg-1', $this->captured[0]['messageId']);
		$this->assertSame('system', $this->captured[0]['actor']);
	}//end testLogQueued()

	/**
	 * logSent carries the logiusMessageId.
	 *
	 * @return void
	 */
	public function testLogSentCarriesLogiusId(): void {
		$logger = $this->buildLogger();
		$logger->logSent('msg-1', 'logius-42', 'hash');
		$this->assertSame('sent', $this->captured[0]['event']);
		$this->assertSame('logius-42', $this->captured[0]['logiusMessageId']);
	}//end testLogSentCarriesLogiusId()

	/**
	 * logFallback carries reason.
	 *
	 * @return void
	 */
	public function testLogFallbackCarriesReason(): void {
		$logger = $this->buildLogger();
		$logger->logFallback('msg-1', '5-day-unread', 'hash');
		$this->assertSame('fallback', $this->captured[0]['event']);
		$this->assertSame('5-day-unread', $this->captured[0]['reason']);
	}//end testLogFallbackCarriesReason()

	/**
	 * logFailed, logRead, logReplyReceived, logOptedOut, logProcessingError
	 * all produce the right event tags.
	 *
	 * @return void
	 */
	public function testAllEventTags(): void {
		$logger = $this->buildLogger();
		$logger->logFailed('m', 'reason', 'h');
		$logger->logRead('m', 'h');
		$logger->logReplyReceived('r', 'h');
		$logger->logOptedOut('m', 'h');
		$logger->logProcessingError('m', 'err', 'h');

		$events = array_column($this->captured, 'event');
		$this->assertSame(['failed', 'read', 'reply-received', 'opted-out', 'processing-error'], $events);
	}//end testAllEventTags()

	/**
	 * calculateRetentionUntil reads tenant config for the zaaktype.
	 *
	 * @return void
	 */
	public function testRetentionUsesSelectielijstConfig(): void {
		$logger = $this->buildLogger();
		$from = new DateTimeImmutable('2026-01-01');
		$until = $logger->calculateRetentionUntil('paspoort', $from);
		$this->assertSame('2046-01-01', $until->format('Y-m-d'));
	}//end testRetentionUsesSelectielijstConfig()

	/**
	 * Default retention is 10 years when no config is set.
	 *
	 * @return void
	 */
	public function testRetentionDefault(): void {
		$logger = $this->buildLogger();
		$from = new DateTimeImmutable('2026-01-01');
		$until = $logger->calculateRetentionUntil('unknown', $from);
		$this->assertSame('2036-01-01', $until->format('Y-m-d'));
	}//end testRetentionDefault()

	/**
	 * hashPayload returns a 64-char SHA-256 hex.
	 *
	 * @return void
	 */
	public function testHashPayload(): void {
		$logger = $this->buildLogger();
		$h = $logger->hashPayload('<p>body</p>');
		$this->assertSame(64, strlen($h));
	}//end testHashPayload()
}//end class
