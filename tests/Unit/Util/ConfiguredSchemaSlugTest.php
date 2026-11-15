<?php

/**
 * Behavioural tests for the ConfiguredSchemaSlug trait.
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

use OCA\Pipelinq\Util\ConfiguredSchemaSlug;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * A configured slug wins; an empty one falls back to the given default.
 *
 * @covers \OCA\Pipelinq\Util\ConfiguredSchemaSlug
 */
class ConfiguredSchemaSlugTest extends TestCase {

	/**
	 * @return void
	 */
	public function testResolveSchemaSlug(): void {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => ($key === 'set_schema' ? 'custom' : '')
		);
		$host = new class($appConfig) {
			use ConfiguredSchemaSlug;

			/**
			 * @param IAppConfig $appConfig The app config.
			 */
			public function __construct(private IAppConfig $appConfig) {
			}

			/**
			 * @param string $key     The key.
			 * @param string $default The default.
			 * @return string
			 */
			public function read(string $key, string $default): string {
				return $this->resolveSchemaSlug(key: $key, default: $default);
			}
		};

		$this->assertSame('custom', $host->read('set_schema', 'fallback'));
		$this->assertSame('fallback', $host->read('unset_schema', 'fallback'));
	}//end testResolveSchemaSlug()
}//end class
