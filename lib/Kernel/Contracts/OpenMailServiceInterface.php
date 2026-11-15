<?php

/**
 * Contract of OpenMailService
 *
 * The public surface of OpenMailService that other components call, so they depend
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

use InvalidArgumentException;
use OutOfBoundsException;

/**
 * Contract of OpenMailService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OpenMailServiceInterface {

	/**
	 * Add a mail's sender as a contact person, the Nextcloud contact first.
	 *
	 * A client named with the sender must be one the caller may read.
	 *
	 * @param string $name The sender's name.
	 * @param string $email The sender's address.
	 * @param string $client The id of the client they belong to, or ''.
	 *
	 * @return array<string, mixed> The created contact.
	 *
	 * @throws InvalidArgumentException When the name or the address is missing or malformed.
	 * @throws OutOfBoundsException When the caller cannot read the client.
	 *
	 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md#requirement-the-pane-shows-the-senders-record-or-adds-it-req-mai-002
	 */
	public function addSender(string $name, string $email, string $client = ''): array;

	/**
	 * Log a mail as an inbound contact moment on a contact or a client.
	 *
	 * The target is read as the caller first: a record they may not see is
	 * a record they may not log on.
	 *
	 * @param string $entityType `contact` or `client`.
	 * @param string $entityId The record's id.
	 * @param string $subject The mail's subject.
	 * @param string $sentAt When the mail was sent, any ISO 8601 instant.
	 * @param string $preview The opening of the mail's text.
	 *
	 * @return array<string, string> The contact moment's id and the fields written.
	 *
	 * @throws InvalidArgumentException When a field is missing or malformed.
	 * @throws OutOfBoundsException When the caller cannot read the target record.
	 *
	 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md#requirement-a-mail-can-be-logged-as-a-contact-moment-req-mai-003
	 */
	public function logMail(string $entityType, string $entityId, string $subject, string $sentAt, string $preview): array;

	/**
	 * The records a sender's address belongs to, as far as the caller may
	 * see them, and an organisation to suggest when there are none.
	 *
	 * @param string $email The sender's address.
	 *
	 * @return array{matches: array<int, array<string, string>>, organisation: array<string, string>|null}
	 *
	 * @throws InvalidArgumentException When the address is not an email address.
	 *
	 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md#requirement-the-pane-shows-the-senders-record-or-adds-it-req-mai-002
	 */
	public function lookup(string $email): array;
}//end interface
