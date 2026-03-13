<?php

/**
 * Unit tests for ProspectScoringService.
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

use OCA\Pipelinq\Service\ProspectScoringService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ProspectScoringService.
 */
class ProspectScoringServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var ProspectScoringService
     */
    private ProspectScoringService $service;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->service = new ProspectScoringService();
    }//end setUp()

    /**
     * Test that a perfect prospect gets the maximum score of 100.
     *
     * @return void
     */
    public function testScorePerfectMatch(): void
    {
        $prospect = [
            'sbiCode'       => '6201',
            'employeeCount' => 50,
            'address'       => ['province' => 'Zuid-Holland'],
            'legalForm'     => 'BV',
            'isActive'      => true,
        ];

        $criteria = [
            'sbiCodes'         => ['6201'],
            'employeeCountMin' => 10,
            'employeeCountMax' => 100,
            'provinces'        => ['Zuid-Holland'],
            'legalForms'       => ['BV'],
        ];

        $result = $this->service->score(prospect: $prospect, criteria: $criteria);

        $this->assertSame(100, $result['fitScore']);
        $this->assertSame(30, $result['fitBreakdown']['sbiMatch']);
        $this->assertSame(25, $result['fitBreakdown']['employeeMatch']);
        $this->assertSame(20, $result['fitBreakdown']['locationMatch']);
        $this->assertSame(15, $result['fitBreakdown']['legalFormMatch']);
        $this->assertSame(10, $result['fitBreakdown']['activeMatch']);
    }//end testScorePerfectMatch()

    /**
     * Test that a prospect matching no criteria gets a score of 0.
     *
     * @return void
     */
    public function testScoreNoMatch(): void
    {
        $prospect = [
            'sbiCode'       => '9999',
            'employeeCount' => 5,
            'address'       => ['province' => 'Friesland'],
            'legalForm'     => 'Stichting',
            'isActive'      => false,
        ];

        $criteria = [
            'sbiCodes'         => ['6201'],
            'employeeCountMin' => 10,
            'employeeCountMax' => 100,
            'provinces'        => ['Zuid-Holland'],
            'legalForms'       => ['BV'],
        ];

        $result = $this->service->score(prospect: $prospect, criteria: $criteria);

        $this->assertSame(0, $result['fitScore']);
    }//end testScoreNoMatch()

    /**
     * Test that an empty prospect against empty criteria scores 0.
     *
     * @return void
     */
    public function testScoreEmptyData(): void
    {
        $result = $this->service->score(prospect: [], criteria: []);

        $this->assertSame(0, $result['fitScore']);
        $this->assertArrayHasKey('fitBreakdown', $result);
    }//end testScoreEmptyData()

    /**
     * Test that SBI prefix matching works (e.g. "6201" matches target "62").
     *
     * @return void
     */
    public function testScoreSbiPrefixMatch(): void
    {
        $prospect = [
            'sbiCode' => '6201',
            'isActive' => false,
        ];

        $criteria = [
            'sbiCodes' => ['62'],
        ];

        $result = $this->service->score(prospect: $prospect, criteria: $criteria);

        $this->assertSame(30, $result['fitBreakdown']['sbiMatch']);
    }//end testScoreSbiPrefixMatch()

    /**
     * Test that employee count open-ended range (no max) works.
     *
     * @return void
     */
    public function testScoreEmployeeCountNoMax(): void
    {
        $prospect = [
            'employeeCount' => 500,
            'isActive' => false,
        ];

        $criteria = [
            'employeeCountMin' => 10,
            'employeeCountMax' => 0,
        ];

        $result = $this->service->score(prospect: $prospect, criteria: $criteria);

        $this->assertSame(25, $result['fitBreakdown']['employeeMatch']);
    }//end testScoreEmployeeCountNoMax()

    /**
     * Test that location matching is case-insensitive.
     *
     * @return void
     */
    public function testScoreLocationCaseInsensitive(): void
    {
        $prospect = [
            'address' => ['province' => ' NOORD-HOLLAND '],
            'isActive' => false,
        ];

        $criteria = [
            'provinces' => ['noord-holland'],
        ];

        $result = $this->service->score(prospect: $prospect, criteria: $criteria);

        $this->assertSame(20, $result['fitBreakdown']['locationMatch']);
    }//end testScoreLocationCaseInsensitive()

    /**
     * Test that scoreAll returns prospects sorted by fitScore descending.
     *
     * @return void
     */
    public function testScoreAllSortsByFitScoreDescending(): void
    {
        $prospects = [
            ['sbiCode' => '9999', 'isActive' => false],
            ['sbiCode' => '6201', 'isActive' => true],
            ['sbiCode' => '6201', 'isActive' => false],
        ];

        $criteria = [
            'sbiCodes' => ['6201'],
        ];

        $result = $this->service->scoreAll(prospects: $prospects, criteria: $criteria);

        $this->assertCount(3, $result);
        // First: SBI match + active = 40.
        $this->assertSame(40, $result[0]['fitScore']);
        // Second: SBI match only = 30.
        $this->assertSame(30, $result[1]['fitScore']);
        // Third: no match = 0.
        $this->assertSame(0, $result[2]['fitScore']);
    }//end testScoreAllSortsByFitScoreDescending()

    /**
     * Test that null employeeCount yields 0 for employee match.
     *
     * @return void
     */
    public function testScoreNullEmployeeCount(): void
    {
        $prospect = [
            'isActive' => false,
        ];

        $criteria = [
            'employeeCountMin' => 10,
            'employeeCountMax' => 100,
        ];

        $result = $this->service->score(prospect: $prospect, criteria: $criteria);

        $this->assertSame(0, $result['fitBreakdown']['employeeMatch']);
    }//end testScoreNullEmployeeCount()

    /**
     * Test partial match: only some criteria match.
     *
     * @return void
     */
    public function testScorePartialMatch(): void
    {
        $prospect = [
            'sbiCode'       => '6201',
            'employeeCount' => 50,
            'address'       => ['province' => 'Friesland'],
            'legalForm'     => 'NV',
            'isActive'      => true,
        ];

        $criteria = [
            'sbiCodes'         => ['6201'],
            'employeeCountMin' => 10,
            'employeeCountMax' => 100,
            'provinces'        => ['Zuid-Holland'],
            'legalForms'       => ['BV'],
        ];

        $result = $this->service->score(prospect: $prospect, criteria: $criteria);

        // SBI (30) + employee (25) + active (10) = 65, no location or legal form.
        $this->assertSame(65, $result['fitScore']);
        $this->assertSame(0, $result['fitBreakdown']['locationMatch']);
        $this->assertSame(0, $result['fitBreakdown']['legalFormMatch']);
    }//end testScorePartialMatch()
}//end class
