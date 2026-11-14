<?php

/**
 * Behavioural tests for the ExportSourceCredentials trait.
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

use OCA\Pipelinq\Util\ExportSourceCredentials;
use PHPUnit\Framework\TestCase;

/**
 * Non-empty legacy secrets and a non-empty authentication block are kept.
 *
 * @covers \OCA\Pipelinq\Util\ExportSourceCredentials
 */
class ExportSourceCredentialsTest extends TestCase {

	/**
	 * @return void
	 */
	public function testCollectsSecrets(): void {
		$host = new class {
			use ExportSourceCredentials;

			private const OPENCONNECTOR_LEGACY_SECRET_FIELDS = ['password', 'apikey'];

			/**
			 * @param array<string, mixed> $source The source.
			 * @return array<string, mixed>
			 */
			public function read(array $source): array {
				return $this->extractSourceCredentials(source: $source);
			}
		};

		$this->assertSame(
			['password' => 'p', 'authentication' => ['type' => 'basic']],
			$host->read(source: ['password' => 'p', 'apikey' => '', 'other' => 'x', 'configuration' => ['authentication' => ['type' => 'basic']]])
		);
		$this->assertSame([], $host->read(source: ['apikey' => 5, 'configuration' => ['authentication' => []]]));
	}//end testCollectsSecrets()
}//end class
