<?php

/**
 * Behavioural tests for the ConfiguredTenantId trait.
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

use OCA\Pipelinq\Util\ConfiguredTenantId;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The configured tenant id wins; an empty one is 'default'.
 *
 * @covers \OCA\Pipelinq\Util\ConfiguredTenantId
 */
class ConfiguredTenantIdTest extends TestCase {

	/**
	 * @return void
	 */
	public function testTenantId(): void {
		foreach (['t1' => 't1', '' => 'default'] as $configured => $expected) {
			$appConfig = $this->createStub(IAppConfig::class);
			$appConfig->method('getValueString')->willReturn((string)$configured);
			$host = new class($appConfig) {
				use ConfiguredTenantId;

				/**
				 * @param IAppConfig $appConfig The app config.
				 */
				public function __construct(private IAppConfig $appConfig) {
				}

				/**
				 * @return string
				 */
				public function read(): string {
					return $this->getTenantId();
				}
			};

			$this->assertSame($expected, $host->read());
		}
	}//end testTenantId()
}//end class
