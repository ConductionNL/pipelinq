<?php

/**
 * Behavioural tests for the ConfiguredRegisterId trait.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Util
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Util;

use OCA\Pipelinq\Util\ConfiguredRegisterId;
use OCA\Pipelinq\Util\LoggedRegisterSchemaIds;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The configured register id is returned; an empty one is logged.
 *
 * @covers \OCA\Pipelinq\Util\ConfiguredRegisterId
 */
class ConfiguredRegisterIdTest extends TestCase {

	/**
	 * Build a host over app config that answers the given values.
	 *
	 * @param array<string, string> $values    Config values by key.
	 * @param LoggerInterface       $logger    The logger.
	 * @param IAppConfig            $appConfig The app config mock to answer from.
	 *
	 * @return object The host.
	 */
	public static function host(array $values, LoggerInterface $logger, IAppConfig $appConfig): object {
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => ($values[$key] ?? $default)
		);

		return new class($appConfig, $logger) {
			use ConfiguredRegisterId;
			use LoggedRegisterSchemaIds;

			/**
			 * @param IAppConfig      $appConfig The app config.
			 * @param LoggerInterface $logger    The logger.
			 */
			public function __construct(private IAppConfig $appConfig, private LoggerInterface $logger) {
			}

			/**
			 * @return string
			 */
			public function register(): string {
				return $this->registerId();
			}

			/**
			 * @param string $key The schema key.
			 * @return array{0: string, 1: string}
			 */
			public function ids(string $key): array {
				return $this->config(schemaKey: $key);
			}
		};
	}//end host()

	/**
	 * @return void
	 */
	public function testConfiguredRegisterIsReturnedSilently(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$host = self::host(['register' => 'r1'], $logger, $this->createMock(IAppConfig::class));
		$this->assertSame('r1', $host->register());
	}//end testConfiguredRegisterIsReturnedSilently()

	/**
	 * @return void
	 */
	public function testEmptyRegisterIsLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('Pipelinq: app-config "register" is not configured; OpenRegister calls are refused, not run unscoped');

		$host = self::host([], $logger, $this->createMock(IAppConfig::class));
		$this->assertSame('', $host->register());
	}//end testEmptyRegisterIsLogged()
}//end class
