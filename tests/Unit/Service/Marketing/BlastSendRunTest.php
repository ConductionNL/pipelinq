<?php

/**
 * Unit tests for BlastSendRun.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\BlastSendRun;
use PHPUnit\Framework\TestCase;

/**
 * A blast send counts the contacts it left out.
 *
 * @covers \OCA\Pipelinq\Service\Marketing\BlastSendRun
 */
class BlastSendRunTest extends TestCase {

	/**
	 * The left-out counts are the sizes of the audience's two lists.
	 *
	 * @return void
	 */
	public function testCountsMissingConsentAndSuppressed(): void {
		$run = new BlastSendRun(
			blastId: 'b-1',
			channel: 'email',
			isDraft: false,
			audience: [
				'members'        => [['id' => 'm-1']],
				'missingConsent' => ['c-1', 'c-2', 'c-3'],
				'suppressed'     => ['c-4'],
			],
		);

		$this->assertSame(3, $run->noConsent());
		$this->assertSame(1, $run->suppressed());
		$this->assertSame('b-1', $run->blastId);
		$this->assertFalse($run->isDraft);
	}//end testCountsMissingConsentAndSuppressed()

	/**
	 * An audience without a suppressed list counts none suppressed.
	 *
	 * @return void
	 */
	public function testAnAudienceWithoutASuppressedListCountsNone(): void {
		$run = new BlastSendRun(blastId: 'b-2', channel: 'sms', isDraft: true, audience: ['missingConsent' => []]);

		$this->assertSame(0, $run->noConsent());
		$this->assertSame(0, $run->suppressed());
	}//end testAnAudienceWithoutASuppressedListCountsNone()
}//end class
