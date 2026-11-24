<?php

/**
 * Unit tests for SurveyInvitationOccasion.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Pipelinq\Service\SurveyInvitationOccasion;
use PHPUnit\Framework\TestCase;

/**
 * SurveyInvitationOccasion keeps what an invitation is built from.
 */
class SurveyInvitationOccasionTest extends TestCase {

	/**
	 * The values are kept as given.
	 *
	 * @return void
	 */
	public function testKeepsValues(): void {
		$now = new DateTimeImmutable('2026-03-01T10:00:00+00:00');
		$occasion = new SurveyInvitationOccasion(rule: ['id' => 'r'], contact: ['id' => 'c'], entity: ['id' => 'e'], now: $now);

		$this->assertSame(['id' => 'r'], $occasion->rule);
		$this->assertSame(['id' => 'c'], $occasion->contact);
		$this->assertSame(['id' => 'e'], $occasion->entity);
		$this->assertSame($now, $occasion->now);
	}//end testKeepsValues()
}//end class
