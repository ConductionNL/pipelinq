<?php

/**
 * Unit tests for PosTransactionStore.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Payment
 *
 * @author    Conduction <info@conduction.nl>
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

namespace OCA\Pipelinq\Tests\Unit\Service\Payment;

use OCA\Pipelinq\Service\Payment\PosTransactionStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Where a transaction lookup runs.
 */
#[CoversClass(PosTransactionStore::class)]
class PosTransactionStoreTest extends TestCase {

	/**
	 * The service, register and schema are kept as given.
	 *
	 * @return void
	 */
	public function testKeepsWhatItIsGiven(): void {
		$service = new \stdClass();
		$store = new PosTransactionStore(objectService: $service, register: 'r-1', schema: 's-1');

		$this->assertSame(expected: [$service, 'r-1', 's-1'], actual: [$store->objectService, $store->register, $store->schema]);
	}//end testKeepsWhatItIsGiven()
}//end class
