<?php

/**
 * Unit tests for PhoneNormaliser.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://codeberg.org/Conduction/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\PhoneNormaliser;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PhoneNormaliser (REQ-CTI-002).
 */
class PhoneNormaliserTest extends TestCase
{
    /**
     * The app config mock.
     *
     * @var IAppConfig
     */
    private IAppConfig $appConfig;

    /**
     * The service under test.
     *
     * @var PhoneNormaliser
     */
    private PhoneNormaliser $normaliser;

    /**
     * Set up with a default NL country.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->appConfig->method('getValueString')
            ->willReturnCallback(
                static function (string $app, string $key, string $default): string {
                    return ($key === 'default_country_code') ? 'NL' : $default;
                }
            );

        $this->normaliser = new PhoneNormaliser($this->appConfig);
    }//end setUp()

    /**
     * National Dutch number normalises to E.164 with country code.
     *
     * @return void
     */
    public function testNationalNumberNormalisesToE164(): void
    {
        $result = $this->normaliser->normalise('0612345678');

        $this->assertSame('+31612345678', $result['e164']);
        $this->assertSame('0612345678', $result['raw']);
    }//end testNationalNumberNormalisesToE164()

    /**
     * National number with spaces is normalised.
     *
     * @return void
     */
    public function testNationalNumberWithSpacesIsNormalised(): void
    {
        $result = $this->normaliser->normalise('06 1234 5678');

        $this->assertSame('+31612345678', $result['e164']);
    }//end testNationalNumberWithSpacesIsNormalised()

    /**
     * An international number keeps its own country code (not forced to NL).
     *
     * @return void
     */
    public function testInternationalNumberKeepsCountryCode(): void
    {
        $result = $this->normaliser->normalise('+33 1 42 68 53 00');

        $this->assertSame('+33142685300', $result['e164']);
    }//end testInternationalNumberKeepsCountryCode()

    /**
     * A 00-prefixed international number is normalised.
     *
     * @return void
     */
    public function testDoubleZeroInternationalIsNormalised(): void
    {
        $result = $this->normaliser->normalise('0031612345678');

        $this->assertSame('+31612345678', $result['e164']);
    }//end testDoubleZeroInternationalIsNormalised()

    /**
     * An already-E.164 number passes through unchanged.
     *
     * @return void
     */
    public function testE164PassesThroughUnchanged(): void
    {
        $result = $this->normaliser->normalise('+31612345678');

        $this->assertSame('+31612345678', $result['e164']);
    }//end testE164PassesThroughUnchanged()

    /**
     * Unparseable input yields a null E.164 and preserves the raw value.
     *
     * @return void
     */
    public function testUnparseableNumberReturnsNullE164(): void
    {
        $result = $this->normaliser->normalise('abc123xyz');

        $this->assertNull($result['e164']);
        $this->assertSame('abc123xyz', $result['raw']);
    }//end testUnparseableNumberReturnsNullE164()

    /**
     * An empty string is not parseable.
     *
     * @return void
     */
    public function testEmptyStringReturnsNull(): void
    {
        $result = $this->normaliser->normalise('');

        $this->assertNull($result['e164']);
    }//end testEmptyStringReturnsNull()

    /**
     * Too-short digit strings are rejected.
     *
     * @return void
     */
    public function testTooShortNumberReturnsNull(): void
    {
        $result = $this->normaliser->normalise('+31 12');

        $this->assertNull($result['e164']);
    }//end testTooShortNumberReturnsNull()
}//end class
