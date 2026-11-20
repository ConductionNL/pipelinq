<?php

/**
 * Behavioural tests for the ErrorAwareJsonAnswer trait.
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

namespace OCA\Pipelinq\Tests\Unit\Controller\Support;

use OCA\Pipelinq\Controller\Support\ErrorAwareJsonAnswer;
use PHPUnit\Framework\TestCase;

/**
 * An error answers 400 with only the error; a result answers 200.
 *
 * @covers \OCA\Pipelinq\Controller\Support\ErrorAwareJsonAnswer
 */
class ErrorAwareJsonAnswerTest extends TestCase {

	/**
	 * @return void
	 */
	public function testBehaviour(): void {
		$host = new class {
			use ErrorAwareJsonAnswer;

			/**
			 * @param string $method  The private method.
			 * @param mixed  ...$args The arguments.
			 * @return mixed
			 */
			public function call(string $method, mixed ...$args): mixed {
				return $this->$method(...$args);
			}
		};

		$error = $host->call('answer', ['error' => 'bad', 'extra' => 1]);
		$this->assertSame(400, $error->getStatus());
		$this->assertSame(['error' => 'bad'], $error->getData());
		$ok = $host->call('answer', ['id' => 'x']);
		$this->assertSame(200, $ok->getStatus());
		$this->assertSame(['id' => 'x'], $ok->getData());
	}//end testBehaviour()
}//end class
