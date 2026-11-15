<?php

/**
 * Pipelinq SubmitterParties.
 *
 * Matches or creates the contact and the client of a form submitter.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
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
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

use OCA\Pipelinq\Kernel\Contracts\ListObjectStoreInterface;

/**
 * The contact and client records a form submission is filed under, shared by
 * the landing-page listener and the website-form lead intake.
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
 */
final class SubmitterParties {

	/**
	 * The schema slug contacts live under.
	 */
	public const CONTACT_SCHEMA_SLUG = 'contact';

	/**
	 * The schema slug clients live under.
	 */
	public const CLIENT_SCHEMA_SLUG = 'client';

	/**
	 * Constructor.
	 *
	 * @param ListObjectStoreInterface $store Register-scoped object access.
	 */
	public function __construct(
		private readonly ListObjectStoreInterface $store,
	) {
	}//end __construct()

	/**
	 * The id of the first contact found by one of the addresses, or of the
	 * contact created when none matches.
	 *
	 * @param array<int, string>   $candidates The addresses to look up, in order.
	 * @param array<string, mixed> $newContact The contact to create when none matches.
	 *
	 * @return string The contact id, or an empty string when the write failed.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
	 */
	public function contactId(array $candidates, array $newContact): string {
		foreach ($candidates as $candidate) {
			$rows = $this->store->findAll(schemaSlug: self::CONTACT_SCHEMA_SLUG, filters: ['email' => $candidate]);
			if ($rows !== []) {
				return $this->store->idOf(payload: $rows[0]);
			}
		}

		$saved = $this->store->save(schemaSlug: self::CONTACT_SCHEMA_SLUG, payload: $newContact);

		return $this->store->idOf(payload: $saved);
	}//end contactId()

	/**
	 * The id of the client with this organisation name, or of the one created.
	 *
	 * @param string $organisation The organisation the visitor named.
	 * @param string $notes        The notes a created client carries.
	 *
	 * @return string The client id, or an empty string when the write failed.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
	 */
	public function clientId(string $organisation, string $notes): string {
		$rows = $this->store->findAll(schemaSlug: self::CLIENT_SCHEMA_SLUG, filters: ['name' => $organisation]);
		if ($rows !== []) {
			return $this->store->idOf(payload: $rows[0]);
		}

		$saved = $this->store->save(
			schemaSlug: self::CLIENT_SCHEMA_SLUG,
			payload: ['name' => $organisation, 'type' => 'organization', 'notes' => $notes],
		);

		return $this->store->idOf(payload: $saved);
	}//end clientId()

	/**
	 * A touch block as the five keys the schemas declare.
	 *
	 * @param array<string, mixed> $utm Whatever the submission carried.
	 *
	 * @return array{campaign: string, source: string, medium: string, term: string, content: string} The block, absent keys empty.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
	 */
	public function utmBlock(array $utm): array {
		$block = [];
		foreach (['campaign', 'source', 'medium', 'term', 'content'] as $key) {
			$block[$key] = trim((string)($utm[$key] ?? ''));
		}

		return $block;
	}//end utmBlock()
}//end class
