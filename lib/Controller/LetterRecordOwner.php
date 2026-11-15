<?php

/**
 * Pipelinq LetterRecordOwner.
 *
 * Whom the records a letter links to must belong to.
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
 * The client, register and ticket the ticket and appointment of a letter are checked against.
 *
 * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
 */
final class LetterRecordOwner
{


    /**
     * Constructor.
     *
     * @param string      $clientId The client id.
     * @param string      $register The register.
     * @param string|null $ticketId The letter's ticket, if any.
     *
     * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
     */
    public function __construct(
        public readonly string $clientId,
        public readonly string $register,
        public readonly ?string $ticketId,
    ) {

    }//end __construct()


}//end class
