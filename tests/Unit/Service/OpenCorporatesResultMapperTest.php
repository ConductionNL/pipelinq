<?php

/**
 * Unit tests for OpenCorporatesResultMapper.
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

use OCA\Pipelinq\Service\OpenCorporatesResultMapper;
use PHPUnit\Framework\TestCase;

/**
 * Tests for OpenCorporatesResultMapper.
 */
class OpenCorporatesResultMapperTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var OpenCorporatesResultMapper
     */
    private OpenCorporatesResultMapper $mapper;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->mapper = new OpenCorporatesResultMapper();
    }//end setUp()

    /**
     * Test mapResult returns mapped prospect data.
     *
     * @return void
     */
    public function testMapResultReturnsMappedData(): void
    {
        $company = [
            'company_number'     => '12345678',
            'name'               => 'Test Corp',
            'company_type'       => 'BV',
            'current_status'     => 'Active',
            'incorporation_date' => '2020-01-01',
            'registered_address' => [
                'street_address' => 'Main St 1',
                'locality'       => 'Amsterdam',
                'region'         => 'Noord-Holland',
                'postal_code'    => '1012AB',
            ],
            'industry_codes'     => [
                ['description' => 'Software'],
            ],
        ];

        $result = $this->mapper->mapResult($company);

        $this->assertSame('12345678', $result['kvkNumber']);
        $this->assertSame('Test Corp', $result['tradeName']);
        $this->assertSame('BV', $result['legalForm']);
        $this->assertTrue($result['isActive']);
        $this->assertSame('opencorporates', $result['source']);
        $this->assertSame('Amsterdam', $result['address']['city']);
        $this->assertSame('Software', $result['sbiDescription']);
    }//end testMapResultReturnsMappedData()

    /**
     * Test mapResult returns null without company number.
     *
     * @return void
     */
    public function testMapResultReturnsNullWithoutNumber(): void
    {
        $this->assertNull($this->mapper->mapResult([]));
    }//end testMapResultReturnsNullWithoutNumber()

    /**
     * Test mapResult handles inactive company.
     *
     * @return void
     */
    public function testMapResultInactive(): void
    {
        $company = [
            'company_number' => '99999999',
            'current_status' => 'Dissolved',
        ];

        $result = $this->mapper->mapResult($company);

        $this->assertFalse($result['isActive']);
    }//end testMapResultInactive()

    /**
     * Test mapResult handles missing address.
     *
     * @return void
     */
    public function testMapResultMissingAddress(): void
    {
        $company = [
            'company_number' => '11111111',
        ];

        $result = $this->mapper->mapResult($company);

        $this->assertSame('', $result['address']['city']);
        $this->assertSame('', $result['address']['street']);
    }//end testMapResultMissingAddress()
}//end class
