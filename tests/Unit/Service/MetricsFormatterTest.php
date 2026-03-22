<?php

/**
 * Unit tests for MetricsFormatter.
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

use OCA\Pipelinq\Service\MetricsFormatter;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MetricsFormatter.
 */
class MetricsFormatterTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var MetricsFormatter
     */
    private MetricsFormatter $formatter;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->formatter = new MetricsFormatter();
    }//end setUp()

    /**
     * Test that formatAppInfo returns the correct Prometheus lines.
     *
     * @return void
     */
    public function testFormatAppInfoReturnsCorrectLines(): void
    {
        $lines = $this->formatter->formatAppInfo(version: '1.0.0', phpVersion: '8.1.0');

        $this->assertContains('# HELP pipelinq_info Application information', $lines);
        $this->assertContains('pipelinq_info{version="1.0.0",php_version="8.1.0"} 1', $lines);
        $this->assertIsArray($lines);
        $this->assertContains('# HELP pipelinq_info Application information', $lines);
        $this->assertContains('# TYPE pipelinq_info gauge', $lines);
        $this->assertContains('pipelinq_info{version="1.0.0",php_version="8.1.0"} 1', $lines);
        $this->assertContains('# HELP pipelinq_up Whether the application is healthy', $lines);
        $this->assertContains('pipelinq_up 1', $lines);
    }//end testFormatAppInfoReturnsCorrectLines()

    /**
     * Test that formatLeadCounts returns correct gauge lines.
     * Test that formatLeadCounts returns correct gauge lines for each row.
     *
     * @return void
     */
    public function testFormatLeadCountsReturnsGaugeLines(): void
    {
        $rows  = [['status' => 'new', 'pipeline' => 'Default', 'cnt' => '5']];
        $lines = $this->formatter->formatLeadCounts(leadCounts: $rows);

        $this->assertContains('pipelinq_leads_total{status="new",pipeline="Default"} 5', $lines);
    }//end testFormatLeadCountsReturnsGaugeLines()

    /**
     * Test that formatGauge returns the correct lines.
        $rows = [
            ['status' => 'new', 'pipeline' => 'Default', 'cnt' => '5'],
            ['status' => 'won', 'pipeline' => 'Default', 'cnt' => '3'],
        ];

        $lines = $this->formatter->formatLeadCounts(leadCounts: $rows);

        $this->assertContains('# HELP pipelinq_leads_total Total leads by status and pipeline', $lines);
        $this->assertContains('# TYPE pipelinq_leads_total gauge', $lines);
        $this->assertContains('pipelinq_leads_total{status="new",pipeline="Default"} 5', $lines);
        $this->assertContains('pipelinq_leads_total{status="won",pipeline="Default"} 3', $lines);
    }//end testFormatLeadCountsReturnsGaugeLines()

    /**
     * Test that formatLeadCounts with empty input returns only header lines.
     *
     * @return void
     */
    public function testFormatLeadCountsEmptyInput(): void
    {
        $lines = $this->formatter->formatLeadCounts(leadCounts: []);

        $this->assertContains('# HELP pipelinq_leads_total Total leads by status and pipeline', $lines);
        // No data lines beyond the header.
        $dataLines = array_filter($lines, fn($l) => str_starts_with($l, 'pipelinq_leads_total{'));
        $this->assertEmpty($dataLines);
    }//end testFormatLeadCountsEmptyInput()

    /**
     * Test that formatLeadValues formats pipeline values correctly.
     *
     * @return void
     */
    public function testFormatLeadValuesFormatsPipelineValues(): void
    {
        $rows = [
            ['pipeline' => 'Default', 'total_value' => '12500.50'],
        ];

        $lines = $this->formatter->formatLeadValues(valueCounts: $rows);

        $this->assertContains('# HELP pipelinq_leads_value_total Total pipeline value in EUR', $lines);
        $this->assertContains('# TYPE pipelinq_leads_value_total gauge', $lines);
        $this->assertContains('pipelinq_leads_value_total{pipeline="Default"} 12500.5', $lines);
    }//end testFormatLeadValuesFormatsPipelineValues()

    /**
     * Test that formatGauge returns the correct three lines plus blank.
     *
     * @return void
     */
    public function testFormatGaugeReturnsCorrectLines(): void
    {
        $lines = $this->formatter->formatGauge(name: 'pipelinq_contacts_total', help: 'Total contacts', value: 42);

        $this->assertSame(
            ['# HELP pipelinq_contacts_total Total contacts', '# TYPE pipelinq_contacts_total gauge', 'pipelinq_contacts_total 42', ''],
        $lines = $this->formatter->formatGauge(
            name: 'pipelinq_contacts_total',
            help: 'Total contacts',
            value: 42
        );

        $this->assertSame(
            [
                '# HELP pipelinq_contacts_total Total contacts',
                '# TYPE pipelinq_contacts_total gauge',
                'pipelinq_contacts_total 42',
                '',
            ],
            $lines
        );
    }//end testFormatGaugeReturnsCorrectLines()

    /**
     * Test that formatRequestCounts formats request status counts.
     *
     * @return void
     */
    public function testFormatRequestCountsFormatsStatusCounts(): void
    {
        $rows  = [['status' => 'open', 'cnt' => '10']];
        $lines = $this->formatter->formatRequestCounts(requestCounts: $rows);

        $this->assertContains('pipelinq_service_requests_total{status="open"} 10', $lines);
        $rows = [
            ['status' => 'open', 'cnt' => '10'],
            ['status' => 'closed', 'cnt' => '7'],
        ];

        $lines = $this->formatter->formatRequestCounts(requestCounts: $rows);

        $this->assertContains('pipelinq_service_requests_total{status="open"} 10', $lines);
        $this->assertContains('pipelinq_service_requests_total{status="closed"} 7', $lines);
    }//end testFormatRequestCountsFormatsStatusCounts()

    /**
     * Test that label values with special characters are sanitized.
     *
     * @return void
     */
    public function testFormatLeadCountsSanitizesSpecialChars(): void
    {
        $rows     = [['status' => 'test"value', 'pipeline' => "line\nbreak", 'cnt' => '1']];
        $lines    = $this->formatter->formatLeadCounts(leadCounts: $rows);
        $dataLine = array_values(array_filter($lines, fn($l) => str_starts_with($l, 'pipelinq_leads_total{')))[0] ?? '';

        $this->assertStringContainsString('\\"', $dataLine);
    }//end testFormatLeadCountsSanitizesSpecialChars()
    public function testFormatLeadCountsSanitizesSpecialCharsInLabels(): void
    {
        $rows = [
            ['status' => 'test"value', 'pipeline' => "line\nbreak", 'cnt' => '1'],
        ];

        $lines = $this->formatter->formatLeadCounts(leadCounts: $rows);

        // Double-quotes and newlines must be escaped in label values.
        $dataLine = array_values(array_filter($lines, fn($l) => str_starts_with($l, 'pipelinq_leads_total{')))[0] ?? '';
        $this->assertStringNotContainsString('"test"value"', $dataLine);
        $this->assertStringContainsString('\\"', $dataLine);
    }//end testFormatLeadCountsSanitizesSpecialCharsInLabels()
}//end class
