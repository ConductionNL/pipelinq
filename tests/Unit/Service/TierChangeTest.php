<?php

/**
 * Unit tests for TierChange.
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

use OCA\Pipelinq\Service\TierChange;
use PHPUnit\Framework\TestCase;

/**
 * TierChange reads the loyalty account a tier change is about.
 */
class TierChangeTest extends TestCase {

	/**
	 * The account's programme, lifetime points and tier are read as typed.
	 *
	 * @return void
	 */
	public function testTheAccountIsReadAsTyped(): void {
		$change = new TierChange(accountId: 'acc-1', account: ['programmeId' => 42, 'lifetimePoints' => '1250', 'currentTierId' => 'tier-gold']);

		self::assertSame('acc-1', $change->accountId);
		self::assertSame('42', $change->programmeId());
		self::assertSame(1250, $change->lifetimePoints());
		self::assertSame('tier-gold', $change->currentTierId());
	}//end testTheAccountIsReadAsTyped()

	/**
	 * An account without those fields has no programme, no points and no tier.
	 *
	 * @return void
	 */
	public function testMissingFieldsHaveNeutralValues(): void {
		$change = new TierChange(accountId: 'acc-2', account: []);

		self::assertSame('', $change->programmeId());
		self::assertSame(0, $change->lifetimePoints());
		self::assertNull($change->currentTierId());
	}//end testMissingFieldsHaveNeutralValues()
}//end class
