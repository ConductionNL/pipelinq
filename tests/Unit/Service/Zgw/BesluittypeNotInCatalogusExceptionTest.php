<?php

/**
 * Unit tests for the besluittype-not-in-catalogus exception.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Zgw;

use OCA\Pipelinq\Service\Zgw\BesluittypeNotInCatalogusException;
use OCA\Pipelinq\Service\Zgw\ZgwException;
use PHPUnit\Framework\TestCase;

/**
 * The exception keeps the omschrijving it failed on and names it.
 *
 * @covers \OCA\Pipelinq\Service\Zgw\BesluittypeNotInCatalogusException
 *
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-001
 */
class BesluittypeNotInCatalogusExceptionTest extends TestCase {

	/**
	 * The omschrijving is kept and quoted in the message.
	 *
	 * @return void
	 */
	public function testItCarriesTheOmschrijving(): void {
		$error = new BesluittypeNotInCatalogusException('Besluit op aanvraag');

		self::assertInstanceOf(ZgwException::class, $error);
		self::assertSame('Besluit op aanvraag', $error->omschrijving);
		self::assertSame(
			'ZGW: besluittype with omschrijving "Besluit op aanvraag" not found in catalogus',
			$error->getMessage()
		);
		self::assertSame(0, $error->getCode());
	}//end testItCarriesTheOmschrijving()
}//end class
