<?php

/**
 * Behavioural tests for the ShortPlainText trait.
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

use OCA\Pipelinq\Util\ShortPlainText;
use PHPUnit\Framework\TestCase;

/**
 * Tags are stripped and long text is cut to 497 characters plus an ellipsis.
 *
 * @covers \OCA\Pipelinq\Util\ShortPlainText
 */
class ShortPlainTextTest extends TestCase {

	/**
	 * @return void
	 */
	public function testBehaviour(): void {
		$host = new class {
			use ShortPlainText;

			/**
			 * @param string $method  The private method.
			 * @param mixed  ...$args The arguments.
			 * @return mixed
			 */
			public function call(string $method, mixed ...$args): mixed {
				return $this->$method(...$args);
			}
		};

		$this->assertSame('hi', $host->call('shorten', ' <b>hi</b> '));
		$long = $host->call('shorten', str_repeat('é', 600));
		$this->assertSame(500, mb_strlen($long, 'UTF-8'));
		$this->assertStringEndsWith('...', $long);
	}//end testBehaviour()
}//end class
