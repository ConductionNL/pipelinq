<?php

/**
 * Contract of ContactSyncService
 *
 * The public surface of ContactSyncService that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of ContactSyncService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ContactSyncServiceInterface {

	/**
	 * Whether a record's address book card exists, which address book holds
	 * it and when the card last changed (its vCard REV).
	 *
	 * @param string $uid The record's `contactsUid`.
	 *
	 * @return array{linked: bool, addressBook: string, updatedAt: string|null} The card's status.
	 *
	 * @spec openspec/specs/contact-person-page-as-board/spec.md#requirement-the-contact-page-shows-its-address-book-card
	 */
	public function cardStatus(string $uid): array;

	/**
	 * Contact-FIRST create of a client/contact: provision (resolve or create)
	 * the authoritative Nextcloud addressbook contact from the create-form
	 * fields, then save the object with the resulting `contactsUid` and the
	 * denormalised identity mirror.
	 *
	 * Without this, every UI create is rejected 400 by OpenRegister because the
	 * `client`/`contact` schema marks `contactsUid` REQUIRED but no create
	 * surface can supply it — `contactsUid` is resolved/created via
	 * ContactVcardService, never minted locally (client-contact unification).
	 *
	 * @param string $objectType The object type ('client' or 'contact').
	 * @param array $form The raw create-form fields (name/type/email/phone/...).
	 *
	 * @return array The created object data (serialised).
	 *
	 * @throws RuntimeException When name is missing or the contact cannot be provisioned.
	 *
	 * @spec openspec/specs/unify-client-contact/spec.md
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential validation + contact-provisioning guard clauses; extraction adds no clarity.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Sequential validation + contact-provisioning guard clauses; extraction adds no clarity.
	 */
	public function createWithContact(string $objectType, array $form): array;

	/**
	 * Import a Nextcloud contact into Pipelinq as a client or contact.
	 *
	 * @param string $uid The contact UID.
	 * @param string $addressBookKey The addressbook key.
	 * @param string $type The import type (client or contact).
	 * @param ?string $clientId The optional client ID for contact imports.
	 *
	 * @return array The created object data.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) — $addressBookKey kept for future per-book import support
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 */
	public function importContact(string $uid, string $addressBookKey, string $type = 'client', ?string $clientId = null): array;

	/**
	 * Search Nextcloud addressbooks for contacts matching a query.
	 * Returns results with an `alreadyLinked` flag if a Pipelinq object has the same contactsUid.
	 *
	 * @param string $query The search query.
	 *
	 * @return array The matching contacts.
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 */
	public function searchContacts(string $query): array;

	/**
	 * Sync a Pipelinq client or contact to Nextcloud Contacts.
	 * Delegates to the ContactVcardService.
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
