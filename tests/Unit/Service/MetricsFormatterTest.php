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
     * The formatter under test.
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
     * Test that formatAppInfo returns correct Prometheus format.
     *
     * @return void
     */
    public function testFormatAppInfo(): void
    {
        $lines = $this->formatter->formatAppInfo(version: '1.2.0', phpVersion: '8.2.15');

        $output = implode("\n", $lines);
        $this->assertStringContainsString('# HELP pipelinq_info', $output);
        $this->assertStringContainsString('# TYPE pipelinq_info gauge', $output);
        $this->assertStringContainsString('pipelinq_info{version="1.2.0",php_version="8.2.15"} 1', $output);
        $this->assertStringContainsString('pipelinq_up 1', $output);
    }//end testFormatAppInfo()

    /**
     * Test that formatGauge returns correct Prometheus format.
     *
     * @return void
     */
    public function testFormatGauge(): void
    {
        $lines = $this->formatter->formatGauge(
            name: 'pipelinq_clients_total',
            help: 'Total clients',
            value: 250
        );

        $output = implode("\n", $lines);
        $this->assertStringContainsString('# HELP pipelinq_clients_total Total clients', $output);
        $this->assertStringContainsString('# TYPE pipelinq_clients_total gauge', $output);
        $this->assertStringContainsString('pipelinq_clients_total 250', $output);
    }//end testFormatGauge()

    /**
     * Test that formatLeadCounts handles data rows correctly.
     *
     * @return void
     */
    public function testFormatLeadCounts(): void
    {
        $counts = [
            ['status' => 'new', 'pipeline' => 'sales', 'cnt' => '40'],
            ['status' => 'won', 'pipeline' => 'sales', 'cnt' => '15'],
        ];

        $lines  = $this->formatter->formatLeadCounts(leadCounts: $counts);
        $output = implode("\n", $lines);

        $this->assertStringContainsString('pipelinq_leads_total{status="new",pipeline="sales"} 40', $output);
        $this->assertStringContainsString('pipelinq_leads_total{status="won",pipeline="sales"} 15', $output);
    }//end testFormatLeadCounts()

    /**
     * Test that formatLeadCounts handles empty input.
     *
     * @return void
     */
    public function testFormatLeadCountsEmpty(): void
    {
        $lines  = $this->formatter->formatLeadCounts(leadCounts: []);
        $output = implode("\n", $lines);

        $this->assertStringContainsString('# HELP pipelinq_leads_total', $output);
        $this->assertStringContainsString('# TYPE pipelinq_leads_total gauge', $output);
    }//end testFormatLeadCountsEmpty()

    /**
     * Test that formatConversionRates returns correct Prometheus format.
     *
     * @return void
     */
    public function testFormatConversionRates(): void
    {
        $rates = [
            ['pipeline' => 'sales', 'won' => 15, 'resolved' => 25, 'rate' => 0.6],
        ];

        $lines  = $this->formatter->formatConversionRates(rates: $rates);
        $output = implode("\n", $lines);

        $this->assertStringContainsString('# HELP pipelinq_conversion_rate', $output);
        $this->assertStringContainsString('# TYPE pipelinq_conversion_rate gauge', $output);
        $this->assertStringContainsString('pipelinq_conversion_rate{pipeline="sales"} 0.6', $output);
    }//end testFormatConversionRates()

    /**
     * Test that formatConversionRates handles empty pipelines.
     *
     * @return void
     */
    public function testFormatConversionRatesEmpty(): void
    {
        $lines  = $this->formatter->formatConversionRates(rates: []);
        $output = implode("\n", $lines);

        $this->assertStringContainsString('# HELP pipelinq_conversion_rate', $output);
        $this->assertStringNotContainsString('pipelinq_conversion_rate{', $output);
    }//end testFormatConversionRatesEmpty()

    /**
     * Test that formatDependencyUp returns correct gauge for available dependency.
     *
     * @return void
     */
    public function testFormatDependencyUpAvailable(): void
    {
        $lines  = $this->formatter->formatDependencyUp(name: 'openregister', up: true);
        $output = implode("\n", $lines);

        $this->assertStringContainsString('pipelinq_dependency_up{dependency="openregister"} 1', $output);
    }//end testFormatDependencyUpAvailable()

    /**
     * Test that formatDependencyUp returns correct gauge for unavailable dependency.
     *
     * @return void
     */
    public function testFormatDependencyUpUnavailable(): void
    {
        $lines  = $this->formatter->formatDependencyUp(name: 'openregister', up: false);
        $output = implode("\n", $lines);

        $this->assertStringContainsString('pipelinq_dependency_up{dependency="openregister"} 0', $output);
    }//end testFormatDependencyUpUnavailable()

    /**
     * Test that formatLeadValues handles data correctly.
     *
     * @return void
     */
    public function testFormatLeadValues(): void
    {
        $values = [
            ['pipeline' => 'enterprise', 'total_value' => '150000.5'],
        ];

        $lines  = $this->formatter->formatLeadValues(valueCounts: $values);
        $output = implode("\n", $lines);

        $this->assertStringContainsString('pipelinq_leads_value_total{pipeline="enterprise"} 150000.5', $output);
    }//end testFormatLeadValues()

    /**
     * Test that formatRequestCounts handles data correctly.
     *
     * @return void
     */
    public function testFormatRequestCounts(): void
    {
        $counts = [
            ['status' => 'open', 'cnt' => '80'],
            ['status' => 'closed', 'cnt' => '120'],
        ];

        $lines  = $this->formatter->formatRequestCounts(requestCounts: $counts);
        $output = implode("\n", $lines);

        $this->assertStringContainsString('pipelinq_service_requests_total{status="open"} 80', $output);
        $this->assertStringContainsString('pipelinq_service_requests_total{status="closed"} 120', $output);
    }//end testFormatRequestCounts()

    /**
     * Test that label sanitization escapes special characters.
     *
     * @return void
     */
    public function testLabelSanitization(): void
    {
        $counts = [
            ['status' => 'test"value', 'pipeline' => "line\nbreak", 'cnt' => '1'],
        ];

        $lines  = $this->formatter->formatLeadCounts(leadCounts: $counts);
        $output = implode("\n", $lines);

        $this->assertStringContainsString('status="test\\"value"', $output);
        $this->assertStringContainsString('pipeline="line\\nbreak"', $output);
    }//end testLabelSanitization()
}//end class
