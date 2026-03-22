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
     * Test that a complete OpenCorporates result is mapped correctly.
     *
     * @return void
     */
    public function testMapResultMapsFullCompany(): void
    {
        $company = [
            'company_number'     => 'NL12345678',
            'name'               => 'Acme Corp',
            'company_type'       => 'BV',
            'current_status'     => 'Active',
            'industry_codes'     => [['description' => 'Software']],
            'registered_address' => ['locality' => 'Rotterdam', 'region' => 'Zuid-Holland', 'postal_code' => '3000AA', 'street_address' => 'Tech 1'],
        ];

        $result = $this->mapper->mapResult(company: $company);

        $this->assertNotNull($result);
        $this->assertSame('NL12345678', $result['kvkNumber']);
        $this->assertSame('Software', $result['sbiDescription']);
        $this->assertTrue($result['isActive']);
        $this->assertSame('opencorporates', $result['source']);
    }//end testMapResultMapsFullCompany()

    /**
     * Test that a company without company_number returns null.
     *
     * @return void
     */
    public function testMapResultReturnsNullWithoutCompanyNumber(): void
    {
        $this->assertNull($this->mapper->mapResult(company: ['name' => 'No Number']));
    }//end testMapResultReturnsNullWithoutCompanyNumber()

    /**
     * Test that a dissolved company maps isActive as false.
     *
     * @return void
     */
    public function testMapResultMapsInactiveCompany(): void
    {
        $result = $this->mapper->mapResult(company: ['company_number' => 'NL99', 'current_status' => 'Dissolved']);

        $this->assertFalse($result['isActive']);
    }//end testMapResultMapsInactiveCompany()

    /**
     * Test that a company with no industry_codes gets empty sbiDescription.
     *
     * @return void
     */
    public function testMapResultHandlesNoIndustryCodes(): void
    {
        $result = $this->mapper->mapResult(company: ['company_number' => 'NL1']);

        $this->assertSame('', $result['sbiDescription']);
    }//end testMapResultHandlesNoIndustryCodes()
}//end class
