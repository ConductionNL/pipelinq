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
     * Test formatAppInfo returns correct Prometheus format.
     *
     * @return void
     */
    public function testFormatAppInfoReturnsPrometheusFormat(): void
    {
        $result = $this->formatter->formatAppInfo('1.0.0', '8.2.0');

        $this->assertContains('# HELP pipelinq_info Application information', $result);
        $this->assertContains('# TYPE pipelinq_info gauge', $result);
        $this->assertContains('pipelinq_info{version="1.0.0",php_version="8.2.0"} 1', $result);
        $this->assertContains('# HELP pipelinq_up Whether the application is healthy', $result);
        $this->assertContains('pipelinq_up 1', $result);
    }//end testFormatAppInfoReturnsPrometheusFormat()

    /**
     * Test formatLeadCounts with multiple rows.
     *
     * @return void
     */
    public function testFormatLeadCountsMultipleRows(): void
    {
        $counts = [
            ['status' => 'open', 'pipeline' => 'Sales', 'cnt' => '5'],
            ['status' => 'won', 'pipeline' => 'Sales', 'cnt' => '3'],
        ];

        $result = $this->formatter->formatLeadCounts($counts);

        $this->assertContains('# HELP pipelinq_leads_total Total leads by status and pipeline', $result);
        $this->assertContains('# TYPE pipelinq_leads_total gauge', $result);
        $this->assertContains('pipelinq_leads_total{status="open",pipeline="Sales"} 5', $result);
        $this->assertContains('pipelinq_leads_total{status="won",pipeline="Sales"} 3', $result);
    }//end testFormatLeadCountsMultipleRows()

    /**
     * Test formatLeadCounts with empty input.
     *
     * @return void
     */
    public function testFormatLeadCountsEmpty(): void
    {
        $result = $this->formatter->formatLeadCounts([]);

        $this->assertContains('# HELP pipelinq_leads_total Total leads by status and pipeline', $result);
        $this->assertContains('# TYPE pipelinq_leads_total gauge', $result);
        $this->assertCount(3, $result);
    }//end testFormatLeadCountsEmpty()

    /**
     * Test formatLeadValues produces correct output.
     *
     * @return void
     */
    public function testFormatLeadValues(): void
    {
        $values = [
            ['pipeline' => 'Sales', 'total_value' => '150000.50'],
        ];

        $result = $this->formatter->formatLeadValues($values);

        $this->assertContains('# HELP pipelinq_leads_value_total Total pipeline value in EUR', $result);
        $this->assertContains('pipelinq_leads_value_total{pipeline="Sales"} 150000.5', $result);
    }//end testFormatLeadValues()

    /**
     * Test formatGauge produces a simple metric.
     *
     * @return void
     */
    public function testFormatGauge(): void
    {
        $result = $this->formatter->formatGauge('pipelinq_clients_total', 'Total clients', 42);

        $this->assertContains('# HELP pipelinq_clients_total Total clients', $result);
        $this->assertContains('# TYPE pipelinq_clients_total gauge', $result);
        $this->assertContains('pipelinq_clients_total 42', $result);
    }//end testFormatGauge()

    /**
     * Test formatRequestCounts with data.
     *
     * @return void
     */
    public function testFormatRequestCounts(): void
    {
        $counts = [
            ['status' => 'new', 'cnt' => '10'],
            ['status' => 'in_progress', 'cnt' => '7'],
        ];

        $result = $this->formatter->formatRequestCounts($counts);

        $this->assertContains('pipelinq_service_requests_total{status="new"} 10', $result);
        $this->assertContains('pipelinq_service_requests_total{status="in_progress"} 7', $result);
    }//end testFormatRequestCounts()

    /**
     * Test label sanitization escapes special characters.
     *
     * @return void
     */
    public function testLabelSanitization(): void
    {
        $counts = [
            ['status' => 'status"with"quotes', 'pipeline' => "line\nbreak", 'cnt' => '1'],
        ];

        $result = $this->formatter->formatLeadCounts($counts);

        // Quotes and newlines should be escaped.
        $found = false;
        foreach ($result as $line) {
            if (str_contains($line, 'status\\"with\\"quotes') === true
                && str_contains($line, 'line\\nbreak') === true
            ) {
                $found = true;
            }
        }

        $this->assertTrue($found, 'Special characters should be escaped in Prometheus labels');
    }//end testLabelSanitization()

    /**
     * Test that formatAppInfo returns the correct Prometheus lines.
     *
     * @return void
     */
    public function testFormatAppInfoReturnsCorrectLines(): void
    {
        $lines = $this->formatter->formatAppInfo(version: '1.0.0', phpVersion: '8.1.0');

        $this->assertIsArray($lines);
        $this->assertContains('# HELP pipelinq_info Application information', $lines);
        $this->assertContains('pipelinq_info{version="1.0.0",php_version="8.1.0"} 1', $lines);
        $this->assertContains('# TYPE pipelinq_info gauge', $lines);
        $this->assertContains('pipelinq_info{version="1.0.0",php_version="8.1.0"} 1', $lines);
        $this->assertContains('# HELP pipelinq_up Whether the application is healthy', $lines);
        $this->assertContains('pipelinq_up 1', $lines);
    }//end testFormatAppInfoReturnsCorrectLines()

    /**
     * Test that formatLeadCounts returns gauge lines.
     * Test that formatLeadCounts returns correct gauge lines.
     * Test that formatLeadCounts returns gauge lines for each row.
     * Test that formatLeadCounts returns correct gauge lines for each row.
     *
     * @return void
     */
    public function testFormatLeadCountsReturnsGaugeLines(): void
    {
        $rows  = [['status' => 'new', 'pipeline' => 'Default', 'cnt' => '5']];
        $lines = $this->formatter->formatLeadCounts(leadCounts: $rows);
        $lines = $this->formatter->formatLeadCounts(leadCounts: [['status' => 'new', 'pipeline' => 'Default', 'cnt' => '5']]);

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
        $lines     = $this->formatter->formatLeadCounts(leadCounts: []);
        $dataLines = array_filter($lines, fn($l) => str_starts_with($l, 'pipelinq_leads_total{'));

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
        $lines = $this->formatter->formatRequestCounts(requestCounts: [['status' => 'open', 'cnt' => '10']]);

        $this->assertContains('pipelinq_service_requests_total{status="open"} 10', $lines);
    }//end testFormatRequestCountsFormatsStatusCounts()

    /**
     * Test that special characters in labels are sanitized.
     * Test that labels with special characters are sanitized.
     *
     * @return void
     */
    public function testSanitizesSpecialCharsInLabels(): void
    {
        $lines    = $this->formatter->formatLeadCounts(leadCounts: [['status' => 'test"val', 'pipeline' => "br\nak", 'cnt' => '1']]);
        $dataLine = array_values(array_filter($lines, fn($l) => str_starts_with($l, 'pipelinq_leads_total{')))[0] ?? '';

        $this->assertStringContainsString('\\"', $dataLine);
    }//end testSanitizesSpecialCharsInLabels()
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
        $rows     = [['status' => 'test"value', 'pipeline' => "line\nbreak", 'cnt' => '1']];
        $lines    = $this->formatter->formatLeadCounts(leadCounts: $rows);
        $dataLine = array_values(array_filter($lines, fn($l) => str_starts_with($l, 'pipelinq_leads_total{')))[0] ?? '';

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
