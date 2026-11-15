<?php

/**
 * Behavioural tests for the PosRegisterSchemaIds trait.
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

use OCA\Pipelinq\Util\PosRegisterSchemaIds;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Both ids are returned; a missing one throws an OCS not-found error.
 *
 * @covers \OCA\Pipelinq\Util\PosRegisterSchemaIds
 */
class PosRegisterSchemaIdsTest extends TestCase {

	/**
	 * Build a host over the given config values.
	 *
	 * @param array<string, string> $values Config values by key.
	 *
	 * @return object The host.
	 */
	private function host(array $values): object {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => ($values[$key] ?? $default)
		);

		return new class($appConfig) {
			use PosRegisterSchemaIds;

			/**
			 * @param IAppConfig $appConfig The app config.
			 */
			public function __construct(private IAppConfig $appConfig) {
			}

			/**
			 * @return array{0: string, 1: string}
			 */
			public function ids(): array {
				return $this->config(schemaKey: 'posRole_schema');
			}
		};
	}//end host()

	/**
	 * @return void
	 */
	public function testBothConfigured(): void {
		$this->assertSame(['r', 's'], $this->host(['register' => 'r', 'posRole_schema' => 's'])->ids());
	}//end testBothConfigured()

	/**
	 * @return void
	 */
	public function testMissingSchemaThrows(): void {
		$this->expectException(OCSNotFoundException::class);
		$this->expectExceptionMessage('POS register of schema is niet geconfigureerd.');
		$this->host(['register' => 'r'])->ids();
	}//end testMissingSchemaThrows()
}//end class
