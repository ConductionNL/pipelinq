<?php

/**
 * Pipelinq RecordRef.
 *
 * One record by its schema slug and id.
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
 * One record by its schema slug and id.
 *
 * @spec openspec/specs/record-documents/spec.md#requirement-a-configured-record-type-has-a-document
 */
final class RecordRef
{


    /**
     * Constructor.
     *
     * @param string $schema The schema slug.
     * @param string $id     The record id.
     */
    public function __construct(
        public readonly string $schema,
        public readonly string $id,
    ) {

    }//end __construct()


}//end class
