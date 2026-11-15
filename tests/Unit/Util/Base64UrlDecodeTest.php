<?php

/**
 * Behavioural tests for the Base64UrlDecode trait.
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

use OCA\Pipelinq\Util\Base64UrlDecode;
use PHPUnit\Framework\TestCase;

/**
 * URL-safe alphabet decodes; invalid input is ''.
 *
 * @covers \OCA\Pipelinq\Util\Base64UrlDecode
 */
class Base64UrlDecodeTest extends TestCase {

	/**
	 * @return void
	 */
	public function testDecode(): void {
		$host = new class {
			use Base64UrlDecode;

			/**
			 * @param string $data The text.
			 * @return string
			 */
			public function decode(string $data): string {
				return $this->base64UrlDecode(data: $data);
			}
		};

		$this->assertSame("\xfb\xff", $host->decode(data: '-_8'));
		$this->assertSame('hello', $host->decode(data: 'aGVsbG8'));
		$this->assertSame('', $host->decode(data: '***'));
	}//end testDecode()
}//end class
