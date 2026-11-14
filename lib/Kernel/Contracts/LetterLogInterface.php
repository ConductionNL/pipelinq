<?php

/**
 * Contract of LetterLog
 *
 * The public surface of LetterLog that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

/**
 * Contract of LetterLog.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LetterLogInterface {

	/**
	 * Log the letter as an outgoing contact moment on the client.
	 *
	 * A failure here does not undo the letter: it is already in the user's
	 * Files. The answer then carries no contact moment id, and the dialog says
	 * so.
	 *
	 * @param string      $clientId     The client id.
	 * @param string|null $contactId    The contact person, if one was chosen.
	 * @param string|null $ticketId     The ticket the letter was made from, if any.
	 * @param string      $templateName The template's name.
	 * @param string|null $path         Where filinq filed the copy.
	 *
	 * @return string|null The contact moment id, or null when it could not be saved.
	 *
	 * @spec openspec/specs/client-letters/spec.md#requirement-a-letter-is-logged-on-the-client-req-wlt-002
	 */
	public function log(string $clientId, ?string $contactId, ?string $ticketId, string $templateName, ?string $path): ?string;
}//end interface
