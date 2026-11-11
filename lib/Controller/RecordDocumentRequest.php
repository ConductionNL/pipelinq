<?php

/**
 * Pipelinq RecordDocumentRequest.
 *
 * A record whose document is asked for, with its schema's document configuration.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/record-documents/spec.md#requirement-a-configured-record-type-has-a-document
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

/**
 * A record whose document is asked for, with its schema's document configuration.
 *
 * @spec openspec/specs/record-documents/spec.md#requirement-a-configured-record-type-has-a-document
 */
final class RecordDocumentRequest
{


    /**
     * Constructor.
     *
     * @param RecordRef            $record The record.
     * @param array<string, mixed> $config The schema's template, owner field and lists.
     */
    public function __construct(
        public readonly RecordRef $record,
        public readonly array $config,
    ) {

    }//end __construct()


}//end class
