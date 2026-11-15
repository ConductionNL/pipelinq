<?php

/**
 * Behavioural tests for the ConfiguredWebhookToken trait.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Cti\Adapter\Support
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

namespace OCA\Pipelinq\Tests\Unit\Service\Cti\Adapter\Support;

use OCA\Pipelinq\Service\Cti\Adapter\Support\ConfiguredWebhookToken;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Matching, mismatching and empty tokens.
 *
 * @covers \OCA\Pipelinq\Service\Cti\Adapter\Support\ConfiguredWebhookToken
 */
class ConfiguredWebhookTokenTest extends TestCase {

	/**
	 * @return void
	 */
	public function testMatchesConfiguredToken(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => ($key === 'tok' ? 's3cret' : $default)
		);
		$host = new class($appConfig) {
			use ConfiguredWebhookToken;

			/**
			 * @param IAppConfig $appConfig The app config.
			 */
			public function __construct(private IAppConfig $appConfig) {
			}

			/**
			 * @param string $key       The config key.
			 * @param string $signature The token.
			 * @return bool
			 */
			public function check(string $key, string $signature): bool {
				return $this->matchesConfiguredToken(configKey: $key, signature: $signature);
			}
		};

		$this->assertTrue($host->check('tok', 's3cret'));
		$this->assertFalse($host->check('tok', 'other'));
		$this->assertFalse($host->check('tok', ''));
		$this->assertFalse($host->check('unset', 's3cret'));
	}//end testMatchesConfiguredToken()
}//end class
