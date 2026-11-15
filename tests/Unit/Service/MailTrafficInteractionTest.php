<?php

/**
 * Unit tests for MailTrafficInteraction.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\MailTrafficInteraction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The blast and contact a mail interaction belongs to.
 */
#[CoversClass(MailTrafficInteraction::class)]
class MailTrafficInteractionTest extends TestCase {

	/**
	 * The ids are read from the delivery row as strings.
	 *
	 * @return void
	 */
	public function testIdsComeFromTheDelivery(): void {
		$hit = new MailTrafficInteraction(kind: 'click', delivery: ['blastId' => 12, 'contactId' => 'c-1'], blast: [], clickedUrl: 'https://x.nl');

		$this->assertSame(expected: ['12', 'c-1', 'https://x.nl'], actual: [$hit->blastId(), $hit->contactId(), $hit->clickedUrl]);
	}//end testIdsComeFromTheDelivery()

	/**
	 * A delivery without ids gives empty strings; an open has no clicked URL.
	 *
	 * @return void
	 */
	public function testMissingIdsAreEmpty(): void {
		$hit = new MailTrafficInteraction(kind: 'open', delivery: [], blast: []);

		$this->assertSame(expected: ['', '', null], actual: [$hit->blastId(), $hit->contactId(), $hit->clickedUrl]);
	}//end testMissingIdsAreEmpty()
}//end class
