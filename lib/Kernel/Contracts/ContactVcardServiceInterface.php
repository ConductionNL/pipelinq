<?php

/**
 * Contract of ContactVcardService
 *
 * The public surface of ContactVcardService that other components call, so they depend
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
 * Contract of ContactVcardService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ContactVcardServiceInterface {

	/**
	 * Provision (resolve or create) the Nextcloud addressbook contact for a NOT
	 * YET SAVED client/contact from its raw create-form fields, returning the
	 * contact UID plus the denormalised identity mirror to persist on the object.
	 *
	 * This is the contact-FIRST half of the client-contact unification: the
	 * `client`/`contact` schema marks `contactsUid` REQUIRED, and the Nextcloud
	 * Contact is authoritative (never minted locally), so the contact must exist
	 * before the object is saved. It mirrors the repair-step resolution order —
	 * match an EXISTING contact (email first, then ORG for organisations) and
	 * only create a fresh vCard when nothing matches — reusing the SAME property
	 * builder + writer the post-save sync uses, so a manual create and the
	 * migration converge on identical identities.
	 *
	 * Returns null only when Contacts is unavailable or the write failed; the
	 * caller then surfaces a clean error instead of a raw 400.
	 *
	 * @param array $form The raw create-form fields (name/type/email/phone/...).
	 * @param string $objectType The object type ('client' or 'contact').
	 *
	 * @return ?array{contactsUid:string,name:string,email:string,phone:string} The
	 *                                                                          resolved UID + identity mirror, or null on failure.
	 *
	 * @spec openspec/specs/unify-client-contact/spec.md
	 */
	public function provisionContactFromForm(array $form, string $objectType): ?array;

	/**
	 * Sync a Pipelinq client or contact to Nextcloud Contacts.
	 *
	 * @param string $objectType The object type (client or contact).
	 * @param string $objectId The object ID.
	 *
	 * @return ?string The contacts UID or null.
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 */
	public function syncToContacts(string $objectType, string $objectId): ?string;
}//end interface
