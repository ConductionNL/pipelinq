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
            'company_number'      => 'NL12345678',
            'name'                => 'Acme Corp',
            'company_type'        => 'Private Limited Company',
            'incorporation_date'  => '2005-06-15',
            'current_status'      => 'Active',
            'industry_codes'      => [
                ['description' => 'Software development'],
                ['description' => 'IT consulting'],
            ],
            'registered_address'  => [
                'street_address' => 'Tech Street 1',
                'locality'       => 'Rotterdam',
                'region'         => 'Zuid-Holland',
                'postal_code'    => '3000AA',
            ],
        ];

        $result = $this->mapper->mapResult(company: $company);

        $this->assertNotNull($result);
        $this->assertSame('NL12345678', $result['kvkNumber']);
        $this->assertSame('Software', $result['sbiDescription']);
        $this->assertTrue($result['isActive']);
        $this->assertSame('opencorporates', $result['source']);
        $this->assertSame('Acme Corp', $result['tradeName']);
        $this->assertSame('Private Limited Company', $result['legalForm']);
        $this->assertSame('', $result['sbiCode']);
        $this->assertSame('Software development', $result['sbiDescription']);
        $this->assertNull($result['employeeCount']);
        $this->assertSame('2005-06-15', $result['registrationDate']);
        $this->assertTrue($result['isActive']);
        $this->assertSame('opencorporates', $result['source']);
        $this->assertSame('Rotterdam', $result['address']['city']);
        $this->assertSame('Zuid-Holland', $result['address']['province']);
        $this->assertSame('3000AA', $result['address']['postalCode']);
        $this->assertSame('Tech Street 1', $result['address']['street']);
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
        $company = [
            'name' => 'No Number Corp',
        ];

        $result = $this->mapper->mapResult(company: $company);

        $this->assertNull($result);
    }//end testMapResultReturnsNullWithoutCompanyNumber()

    /**
     * Test that a non-active company maps isActive as false.
     *
     * @return void
     */
    public function testMapResultMapsInactiveCompany(): void
    {
        $result = $this->mapper->mapResult(company: ['company_number' => 'NL99', 'current_status' => 'Dissolved']);

        $company = [
            'company_number' => 'NL99999999',
            'current_status' => 'Dissolved',
        ];

        $result = $this->mapper->mapResult(company: $company);

        $this->assertNotNull($result);
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
        $company = [
            'company_number' => 'NL11111111',
        ];

        $result = $this->mapper->mapResult(company: $company);

        $this->assertNotNull($result);
        $this->assertSame('', $result['sbiDescription']);
    }//end testMapResultHandlesNoIndustryCodes()

    /**
     * Test that a company with empty registered_address maps to empty address fields.
     *
     * @return void
     */
    public function testMapResultHandlesEmptyAddress(): void
    {
        $company = [
            'company_number' => 'NL22222222',
        ];

        $result = $this->mapper->mapResult(company: $company);

        $this->assertNotNull($result);
        $this->assertSame('', $result['address']['street']);
        $this->assertSame('', $result['address']['city']);
        $this->assertSame('', $result['address']['province']);
        $this->assertSame('', $result['address']['postalCode']);
    }//end testMapResultHandlesEmptyAddress()

    /**
     * Test that a minimal company with only company_number returns a valid record.
     *
     * @return void
     */
    public function testMapResultHandlesMinimalCompany(): void
    {
        $company = ['company_number' => 'UK000001'];

        $result = $this->mapper->mapResult(company: $company);

        $this->assertNotNull($result);
        $this->assertSame('UK000001', $result['kvkNumber']);
        $this->assertSame('', $result['tradeName']);
        $this->assertSame('opencorporates', $result['source']);
        $this->assertNull($result['website']);
        $this->assertTrue($result['isActive']); // Default 'Active'.
    }//end testMapResultHandlesMinimalCompany()
}//end class
