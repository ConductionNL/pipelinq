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
     * The service under test.
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
     * Test mapResult returns mapped prospect data.
     *
     * @return void
     */
    public function testMapResultReturnsProspect(): void
    {
        $item = [
            'kvkNummer'              => '12345678',
            'eersteHandelsnaam'      => 'Test BV',
            'rechtsvorm'             => 'BV',
            'totaalWerkzamePersonen' => 25,
            'adres'                  => [
                'straatnaam'  => 'Kerkstraat',
                'huisnummer'  => '1',
                'plaats'      => 'Amsterdam',
                'provincie'   => 'Noord-Holland',
                'postcode'    => '1012AB',
            ],
            'registratieDatum'       => '2020-01-01',
            'actief'                 => 'Ja',
        ];

        $result = $this->mapper->mapResult($item, '6201');

        $this->assertSame('12345678', $result['kvkNumber']);
        $this->assertSame('Test BV', $result['tradeName']);
        $this->assertSame('BV', $result['legalForm']);
        $this->assertSame('6201', $result['sbiCode']);
        $this->assertSame(25, $result['employeeCount']);
        $this->assertTrue($result['isActive']);
        $this->assertSame('kvk', $result['source']);
        $this->assertSame('Amsterdam', $result['address']['city']);
    }//end testMapResultReturnsProspect()

    /**
     * Test mapResult returns null without kvkNummer.
     *
     * @return void
     */
    public function testMapResultReturnsNullWithoutKvk(): void
    {
        $this->assertNull($this->mapper->mapResult([], '6201'));
    }//end testMapResultReturnsNullWithoutKvk()

    /**
     * Test mapResult handles inactive company.
     *
     * @return void
     */
    public function testMapResultInactiveCompany(): void
    {
        $item = [
            'kvkNummer' => '99999999',
            'actief'    => 'Nee',
        ];

        $result = $this->mapper->mapResult($item, '62');

        $this->assertFalse($result['isActive']);
    }//end testMapResultInactiveCompany()

    /**
     * Test mapResult uses naam as fallback for trade name.
     *
     * @return void
     */
    public function testMapResultUsesNaamFallback(): void
    {
        $item = [
            'kvkNummer' => '11111111',
            'naam'      => 'Fallback Name',
        ];

        $result = $this->mapper->mapResult($item, '62');

        $this->assertSame('Fallback Name', $result['tradeName']);
    }//end testMapResultUsesNaamFallback()

    /**
     * Test mapResult finds SBI description.
     *
     * @return void
     */
    public function testMapResultFindsSbiDescription(): void
    {
        $item = [
            'kvkNummer'       => '12345678',
            'spiActiviteiten' => [
                ['sbiCode' => '6201', 'sbiOmschrijving' => 'Software development'],
            ],
        ];

        $result = $this->mapper->mapResult($item, '62');

        $this->assertSame('Software development', $result['sbiDescription']);
    }//end testMapResultFindsSbiDescription()
}//end class
