<?php

/**
 * Unit tests for BsnValidationService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#9.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\BsnValidationService;
use PHPUnit\Framework\TestCase;

/**
 * Tests the 11-proef + masking + SHA-256 hashing helpers.
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-001
 */
class BsnValidationServiceTest extends TestCase {
	/**
	 * Service under test.
	 *
	 * @var BsnValidationService
	 */
	private BsnValidationService $service;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new BsnValidationService();
	}//end setUp()

	/**
	 * Known-good BSNs pass the 11-proef.
	 *
	 * `123456782` is the canonical example from the design.md; `111222333` is a
	 * deterministically-constructed pass case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-001-01
	 */
	public function testValidBsnPassesElfproef(): void {
		foreach (['123456782', '111222333'] as $bsn) {
			$result = $this->service->validate($bsn);
			self::assertTrue($result['isFormeelGeldig'], "BSN $bsn should pass");
			self::assertNull($result['errorCode']);
			self::assertSame(0, $result['elfproefScore']);
		}
	}//end testValidBsnPassesElfproef()

	/**
	 * Known-bad BSNs fail the 11-proef.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-001-02
	 */
	public function testInvalidBsnFailsElfproef(): void {
		// 9-digit numerics that fail the 11-proef.
		foreach (['123456789', '987654321'] as $bsn) {
			$result = $this->service->validate($bsn);
			self::assertFalse($result['isFormeelGeldig'], "BSN $bsn should fail");
			self::assertSame(BsnValidationService::ERROR_CHECKSUM, $result['errorCode']);
		}
	}//end testInvalidBsnFailsElfproef()

	/**
	 * Non-9-digit input is rejected without running the 11-proef.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-001-03
	 */
	public function testNonNineDigitInputIsRejected(): void {
		foreach (['12345678', '1234567890', '12345678a', 'abc'] as $bad) {
			$result = $this->service->validate($bad);
			self::assertFalse($result['isFormeelGeldig'], "Input '$bad' must be rejected");
			self::assertSame(BsnValidationService::ERROR_LENGTH, $result['errorCode']);
		}
	}//end testNonNineDigitInputIsRejected()

	/**
	 * Empty input returns the length error and an empty mask.
	 *
	 * @return void
	 */
	public function testEmptyInputReturnsLengthError(): void {
		$result = $this->service->validate('');
		self::assertFalse($result['isFormeelGeldig']);
		self::assertSame(BsnValidationService::ERROR_LENGTH, $result['errorCode']);
		self::assertSame('', $result['maskedBsn']);
	}//end testEmptyInputReturnsLengthError()

	/**
	 * Masking reveals chars 3..6 surrounded by stars (REQ-BSN-009-01).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-009-01
	 */
	public function testMaskHidesMostDigits(): void {
		self::assertSame('***4567*', BsnValidationService::mask('123456782'));
		self::assertSame('***5678*', BsnValidationService::mask('123567892'));
		self::assertSame('', BsnValidationService::mask(''));
		self::assertSame('**', BsnValidationService::mask('12'));
	}//end testMaskHidesMostDigits()

	/**
	 * Hash is deterministic, 64 hex chars, and never identical to the raw BSN.
	 *
	 * @return void
	 */
	public function testHashIsDeterministicSha256(): void {
		$bsn = '123456782';
		$h1 = BsnValidationService::hash($bsn);
		$h2 = BsnValidationService::hash($bsn);
		self::assertSame($h1, $h2);
		self::assertSame(64, strlen($h1));
		self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $h1);
		self::assertNotSame($bsn, $h1);
	}//end testHashIsDeterministicSha256()

	/**
	 * Two different BSNs yield different hashes (collision sanity check).
	 *
	 * @return void
	 */
	public function testHashDiffersBetweenBsns(): void {
		self::assertNotSame(
			BsnValidationService::hash('123456782'),
			BsnValidationService::hash('111222333')
		);
	}//end testHashDiffersBetweenBsns()
}//end class
