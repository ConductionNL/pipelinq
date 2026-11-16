<?php

/**
 * Unit tests for PipelinqBsnIdentityVerifyProvider.
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

use OCA\Pipelinq\Service\BsnValidationService;
use OCA\Pipelinq\Service\Gdpr\PipelinqBsnIdentityVerifyProvider;
use OCA\Pipelinq\Service\HaalCentraalClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins how the BSN is found on a case, that the real 11-proef gates the BRP
 * lookup, that an unconfigured BRP asks for more instead of verifying, and
 * that a BRP error fails closed with a log line.
 *
 * @covers \OCA\Pipelinq\Service\Gdpr\PipelinqBsnIdentityVerifyProvider
 * @uses   \OCA\Pipelinq\Service\BsnValidationService
 */
class PipelinqBsnIdentityVerifyProviderTest extends TestCase {

	/**
	 * A BSN that passes the 11-proef.
	 */
	private const VALID_BSN = '999999990';

	/**
	 * BRP client mock.
	 *
	 * @var HaalCentraalClient&MockObject
	 */
	private HaalCentraalClient&MockObject $brp;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Fresh mocks per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->brp    = $this->createMock(HaalCentraalClient::class);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * The provider under test, with the real BSN validation.
	 *
	 * @return PipelinqBsnIdentityVerifyProvider The provider.
	 */
	private function provider(): PipelinqBsnIdentityVerifyProvider {
		return new PipelinqBsnIdentityVerifyProvider(
			bsnValidation: new BsnValidationService(),
			brpClient: $this->brp,
			logger: $this->logger
		);

	}//end provider()

	/**
	 * The provider id is stable.
	 *
	 * @return void
	 */
	public function testProviderId(): void {
		$this->assertSame('pipelinq-bsn-brp', $this->provider()->getProviderId());

	}//end testProviderId()

	/**
	 * Cases where no field carries a nine-digit BSN.
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public static function casesWithoutABsn(): array {
		return [
			'empty case'        => [[]],
			'eight digits'      => [['bsn' => '12345678']],
			'ten digits'        => [['bsn' => '1234567890']],
			'letters'           => [['bsn' => '12345678a']],
			'spaces inside'     => [['bsn' => '123 45 678']],
			'not a string'      => [['bsn' => ['999999990'], 'subjectBsn' => 999999990.0]],
			'unknown field'     => [['burgerservicenummer' => self::VALID_BSN]],
		];

	}//end casesWithoutABsn()

	/**
	 * Without a BSN the case needs more, and neither validation nor BRP runs.
	 *
	 * @param array<string,mixed> $case The case data.
	 *
	 * @dataProvider casesWithoutABsn
	 *
	 * @return void
	 */
	public function testACaseWithoutABsnNeedsMore(array $case): void {
		$this->brp->expects($this->never())->method('isConfigured');
		$this->brp->expects($this->never())->method('lookupPersoon');

		$result = $this->provider()->verify(caseUuid: 'case-1', case: $case);

		$this->assertSame('needs-more', $result->getStatus());
		$this->assertSame('No BSN present on the case; identity cannot be established yet.', $result->getMessage());

	}//end testACaseWithoutABsnNeedsMore()

	/**
	 * The first field holding a usable BSN wins, in declared order; values are
	 * trimmed and integers count.
	 *
	 * @return void
	 */
	public function testTheFirstUsableBsnFieldIsLookedUp(): void {
		$this->brp->method('isConfigured')->willReturn(true);
		$this->brp->expects($this->once())
			->method('lookupPersoon')
			->with(self::VALID_BSN, 'case-7')
			->willReturn(['naam' => 'Jan']);

		$result = $this->provider()->verify(
			caseUuid: 'case-7',
			case: [
				'bsn'               => 'unknown',
				'subjectBsn'        => '',
				'subjectIdentifier' => 999999990,
				'verzoekerBsn'      => '111222333',
			]
		);

		$this->assertSame('verified', $result->getStatus());
		$this->assertSame('BSN passed the 11-proef and a BRP record was resolved.', $result->getMessage());
		$this->assertSame('pipelinq-bsn-brp', $result->getProviderId());

	}//end testTheFirstUsableBsnFieldIsLookedUp()

	/**
	 * A BSN failing the 11-proef fails with a masked BSN and never reaches BRP.
	 *
	 * @return void
	 */
	public function testABsnFailingTheElevenTestFailsWithoutALookup(): void {
		$this->brp->expects($this->never())->method('isConfigured');
		$this->brp->expects($this->never())->method('lookupPersoon');

		$result = $this->provider()->verify(caseUuid: 'case-1', case: ['subject' => ' 123456789 ']);

		$this->assertSame('failed', $result->getStatus());
		$this->assertSame('BSN failed the 11-proef (***4567*).', $result->getMessage());
		$this->assertStringNotContainsString('123456789', $result->getMessage());

	}//end testABsnFailingTheElevenTestFailsWithoutALookup()

	/**
	 * A valid BSN with no BRP configured needs more, never verified.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredBrpNeedsMore(): void {
		$this->brp->method('isConfigured')->willReturn(false);
		$this->brp->expects($this->never())->method('lookupPersoon');

		$result = $this->provider()->verify(caseUuid: 'case-1', case: ['bsn' => self::VALID_BSN]);

		$this->assertSame('needs-more', $result->getStatus());
		$this->assertSame(
			'BSN is formally valid but the BRP (Haal Centraal) client is not configured; '
				. 'positive identity requires a BRP match.',
			$result->getMessage()
		);

	}//end testAnUnconfiguredBrpNeedsMore()

	/**
	 * BRP answers that resolve no person.
	 *
	 * @return array<string,array{0:array<string,mixed>|null}>
	 */
	public static function emptyBrpAnswers(): array {
		return [
			'null'        => [null],
			'empty array' => [[]],
		];

	}//end emptyBrpAnswers()

	/**
	 * No BRP record fails the verification.
	 *
	 * @param array<string,mixed>|null $answer The BRP answer.
	 *
	 * @dataProvider emptyBrpAnswers
	 *
	 * @return void
	 */
	public function testNoBrpRecordFails(?array $answer): void {
		$this->brp->method('isConfigured')->willReturn(true);
		$this->brp->method('lookupPersoon')->willReturn($answer);
		$this->logger->expects($this->never())->method('warning');

		$result = $this->provider()->verify(caseUuid: 'case-1', case: ['bsn' => self::VALID_BSN]);

		$this->assertSame('failed', $result->getStatus());
		$this->assertSame('No BRP record matched the supplied BSN.', $result->getMessage());

	}//end testNoBrpRecordFails()

	/**
	 * A BRP error fails closed and logs the case and the error, not the BSN.
	 *
	 * @return void
	 */
	public function testABrpErrorFailsClosedAndIsLogged(): void {
		$this->brp->method('isConfigured')->willReturn(true);
		$this->brp->method('lookupPersoon')->willThrowException(new RuntimeException('timeout'));
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'Pipelinq identity verify: BRP lookup failed',
				['case' => 'case-3', 'exception' => 'timeout']
			);

		$result = $this->provider()->verify(caseUuid: 'case-3', case: ['bsn' => self::VALID_BSN]);

		$this->assertSame('failed', $result->getStatus());
		$this->assertSame('BRP lookup errored; identity not established (fail-closed).', $result->getMessage());

	}//end testABrpErrorFailsClosedAndIsLogged()
}//end class
