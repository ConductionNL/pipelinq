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
            'adres'                  => ['straatnaam' => 'Teststraat', 'huisnummer' => '10', 'plaats' => 'Amsterdam', 'provincie' => 'Noord-Holland', 'postcode' => '1234AB'],
            'spiActiviteiten'        => [['sbiCode' => '6201', 'sbiOmschrijving' => 'Software development']],
        ];

        $result = $this->mapper->mapResult(item: $item, sbiCode: '6201');

        $this->assertNotNull($result);
        $this->assertSame('12345678', $result['kvkNumber']);
        $this->assertSame('Acme B.V.', $result['tradeName']);
        $this->assertSame('Software development', $result['sbiDescription']);
        $this->assertTrue($result['isActive']);
        $this->assertSame('kvk', $result['source']);
        $this->assertSame('Amsterdam', $result['address']['city']);
    }//end testMapResultMapsFullItem()

    /**
     * Test that an item without kvkNummer returns null.
     *
     * @return void
     */
    public function testMapResultReturnsNullWithoutKvkNumber(): void
    {
        $this->assertNull($this->mapper->mapResult(item: ['naam' => 'Test'], sbiCode: ''));
    }//end testMapResultReturnsNullWithoutKvkNumber()

    /**
     * Test that an inactive company maps isActive as false.
     *
     * @return void
     */
    public function testMapResultMapsInactiveCompany(): void
    {
        $result = $this->mapper->mapResult(item: ['kvkNummer' => '99999999', 'actief' => 'Nee'], sbiCode: '62');

        $this->assertFalse($result['isActive']);
    }//end testMapResultMapsInactiveCompany()

    /**
     * Test that 'naam' is used as fallback trade name.
     *
     * @return void
     */
    public function testMapResultFallsBackToNaam(): void
    {
        $result = $this->mapper->mapResult(item: ['kvkNummer' => '11111111', 'naam' => 'Fallback'], sbiCode: '');

        $this->assertSame('Fallback', $result['tradeName']);
    }//end testMapResultFallsBackToNaam()

    /**
     * Test that SBI prefix matching finds the description.
     *
     * @return void
     */
    public function testMapResultFindsSbiDescriptionByPrefix(): void
    {
        $item   = ['kvkNummer' => '22222222', 'spiActiviteiten' => [['sbiCode' => '6201', 'sbiOmschrijving' => 'Software']]];
        $result = $this->mapper->mapResult(item: $item, sbiCode: '62');

        $this->assertSame('Software', $result['sbiDescription']);
    }//end testMapResultFindsSbiDescriptionByPrefix()
}//end class
