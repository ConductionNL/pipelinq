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
            'kvkNummer'         => '12345678',
            'eersteHandelsnaam' => 'Acme B.V.',
            'rechtsvorm'        => 'BV',
            'actief'            => 'Ja',
            'adres'             => ['straatnaam' => 'Str', 'huisnummer' => '1', 'plaats' => 'Amsterdam', 'provincie' => 'NH', 'postcode' => '1234AB'],
            'spiActiviteiten'   => [['sbiCode' => '6201', 'sbiOmschrijving' => 'Software']],
        ];

        $result = $this->mapper->mapResult(item: $item, sbiCode: '6201');

        $this->assertSame('12345678', $result['kvkNumber']);
        $this->assertSame('Acme B.V.', $result['tradeName']);
        $this->assertSame('Software', $result['sbiDescription']);
        $this->assertTrue($result['isActive']);
        $this->assertSame('kvk', $result['source']);
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
        $this->assertFalse($this->mapper->mapResult(item: ['kvkNummer' => '1', 'actief' => 'Nee'], sbiCode: '')['isActive']);
    }//end testMapResultMapsInactiveCompany()

    /**
     * Test that SBI prefix matching finds the description.
     *
     * @return void
     */
    public function testMapResultFindsSbiByPrefix(): void
    {
        $item   = ['kvkNummer' => '2', 'spiActiviteiten' => [['sbiCode' => '6201', 'sbiOmschrijving' => 'Dev']]];
        $result = $this->mapper->mapResult(item: $item, sbiCode: '62');

        $this->assertSame('Dev', $result['sbiDescription']);
    }//end testMapResultFindsSbiByPrefix()
}//end class
