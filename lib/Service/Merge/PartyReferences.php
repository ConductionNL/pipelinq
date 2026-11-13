<?php

/**
 * Pipelinq PartyReferences.
 *
 * Every property in pipelinq's register that holds the uuid of a client or a
 * contact, so a merge can move those references onto the record that stays and
 * a reversal can move them back. The register guard test fails when a register
 * file adds a client or contact reference this list does not hold.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Merge
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/clients-reversible-merge/specs/client-reversible-merge/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Merge;

/**
 * The client and contact reference properties, by schema slug.
 *
 * @spec openspec/changes/clients-reversible-merge/specs/client-reversible-merge/spec.md
 */
final class PartyReferences {

	/**
	 * The field on a merged-away client or contact that lists the rows a merge moved.
	 */
	public const LEDGER_FIELD = 'mergeMovedRows';

	/**
	 * The properties that hold a client uuid, as [schema slug, property].
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	public const CLIENT = [
		['billableExpense', 'client'],
		['billingTimeEntry', 'client'],
		['competitor', 'clientId'],
		['contact', 'client'],
		['crmTask', 'clientId'],
		['deliveryProgramme', 'client'],
		['enquiry', 'client'],
		['journeyRun', 'clientId'],
		['lead', 'client'],
		['posTransaction', 'client'],
		// A relationship's ends hold a contact or a client uuid, so both merges move them.
		['relationship', 'fromContact'],
		['relationship', 'toContact'],
		['salesContract', 'clientRef'],
		['salesQuote', 'client'],
		['satisfactionSurveyInvitation', 'clientRef'],
		['socialConnection', 'clientId'],
		['surveyResponse', 'clientRef'],
		['ticket', 'client'],
	];

	/**
	 * The properties that hold a contact uuid, as [schema slug, property].
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	public const CONTACT = [
		['attributionLink', 'contactId'],
		['blastDelivery', 'contactId'],
		['channelConversation', 'contactId'],
		['channelMessage', 'contactId'],
		['consentRecord', 'contactId'],
		['enquiry', 'contact'],
		['eventRegistration', 'contact'],
		['journeyRun', 'contactId'],
		['lead', 'contact'],
		['messagingConsentRecord', 'contactId'],
		['posTransaction', 'customer'],
		['relationship', 'fromContact'],
		['relationship', 'toContact'],
		['salesContract', 'contactRef'],
		['salesQuote', 'contact'],
		['subscription', 'contactId'],
		['ticket', 'contact'],
		['touchpoint', 'contactId'],
	];

	/**
	 * Properties named like a client or contact reference that hold something else, with why.
	 *
	 * @var array<string, string>
	 */
	public const NOT_REFERENCES = [
		'zgwEndpoint.clientId' => 'The zgwClient id the endpoint authenticates with, not a CRM client.',
		'socialAccount.clientId' => 'The OAuth client id the connection was made with.',
		'satisfactionSurveyInvitation.contactRef' => 'The recipient as a Nextcloud contact uid, never a contact record.',
		'surveyResponse.contactRef' => 'The respondent as a Nextcloud contact uid, like the invitation it answers.',
	];
}//end class
