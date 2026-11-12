<?php

/**
 * LoggedLetter: the letter LetterLog records as a contact moment.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Letter
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/client-letters/spec.md#requirement-a-letter-is-logged-on-the-client-req-wlt-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Letter;

/**
 * A letter made for a client: who it is for, where it came from, where its copy is.
 *
 * @spec openspec/specs/client-letters/spec.md#requirement-a-letter-is-logged-on-the-client-req-wlt-002
 */
final class LoggedLetter
{


    /**
     * Constructor.
     *
     * @param string      $clientId     The client id.
     * @param string|null $contactId    The contact person, if one was chosen.
     * @param string|null $ticketId     The ticket the letter was made from, if any.
     * @param string      $templateName The template's name.
     * @param string|null $path         Where filinq filed the copy.
     */
    public function __construct(
        public readonly string $clientId,
        public readonly ?string $contactId,
        public readonly ?string $ticketId,
        public readonly string $templateName,
        public readonly ?string $path,
    ) {

    }//end __construct()


}//end class
