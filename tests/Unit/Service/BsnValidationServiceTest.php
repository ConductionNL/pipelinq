<?php

/**
 * Unit tests for BsnValidationService and BsnMasker.
 *
 * Exercises the deterministic RvIG 11-proef across known-valid BSNs, checksum
 * failures, malformed lengths, non-numeric input, the all-zero sentinel, and
 * asserts the masking / hashing privacy invariants (ADR-005): no plain-text BSN
 * is ever present in a result, and the masked form is `***45678*`.
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
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\Bsn\BsnMasker;
use OCA\Pipelinq\Service\BsnValidationService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the BSN 11-proef validation and masking (REQ-BSN-001 / 009).
 */
class BsnValidationServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var BsnValidationService
     */
    private BsnValidationService $service;

    /**
     * Set up the service.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BsnValidationService();
    }//end setUp()

    /**
     * Known-valid BSNs (passing the 11-proef) are accepted.
     *
     * @return void
     */
    public function testValidBsnsAreAccepted(): void
    {
        foreach (['123456782', '111222333', '999999990'] as $bsn) {
            $result = $this->service->validate($bsn);
            $this->assertTrue($result->isFormeelGeldig, "$bsn should be valid");
            $this->assertNull($result->errorCode);
            $this->assertNull($result->errorMessage);
        }
    }//end testValidBsnsAreAccepted()

    /**
     * Nine-digit BSNs that fail the checksum are rejected with the 11-proef code.
     *
     * @return void
     */
    public function testChecksumFailureIsRejected(): void
    {
        foreach (['123456789', '987654321', '123456780'] as $bsn) {
            $result = $this->service->validate($bsn);
            $this->assertFalse($result->isFormeelGeldig, "$bsn should fail the 11-proef");
            $this->assertSame(BsnValidationService::ERROR_ELFPROEF, $result->errorCode);
        }
    }//end testChecksumFailureIsRejected()

    /**
     * The all-zero BSN is rejected even though its checksum is zero.
     *
     * @return void
     */
    public function testAllZeroBsnIsRejected(): void
    {
        $result = $this->service->validate('000000000');
        $this->assertFalse($result->isFormeelGeldig);
        $this->assertSame(BsnValidationService::ERROR_ELFPROEF, $result->errorCode);
    }//end testAllZeroBsnIsRejected()

    /**
     * Too-short / too-long inputs are rejected on length before the 11-proef.
     *
     * @return void
     */
    public function testWrongLengthIsRejected(): void
    {
        foreach (['12345678', '1234567890', '', '1'] as $bsn) {
            $result = $this->service->validate($bsn);
            $this->assertFalse($result->isFormeelGeldig);
            $this->assertSame(BsnValidationService::ERROR_LENGTH, $result->errorCode);
        }
    }//end testWrongLengthIsRejected()

    /**
     * Non-numeric nine-character input is rejected on the character class check.
     *
     * @return void
     */
    public function testNonNumericInputIsRejected(): void
    {
        foreach (['12345678a', 'abcdefghi', '1234 5678'] as $bsn) {
            $result = $this->service->validate($bsn);
            $this->assertFalse($result->isFormeelGeldig);
            $this->assertSame(BsnValidationService::ERROR_LENGTH, $result->errorCode);
        }
    }//end testNonNumericInputIsRejected()

    /**
     * A valid result carries the masked BSN, never the raw value (ADR-005).
     *
     * @return void
     */
    public function testResultNeverLeaksRawBsn(): void
    {
        $result  = $this->service->validate('123456782');
        $encoded = json_encode($result->toArray());

        $this->assertSame('***45678*', $result->bsnGemaskeerd);
        $this->assertStringNotContainsString('123456782', (string) $encoded);
    }//end testResultNeverLeaksRawBsn()

    /**
     * The masker redacts malformed input fully and never echoes the raw value.
     *
     * @return void
     */
    public function testMaskerBehaviour(): void
    {
        $this->assertSame('***45678*', BsnMasker::mask('123456782'));
        $this->assertSame('*********', BsnMasker::mask('not-a-bsn'));
        $this->assertSame('*********', BsnMasker::mask('12345'));
    }//end testMaskerBehaviour()

    /**
     * The keyed hash is deterministic, prefixed, and depends on the secret.
     *
     * @return void
     */
    public function testHashIsKeyedAndDeterministic(): void
    {
        $a = BsnMasker::hash('123456782', 'secret-one');
        $b = BsnMasker::hash('123456782', 'secret-one');
        $c = BsnMasker::hash('123456782', 'secret-two');

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
        $this->assertStringStartsWith('sha256:', $a);
        $this->assertStringNotContainsString('123456782', $a);
    }//end testHashIsKeyedAndDeterministic()
}//end class
