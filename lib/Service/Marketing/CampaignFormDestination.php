<?php

/**
 * The object a campaign's form creates, and how its answers reach it.
 *
 * Decision 179: a form submit creates its destination object directly. A
 * campaign's sign-up form names `lead` as that object, so the page that hosts
 * the form writes the lead in the visitor's own request and nothing waits in
 * between. This class holds the one declaration both callers need: the
 * landing-page request for a new form, and the repair step that names the
 * destination of forms made before forms had one.
 *
 * The declaration is data: a register, a schema, which answer writes which
 * property, and the values every submit of this form writes. Matching the
 * contact and logging the touchpoint are effects of the lead being created
 * (see SubmittedLeadIntake), not part of the form.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

/**
 * Declares the destination of a campaign's form.
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
 */
class CampaignFormDestination {

	/**
	 * The register the destination lives in.
	 *
	 * @var string
	 */
	public const REGISTER = 'pipelinq';

	/**
	 * The schema a submit creates.
	 *
	 * @var string
	 */
	public const SCHEMA = 'lead';

	/**
	 * What a lead created by a campaign form records as its origin.
	 *
	 * @var string
	 */
	public const SOURCE = 'landing-page';

	/**
	 * The prefix of the reference a form carries back to its campaign.
	 *
	 * @var string
	 */
	public const REFERENCE_PREFIX = 'pipelinq:campaign:';

	/**
	 * Which form answer writes which lead property.
	 *
	 * The three visible answers land in the lead's own submitter properties,
	 * so the lead is answerable before anyone has matched a contact. The two
	 * tracking answers the page adds land in the touch blocks the lead
	 * already has.
	 *
	 * @var array<string, string>
	 */
	public const FIELDS = [
		'name' => 'submitterName',
		'email' => 'submitterEmail',
		'organisation' => 'submitterOrganisation',
		'utmFirstTouch' => 'firstTouch',
		'utmLastTouch' => 'lastTouch',
	];

	/**
	 * The destination of one campaign's form.
	 *
	 * @param string               $campaignId The campaign's id, or an empty string when unknown.
	 * @param array<string, mixed> $campaign   The campaign row, may be empty.
	 *
	 * @return array{register: string, schema: string, fields: array<string, string>, fixed: array<string, string>} The declaration.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
	 */
	public function forCampaign(string $campaignId, array $campaign): array {
		$title = trim((string)($campaign['name'] ?? ''));
		if ($title === '') {
			$title = 'Landing page';
		}

		$fixed = ['title' => $title, 'source' => self::SOURCE];
		if (trim($campaignId) !== '') {
			$fixed['campaignId'] = trim($campaignId);
		}

		return [
			'register' => self::REGISTER,
			'schema' => self::SCHEMA,
			'fields' => self::FIELDS,
			'fixed' => $fixed,
		];
	}//end forCampaign()

	/**
	 * The reference a form carries back to its campaign.
	 *
	 * @param string $campaignId The campaign's id.
	 *
	 * @return string The reference.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
	 */
	public function referenceFor(string $campaignId): string {
		return (self::REFERENCE_PREFIX . $campaignId);
	}//end referenceFor()

	/**
	 * The campaign id inside a form's reference.
	 *
	 * @param string $reference The form's external reference.
	 *
	 * @return string The campaign id, or an empty string when the reference names none.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
	 */
	public function campaignIdOf(string $reference): string {
		$reference = trim($reference);
		if (str_starts_with($reference, self::REFERENCE_PREFIX) === false) {
			return '';
		}

		return trim(substr($reference, strlen(self::REFERENCE_PREFIX)));
	}//end campaignIdOf()
}//end class
