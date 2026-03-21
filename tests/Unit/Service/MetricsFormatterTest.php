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
}//end class
