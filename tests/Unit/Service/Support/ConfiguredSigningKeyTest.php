<?php

/**
 * Behavioural tests for the ConfiguredSigningKey trait.
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

namespace OCA\Pipelinq\Tests\Unit\Service\Support;

use OCA\Pipelinq\Service\Support\ConfiguredSigningKey;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * A stored key is reused; a missing one is generated and stored as sensitive.
 *
 * @covers \OCA\Pipelinq\Service\Support\ConfiguredSigningKey
 */
class ConfiguredSigningKeyTest extends TestCase {

	/**
	 * Build a host over the given config and random source.
	 *
	 * @param IAppConfig    $appConfig    The app config.
	 * @param ISecureRandom $secureRandom The random source.
	 *
	 * @return object The host.
	 */
	private function host(IAppConfig $appConfig, ISecureRandom $secureRandom): object {
		return new class($appConfig, $secureRandom) {
			use ConfiguredSigningKey;

			private const SECRET_CONFIG_KEY = 'signing_secret';

			/**
			 * @param IAppConfig    $appConfig    The app config.
			 * @param ISecureRandom $secureRandom The random source.
			 */
			public function __construct(private IAppConfig $appConfig, private ISecureRandom $secureRandom) {
			}

			/**
			 * @return string
			 */
			public function key(): string {
				return $this->signingKey();
			}
		};
	}//end host()

	/**
	 * @return void
	 */
	public function testSigningKey(): void {
		$stored = $this->createMock(IAppConfig::class);
		$stored->method('getValueString')->with('pipelinq', 'signing_secret', '')->willReturn('kept');
		$stored->expects($this->never())->method('setValueString');
		$this->assertSame('kept', $this->host($stored, $this->createStub(ISecureRandom::class))->key());

		$empty = $this->createMock(IAppConfig::class);
		$empty->method('getValueString')->willReturn('');
		$empty->expects($this->once())->method('setValueString')->with('pipelinq', 'signing_secret', 'fresh', false, true);
		$random = $this->createMock(ISecureRandom::class);
		$random->expects($this->once())->method('generate')->with(64, ISecureRandom::CHAR_ALPHANUMERIC)->willReturn('fresh');
		$this->assertSame('fresh', $this->host($empty, $random)->key());
	}//end testSigningKey()
}//end class
