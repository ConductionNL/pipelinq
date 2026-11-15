<?php

/**
 * Behavioural tests for the ConfiguredBlastDeliverySchemaSlug trait.
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

use OCA\Pipelinq\Util\ConfiguredBlastDeliverySchemaSlug;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * A configured slug wins; an empty value falls back to the host's default.
 *
 * @covers \OCA\Pipelinq\Util\ConfiguredBlastDeliverySchemaSlug
 */
class ConfiguredBlastDeliverySchemaSlugTest extends TestCase {

	/**
	 * Build a host whose app config answers the given value.
	 *
	 * @param string $value The configured value.
	 *
	 * @return object The host.
	 */
	private function host(string $value): object {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => ($app === 'pipelinq' ? $value : 'wrong-app')
		);

		return new class($appConfig) {
			use ConfiguredBlastDeliverySchemaSlug;

			private const DEFAULT_BLAST_DELIVERY_SCHEMA_SLUG = 'default-delivery';

			/**
			 * @param IAppConfig $appConfig The app config.
			 */
			public function __construct(private IAppConfig $appConfig) {
			}

			/**
			 * @return string
			 */
			public function read(): string {
				return $this->getBlastDeliverySchemaSlug();
			}
		};
	}//end host()

	/**
	 * @return void
	 */
	public function testConfiguredValueWins(): void {
		$this->assertSame('custom', $this->host(value: 'custom')->read());
	}//end testConfiguredValueWins()

	/**
	 * @return void
	 */
	public function testEmptyValueFallsBackToDefault(): void {
		$this->assertSame('default-delivery', $this->host(value: '')->read());
	}//end testEmptyValueFallsBackToDefault()
}//end class
