<?php

/**
 * Pipelinq MetricsFormatter.
 *
 * Formats metrics data as Prometheus text exposition format.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * Formats metrics data into Prometheus text exposition format.
 */
class MetricsFormatter
{
    /**
     * Format app info metrics.
     *
     * @param string $version    The app version.
     * @param string $phpVersion The PHP version.
     *
     * @return array The formatted metric lines.
     */
    public function formatAppInfo(string $version, string $phpVersion): array
    {
        return [
            '# HELP pipelinq_info Application information',
            '# TYPE pipelinq_info gauge',
            'pipelinq_info{version="'.$version.'",php_version="'.$phpVersion.'"} 1',
            '',
            '# HELP pipelinq_up Whether the application is healthy',
            '# TYPE pipelinq_up gauge',
            'pipelinq_up 1',
            '',
        ];
    }//end formatAppInfo()

    /**
     * Format lead count metrics.
     *
     * @param array $leadCounts The lead count data rows.
     *
     * @return array The formatted metric lines.
     */
    public function formatLeadCounts(array $leadCounts): array
    {
        $lines   = [];
        $lines[] = '# HELP pipelinq_leads_total Total leads by status and pipeline';
        $lines[] = '# TYPE pipelinq_leads_total gauge';

        foreach ($leadCounts as $row) {
            $status   = $this->sanitizeLabel(value: $row['status']);
            $pipeline = $this->sanitizeLabel(value: $row['pipeline']);
            $count    = (int) $row['cnt'];
            $lines[]  = 'pipelinq_leads_total{status="'.$status.'",pipeline="'.$pipeline.'"} '.$count;
        }

        $lines[] = '';

        return $lines;
    }//end formatLeadCounts()

    /**
     * Format lead value metrics.
     *
     * @param array $valueCounts The lead value data rows.
     *
     * @return array The formatted metric lines.
     */
    public function formatLeadValues(array $valueCounts): array
    {
        $lines   = [];
        $lines[] = '# HELP pipelinq_leads_value_total Total pipeline value in EUR';
        $lines[] = '# TYPE pipelinq_leads_value_total gauge';

        foreach ($valueCounts as $row) {
            $pipeline = $this->sanitizeLabel(value: $row['pipeline']);
            $value    = (float) $row['total_value'];
            $lines[]  = 'pipelinq_leads_value_total{pipeline="'.$pipeline.'"} '.$value;
        }

        $lines[] = '';

        return $lines;
    }//end formatLeadValues()

    /**
     * Format a simple gauge metric.
     *
     * @param string $name  The metric name.
     * @param string $help  The metric help text.
     * @param int    $value The metric value.
     *
     * @return array The formatted metric lines.
     */
    public function formatGauge(string $name, string $help, int $value): array
    {
        return [
            '# HELP '.$name.' '.$help,
            '# TYPE '.$name.' gauge',
            $name.' '.$value,
            '',
        ];
    }//end formatGauge()

    /**
     * Format request count metrics.
     *
     * @param array $requestCounts The request count data rows.
     *
     * @return array The formatted metric lines.
     */
    public function formatRequestCounts(array $requestCounts): array
    {
        $lines   = [];
        $lines[] = '# HELP pipelinq_service_requests_total Total service requests by status';
        $lines[] = '# TYPE pipelinq_service_requests_total gauge';

        foreach ($requestCounts as $row) {
            $status  = $this->sanitizeLabel(value: $row['status']);
            $count   = (int) $row['cnt'];
            $lines[] = 'pipelinq_service_requests_total{status="'.$status.'"} '.$count;
        }

        $lines[] = '';

        return $lines;
    }//end formatRequestCounts()

    /**
     * Sanitize a label value for Prometheus format.
     *
     * @param string $value The label value.
     *
     * @return string Sanitized label value.
     */
    private function sanitizeLabel(string $value): string
    {
        return str_replace(
            ['\\', '"', "\n"],
            ['\\\\', '\\"', '\\n'],
            $value
        );
    }//end sanitizeLabel()
}//end class
