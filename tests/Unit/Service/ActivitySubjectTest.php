<?php

/**
 * Unit tests for ActivitySubject.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ActivitySubject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What an activity says.
 */
#[CoversClass(ActivitySubject::class)]
class ActivitySubjectTest extends TestCase {

	/**
	 * The subject, type and parameters are kept as given.
	 *
	 * @return void
	 */
	public function testKeepsWhatItIsGiven(): void {
		$subject = new ActivitySubject(subject: 'lead_won', type: 'pipelinq_deals', parameters: ['title' => 'Deal']);

		$this->assertSame(expected: ['lead_won', 'pipelinq_deals', ['title' => 'Deal']], actual: [$subject->subject, $subject->type, $subject->parameters]);
	}//end testKeepsWhatItIsGiven()
}//end class
