<?php

/**
 * Unit tests for JourneyCompileRun.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\JourneyCompileRun;
use PHPUnit\Framework\TestCase;

/**
 * A compile run carries its journey id, journey and identity unchanged.
 */
class JourneyCompileRunTest extends TestCase {

	/**
	 * Every value given is the value read back.
	 *
	 * @return void
	 */
	public function testCarriesItsValues(): void {
		$run = new JourneyCompileRun(journeyId: 'j-1', journey: ['title' => 'Welcome'], runAs: 'admin');

		self::assertSame('j-1', $run->journeyId);
		self::assertSame(['title' => 'Welcome'], $run->journey);
		self::assertSame('admin', $run->runAs);
	}//end testCarriesItsValues()
}//end class
