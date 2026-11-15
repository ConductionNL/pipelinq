<?php

/**
 * Test helper for the website form suite: OpenRegister's ObjectServiceInterface over a FakeObjectStore.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes;

use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * The published contract, answering findAll and deleteObject from the store.
 */
trait ObjectServiceContract {

	/**
	 * A contract mock that delegates to the store.
	 *
	 * @param FakeObjectStore $store The store.
	 *
	 * @return ObjectServiceInterface The contract.
	 */
	private function contractOver(FakeObjectStore $store): ObjectServiceInterface {
		$contract = $this->createMock(ObjectServiceInterface::class);
		$contract->method('findAll')->willReturnCallback([$store, 'findAll']);
		$contract->method('deleteObject')->willReturnCallback([$store, 'deleteObject']);

		return $contract;
	}//end contractOver()
}//end class
