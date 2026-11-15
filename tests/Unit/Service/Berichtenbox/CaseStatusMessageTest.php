<?php

/**
 * Unit tests for CaseStatusMessage: the template variables of a case status message.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Berichtenbox
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

namespace OCA\Pipelinq\Tests\Unit\Service\Berichtenbox;

use OCA\Pipelinq\Service\Berichtenbox\CaseStatusMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The variables never carry the citizen number.
 */
#[CoversClass(CaseStatusMessage::class)]
class CaseStatusMessageTest extends TestCase {

	/**
	 * The variables are the case, the status, the municipality, an empty deadline and the message id, in that order.
	 *
	 * @return void
	 */
	public function testTheVariablesOfAStatusMessage(): void {
		$message = new CaseStatusMessage(caseId: 'zaak-7', interactionId: null, status: 'in behandeling', bsn: '999993653');

		$variables = $message->variables(gemeente: 'Utrecht', messageId: 'msg-1');

		self::assertSame(
			['caseId' => 'zaak-7', 'status' => 'in behandeling', 'gemeente' => 'Utrecht', 'deadline' => '', 'messageId' => 'msg-1'],
			$variables
		);
		self::assertNotContains('999993653', $variables);
		self::assertSame('999993653', $message->bsn);
	}//end testTheVariablesOfAStatusMessage()
}//end class
