<?php

/**
 * Tests for CallDisposition.
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

use OCA\Pipelinq\Service\CallDisposition;
use PHPUnit\Framework\TestCase;

/**
 * CallDisposition carries the wrap-up values unchanged.
 */
final class CallDispositionTest extends TestCase {
	/**
	 * Every value reads back as given.
	 *
	 * @return void
	 */
	public function testCarriesValues(): void {
		$disposition = new CallDisposition(interactionId: 'cm-1', subject: 'Vraag', outcome: 'escalated', notes: 'n', groupId: 'g1');

		$this->assertSame(['cm-1', 'Vraag', 'escalated', 'n', 'g1'], [$disposition->interactionId, $disposition->subject, $disposition->outcome, $disposition->notes, $disposition->groupId]);
	}//end testCarriesValues()
}//end class
