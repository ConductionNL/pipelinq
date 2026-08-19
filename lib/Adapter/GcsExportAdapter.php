<?php

/**
 * Pipelinq GcsExportAdapter.
 *
 * Export sink adapter for Google Cloud Storage. Surfaces the written object's
 * generation/metadata as the destination acknowledgement.
 *
 * @category Adapter
 * @package  OCA\Pipelinq\Adapter
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-008-01
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Adapter;

/**
 * Google Cloud Storage export sink adapter.
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-008-01
 */
class GcsExportAdapter extends AbstractOpenConnectorSink
{
    /**
     * The destination type slug.
     *
     * @return string The type ('gcs').
     */
    public function getType(): string
    {
        return 'gcs';
    }//end getType()

    /**
     * Surface the GCS object generation/metadata as the acknowledgement.
     *
     * @param array<string, mixed> $result The transfer result metadata.
     *
     * @return string The object metadata (or a generic ack when absent).
     */
    protected function acknowledge(array $result): string
    {
        return (string) ($result['generation'] ?? $result['md5Hash'] ?? ($result['path'] ?? ''));
    }//end acknowledge()
}//end class
