<?php

/**
 * Unit tests for PipelinqApRegulatorEscalateProvider.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Gdpr
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Gdpr;

use OCA\Pipelinq\Service\Gdpr\PipelinqApRegulatorEscalateProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins which case field becomes the AP-complaint reference, the fail-closed
 * refusal and the log line.
 *
 * @covers \OCA\Pipelinq\Service\Gdpr\PipelinqApRegulatorEscalateProvider
 */
class PipelinqApRegulatorEscalateProviderTest extends TestCase {

	/**
	 * The provider id is stable.
	 *
	 * @return void
	 */
	public function testProviderId(): void {
		$provider = new PipelinqApRegulatorEscalateProvider($this->createMock(LoggerInterface::class));

		$this->assertSame('pipelinq-ap-complaint', $provider->getProviderId());
	}//end testProviderId()

	/**
	 * The first non-empty reference field wins, in the declared order, and is
	 * trimmed; integers count, other types are skipped.
	 *
	 * @return void
	 */
	public function testTheFirstUsableReferenceFieldWins(): void {
		$provider = new PipelinqApRegulatorEscalateProvider($this->createMock(LoggerInterface::class));

		$cases = [
			[['kenmerk' => ' K-1 ', 'reference' => 'R-1'], 'AP-KLACHT/K-1'],
			[['kenmerk' => '  ', 'reference' => 'R-2', 'caseNumber' => 'C-2'], 'AP-KLACHT/R-2'],
			[['kenmerk' => ['x'], 'reference' => 1.5, 'caseNumber' => 42], 'AP-KLACHT/42'],
			[['dossierNummer' => 'D-9'], 'AP-KLACHT/D-9'],
		];
		foreach ($cases as [$case, $expected]) {
			$result = $provider->escalate('uuid-1', $case);
			$this->assertTrue($result->isEscalated());
			$this->assertSame($expected, $result->getReference());
			$this->assertSame('pipelinq-ap-complaint', $result->getProviderId());
		}
	}//end testTheFirstUsableReferenceFieldWins()

	/**
	 * Without a reference field the trimmed case uuid is used, and the issued
	 * reference is logged with the AP complaint link in the message.
	 *
	 * @return void
	 */
	public function testTheUuidIsTheFallbackAndTheReferenceIsLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			'Pipelinq regulator escalate: AP-complaint reference issued',
			['case' => ' uuid-7 ', 'reference' => 'AP-KLACHT/uuid-7']
		);
		$provider = new PipelinqApRegulatorEscalateProvider($logger);

		$result = $provider->escalate(' uuid-7 ', []);

		$this->assertSame('AP-KLACHT/uuid-7', $result->getReference());
		$this->assertSame(
			'AP-complaint dossier prepared. Klacht melden bij de AP via '.PipelinqApRegulatorEscalateProvider::AP_COMPLAINT_URL,
			$result->getMessage()
		);
	}//end testTheUuidIsTheFallbackAndTheReferenceIsLogged()

	/**
	 * A case with neither a reference nor a uuid is refused and not logged.
	 *
	 * @return void
	 */
	public function testACaseWithoutAnyIdentifierIsRefused(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');
		$provider = new PipelinqApRegulatorEscalateProvider($logger);

		$result = $provider->escalate('  ', ['kenmerk' => '']);

		$this->assertFalse($result->isEscalated());
		$this->assertSame('refused', $result->getStatus());
		$this->assertSame('', $result->getReference());
		$this->assertSame(
			'Case carries no kenmerk or uuid; AP-complaint escalation not performed (fail-closed).',
			$result->getMessage()
		);
	}//end testACaseWithoutAnyIdentifierIsRefused()
}//end class
