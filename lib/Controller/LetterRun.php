<?php

/**
 * Pipelinq LetterRun.
 *
 * What one letter or letter preview for a client is made from.
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
 * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

/**
 * One letter (or preview) request: the client and the template asked for,
 * and, once read, the records, the template and the file name.
 *
 * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
 */
final class LetterRun
{

    /**
     * The records read as the caller (`client`, `contactId`, `ticketId`, `refs`).
     *
     * @var array<string, mixed>
     */
    public array $records = [];

    /**
     * The filinq template.
     *
     * @var array<string, mixed>
     */
    public array $template = [];

    /**
     * The letter's file name, without extension.
     *
     * @var string
     */
    public string $filename = '';


    /**
     * Constructor.
     *
     * @param string $clientId   The client id.
     * @param string $templateId The filinq template id.
     */
    public function __construct(
        public readonly string $clientId,
        public readonly string $templateId,
    ) {

    }//end __construct()


}//end class
