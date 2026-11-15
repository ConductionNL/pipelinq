<?php

/**
 * Unit tests for ReceiptAction.
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

use OCA\Pipelinq\Service\ReceiptAction;
use PHPUnit\Framework\TestCase;

/**
 * ReceiptAction keeps the transaction, template, action and actor.
 */
class ReceiptActionTest extends TestCase {

	/**
	 * The values are kept as given.
	 *
	 * @return void
	 */
	public function testKeepsValues(): void {
		$action = new ReceiptAction(transaction: ['id' => 't-1'], template: [], action: 'print', userId: 'anna');

		$this->assertSame(['id' => 't-1'], $action->transaction);
		$this->assertSame([], $action->template);
		$this->assertSame('print', $action->action);
		$this->assertSame('anna', $action->userId);
	}//end testKeepsValues()
}//end class
