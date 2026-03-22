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
     * The mapper under test.
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
     * Test that a complete company is mapped correctly.
     *
     * @return void
     */
    public function testMapResultMapsFullCompany(): void
    {
        $company = [
            'company_number'     => 'NL1',
            'name'               => 'Corp',
            'current_status'     => 'Active',
            'industry_codes'     => [['description' => 'Dev']],
            'registered_address' => ['locality' => 'City', 'region' => 'Region', 'postal_code' => '1234', 'street_address' => 'Str 1'],
        ];

        $result = $this->mapper->mapResult(company: $company);

        $this->assertSame('NL1', $result['kvkNumber']);
        $this->assertSame('Dev', $result['sbiDescription']);
        $this->assertSame('opencorporates', $result['source']);
        $this->assertTrue($result['isActive']);
    }//end testMapResultMapsFullCompany()

    /**
     * Test that missing company_number returns null.
     *
     * @return void
     */
    public function testMapResultReturnsNullWithoutNumber(): void
    {
        $this->assertNull($this->mapper->mapResult(company: ['name' => 'No']));
    }//end testMapResultReturnsNullWithoutNumber()

    /**
     * Test that inactive status maps correctly.
     *
     * @return void
     */
    public function testMapResultMapsInactiveCompany(): void
    {
        $this->assertFalse($this->mapper->mapResult(company: ['company_number' => 'X', 'current_status' => 'Dissolved'])['isActive']);
    }//end testMapResultMapsInactiveCompany()

    /**
     * Test that no industry codes returns empty sbiDescription.
     *
     * @return void
     */
    public function testMapResultHandlesNoIndustryCodes(): void
    {
        $this->assertSame('', $this->mapper->mapResult(company: ['company_number' => 'Y'])['sbiDescription']);
    }//end testMapResultHandlesNoIndustryCodes()
}//end class
