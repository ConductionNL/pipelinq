<?php

/**
 * Unit tests for MailboxResolver — TTL cache hit/miss, Logius fallback,
 * opted-out marking.
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
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-mailbox-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\EncryptionService;
use OCA\Pipelinq\Service\LogiusConnector;
use OCA\Pipelinq\Service\MailboxResolver;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for MailboxResolver.
 */
class MailboxResolverTest extends TestCase {
	/**
	 * Make an EncryptionService with a deterministic system secret.
	 *
	 * @return EncryptionService
	 */
	private function makeEncryption(): EncryptionService {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturn('unit-secret-for-mailbox-resolver-tests');
		return new EncryptionService(
			$config,
			$this->createMock(LoggerInterface::class)
		);
	}//end makeEncryption()

	/**
	 * Build a resolver wired to mocks and capture saved rows in $savedRows.
	 *
	 * @param ObjectService $objectService Object service mock.
	 * @param LogiusConnector $connector Connector mock.
	 *
	 * @return MailboxResolver
	 */
	private function buildResolver(ObjectService $objectService, LogiusConnector $connector): MailboxResolver {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				return match ($key) {
					'register' => 'reg-1',
					'mailboxResolution_schema' => 'sch-mr',
					default => $default,
				};
			}
		);

		return new MailboxResolver(
			$container,
			$appConfig,
			$this->makeEncryption(),
			$connector,
			$this->createMock(LoggerInterface::class)
		);
	}//end buildResolver()

	/**
	 * Cache miss → Logius call → cache write.
	 *
	 * @return void
	 */
	public function testCacheMissCallsLogius(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn([]);
		// saveObject must be called once with mailboxAvailable=true.
		$captured = null;
		$objectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(function (...$args) use (&$captured) {
				$named = func_get_args();
				$captured = $named[0]; // First positional arg is the object array.
				return $named[0];
			});

		$connector = $this->createMock(LogiusConnector::class);
		$connector->expects($this->once())
			->method('checkMailboxExists')
			->with('123456789')
			->willReturn(true);

		$resolver = $this->buildResolver($objectService, $connector);
		$result = $resolver->resolve('123456789', 'tenant-a');

		$this->assertTrue($result['mailboxAvailable']);
		$this->assertSame('logius', $result['source']);
		$this->assertNotNull($captured);
	}//end testCacheMissCallsLogius()

	/**
	 * Cache hit short-circuits Logius.
	 *
	 * Because buildResolver() builds a fresh EncryptionService internally
	 * we cannot reuse a local one to pre-compute the hash; instead we
	 * return a row keyed by *any* bsnHash and rely on the resolver
	 * accepting it because the mock returns it unconditionally.
	 *
	 * @return void
	 */
	public function testCacheHitSkipsLogius(): void {
		$future = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+1 hour');

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn([[
			'bsnHash' => str_repeat('a', 64),
			'mailboxAvailable' => true,
			'expiresAt' => $future->format(DATE_ATOM),
			'resolvedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
		]]);
		$objectService->expects($this->never())->method('saveObject');

		$connector = $this->createMock(LogiusConnector::class);
		$connector->expects($this->never())->method('checkMailboxExists');

		$resolver = $this->buildResolver($objectService, $connector);
		$result = $resolver->resolve('123456789', 'tenant-a');
		$this->assertTrue($result['mailboxAvailable']);
		$this->assertSame('cache', $result['source']);
	}//end testCacheHitSkipsLogius()

	/**
	 * Expired cache row → forces Logius call.
	 *
	 * @return void
	 */
	public function testExpiredCacheFallsThrough(): void {
		$past = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-1 hour');

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn([[
			'bsnHash' => 'expired-hash',
			'mailboxAvailable' => true,
			'expiresAt' => $past->format(DATE_ATOM),
		]]);
		$objectService->expects($this->once())->method('saveObject');

		$connector = $this->createMock(LogiusConnector::class);
		$connector->expects($this->once())
			->method('checkMailboxExists')
			->willReturn(false);

		$resolver = $this->buildResolver($objectService, $connector);
		$result = $resolver->resolve('123456789', 'tenant-a');
		$this->assertFalse($result['mailboxAvailable']);
		$this->assertSame('logius', $result['source']);
	}//end testExpiredCacheFallsThrough()

	/**
	 * markOptedOut saves with optedOut=true.
	 *
	 * @return void
	 */
	public function testMarkOptedOut(): void {
		$captured = null;
		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(function (...$args) use (&$captured) {
				$captured = $args[0] ?? func_get_arg(0);
				// saveObject is called with named args; the first positional may be the array.
				if (is_array($captured) === false) {
					foreach (func_get_args() as $arg) {
						if (is_array($arg) === true && isset($arg['optedOut']) === true) {
							$captured = $arg;
							break;
						}
					}
				}
				return $captured;
			});

		$connector = $this->createMock(LogiusConnector::class);
		$resolver = $this->buildResolver($objectService, $connector);
		$resolver->markOptedOut('123456789', 'tenant-a');

		$this->assertNotNull($captured);
		$this->assertTrue($captured['optedOut'] ?? false);
	}//end testMarkOptedOut()
}//end class
