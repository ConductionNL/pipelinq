<?php

/**
 * Behavioural tests for the SourceRecordLink value object.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair\Support;

use OCA\Pipelinq\Repair\Support\SourceRecordLink;
use PHPUnit\Framework\TestCase;

/**
 * The link names its source system after the entity type and keys the record by it.
 *
 * @covers \OCA\Pipelinq\Repair\Support\SourceRecordLink
 */
class SourceRecordLinkTest extends TestCase {

	/**
	 * @return void
	 */
	public function testToRecordKeysTheRecordBySourceSystemAndNativeId(): void {
		$raw = ['id' => 'v-9', 'name' => 'Acme'];
		$link = new SourceRecordLink(entityType: 'vendor', masterEntity: 'master-1', rawAttributes: $raw);

		$this->assertSame('v-9', $link->nativeId);
		$this->assertSame('shillinq-vendors', $link->sourceSystem());
		$this->assertSame(
			[
				'sourceRecordId' => 'shillinq-vendors:v-9',
				'sourceSystem' => 'shillinq-vendors',
				'nativeId' => 'v-9',
				'entityType' => 'vendor',
				'currentMasterEntity' => 'master-1',
				'rawAttributes' => $raw,
				'mappedAttributes' => [],
				'firstSeen' => '2026-01-02T03:04:05Z',
				'lastSeen' => '2026-01-02T03:04:05Z',
				'lastChange' => '2026-01-02T03:04:05Z',
				'linkageMethod' => 'migration',
				'linkageConfidence' => 1.0,
				'confidence' => 1.0,
			],
			$link->toRecord(now: '2026-01-02T03:04:05Z')
		);
	}//end testToRecordKeysTheRecordBySourceSystemAndNativeId()

	/**
	 * @return void
	 */
	public function testNativeIdIsCastToStringAndEmptyWhenMissing(): void {
		$numeric = new SourceRecordLink(entityType: 'product', masterEntity: 'm', rawAttributes: ['id' => 17]);
		$this->assertSame('17', $numeric->nativeId);
		$this->assertSame('shillinq-products:17', $numeric->toRecord(now: 'n')['sourceRecordId']);

		$missing = new SourceRecordLink(entityType: 'product', masterEntity: 'm', rawAttributes: []);
		$this->assertSame('', $missing->nativeId);
		$this->assertSame('shillinq-products:', $missing->toRecord(now: 'n')['sourceRecordId']);
	}//end testNativeIdIsCastToStringAndEmptyWhenMissing()
}//end class
