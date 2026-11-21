<?php

/**
 * Unit tests for DeliveryAuditEntry.
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

use OCA\Pipelinq\Service\DeliveryAuditEntry;
use PHPUnit\Framework\TestCase;

/**
 * DeliveryAuditEntry carries its values unchanged.
 */
class DeliveryAuditEntryTest extends TestCase {

	/**
	 * Every constructor value is readable as given.
	 *
	 * @return void
	 */
	public function testKeepsTheValuesItIsGiven(): void {
		$value = new DeliveryAuditEntry(messageId: 'msg-1', event: 'sent', payloadHash: 'abc', retentionUntil: null, actor: 'system');

		self::assertSame('msg-1', $value->messageId);
		self::assertSame('sent', $value->event);
		self::assertSame('abc', $value->payloadHash);
		self::assertSame(null, $value->retentionUntil);
		self::assertSame('system', $value->actor);
	}//end testKeepsTheValuesItIsGiven()
}//end class
