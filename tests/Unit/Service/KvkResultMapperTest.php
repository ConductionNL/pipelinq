<?php

/**
 * Unit tests for KvkResultMapper.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\KvkResultMapper;
use PHPUnit\Framework\TestCase;

/**
 * Tests for KvkResultMapper.
 */
class KvkResultMapperTest extends TestCase
{
    /**
     * The mapper under test.
     *
     * @var KvkResultMapper
     */
    private KvkResultMapper $mapper;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->mapper = new KvkResultMapper();
    }//end setUp()

    /**
     * Test that a complete KVK result is mapped correctly.
     *
     * @return void
     */
    public function testMapResultMapsFullItem(): void
    {
        $item = [
            'kvkNummer'              => '12345678',
            'eersteHandelsnaam'      => 'Acme B.V.',
            'rechtsvorm'             => 'BV',
            'totaalWerkzamePersonen' => 42,
            'registratieDatum'       => '2010-01-15',
            'actief'                 => 'Ja',
            'adres'                  => [
                'straatnaam'  => 'Teststraat',
                'huisnummer'  => '10',
                'plaats'      => 'Amsterdam',
                'provincie'   => 'Noord-Holland',
                'postcode'    => '1234AB',
            ],
            'spiActiviteiten'        => [
                ['sbiCode' => '6201', 'sbiOmschrijving' => 'Software development'],
            ],
        ];

        $result = $this->mapper->mapResult(item: $item, sbiCode: '6201');

        $this->assertNotNull($result);
        $this->assertSame('12345678', $result['kvkNumber']);
        $this->assertSame('Acme B.V.', $result['tradeName']);
        $this->assertSame('BV', $result['legalForm']);
        $this->assertSame('6201', $result['sbiCode']);
        $this->assertSame('Software development', $result['sbiDescription']);
        $this->assertSame(42, $result['employeeCount']);
        $this->assertSame('2010-01-15', $result['registrationDate']);
        $this->assertTrue($result['isActive']);
        $this->assertSame('kvk', $result['source']);
        $this->assertSame('Amsterdam', $result['address']['city']);
        $this->assertSame('Noord-Holland', $result['address']['province']);
        $this->assertSame('1234AB', $result['address']['postalCode']);
    }//end testMapResultMapsFullItem()

    /**
     * Test that an item without kvkNummer returns null.
     *
     * @return void
     */
    public function testMapResultReturnsNullWithoutKvkNumber(): void
    {
        $item = [
            'eersteHandelsnaam' => 'No Number B.V.',
        ];

        $result = $this->mapper->mapResult(item: $item, sbiCode: '6201');

        $this->assertNull($result);
    }//end testMapResultReturnsNullWithoutKvkNumber()

    /**
     * Test that an inactive company maps isActive as false.
     *
     * @return void
     */
    public function testMapResultMapsInactiveCompany(): void
    {
        $item = [
            'kvkNummer' => '99999999',
            'actief'    => 'Nee',
        ];

        $result = $this->mapper->mapResult(item: $item, sbiCode: '62');

        $this->assertNotNull($result);
        $this->assertFalse($result['isActive']);
    }//end testMapResultMapsInactiveCompany()

    /**
     * Test that the fallback trade name field 'naam' is used when no eersteHandelsnaam.
     *
     * @return void
     */
    public function testMapResultFallsBackToNaamForTradeName(): void
    {
        $item = [
            'kvkNummer' => '11111111',
            'naam'      => 'Fallback Naam B.V.',
        ];

        $result = $this->mapper->mapResult(item: $item, sbiCode: '62');

        $this->assertNotNull($result);
        $this->assertSame('Fallback Naam B.V.', $result['tradeName']);
    }//end testMapResultFallsBackToNaamForTradeName()

    /**
     * Test that SBI prefix matching finds the description when code is a prefix.
     *
     * @return void
     */
    public function testMapResultFindsSbiDescriptionByPrefix(): void
    {
        $item = [
            'kvkNummer'         => '22222222',
            'spiActiviteiten'   => [
                ['sbiCode' => '6201', 'sbiOmschrijving' => 'Ontwikkelen en produceren van software'],
            ],
        ];

        // sbiCode '62' is a prefix of '6201'.
        $result = $this->mapper->mapResult(item: $item, sbiCode: '62');

        $this->assertNotNull($result);
        $this->assertSame('Ontwikkelen en produceren van software', $result['sbiDescription']);
    }//end testMapResultFindsSbiDescriptionByPrefix()

    /**
     * Test that an empty item with just kvkNummer returns a minimal valid record.
     *
     * @return void
     */
    public function testMapResultHandlesMinimalItem(): void
    {
        $item = ['kvkNummer' => '00000001'];

        $result = $this->mapper->mapResult(item: $item, sbiCode: '');

        $this->assertNotNull($result);
        $this->assertSame('00000001', $result['kvkNumber']);
        $this->assertSame('', $result['tradeName']);
        $this->assertSame('', $result['legalForm']);
        $this->assertNull($result['employeeCount']);
        $this->assertTrue($result['isActive']); // Default 'actief' => 'Ja'.
        $this->assertSame('', $result['sbiDescription']);
    }//end testMapResultHandlesMinimalItem()

    /**
     * Test that address falls back to vestingAdres when adres is absent.
     *
     * @return void
     */
    public function testMapResultUsesVestingAdresFallback(): void
    {
        $item = [
            'kvkNummer'    => '33333333',
            'vestingAdres' => [
                'straatnaam' => 'Vestigingstraat',
                'huisnummer' => '5',
                'plaats'     => 'Utrecht',
                'provincie'  => 'Utrecht',
                'postcode'   => '3500AA',
            ],
        ];

        $result = $this->mapper->mapResult(item: $item, sbiCode: '');

        $this->assertNotNull($result);
        $this->assertSame('Utrecht', $result['address']['city']);
    }//end testMapResultUsesVestingAdresFallback()
}//end class
