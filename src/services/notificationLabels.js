// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The words a person reads for each notification rule in their settings.
 *
 * pipelinq declares its notification rules in the register
 * (`x-openregister-notifications`), under keys like `newLead` and
 * `clientUpdated`. OpenRegister's preference list hands those keys to the
 * user settings without a label, and the settings printed the key. CnAppRoot
 * takes a `notificationLabels` map (nextcloud-vue change
 * notification-rule-labels-and-runtime-version), keyed `<schema>.<rule>`,
 * and the notification pane shows that label instead.
 *
 * A label says when the person hears something, from their side. A rule
 * that goes to the assignee or the owner says so ("assigned to me", "I
 * own"). tests/vitest/notificationLabels.spec.js fails when a rule in the
 * register has no label here, or a label has no en or nl entry.
 *
 * The translator is passed in, so the module stays free of a Nextcloud
 * runtime and the l10n check still sees every string as a literal.
 *
 * @spec openspec/changes/simple-tour-and-readable-labels/specs/notifications/spec.md
 */

/**
 * The label of every notification rule pipelinq declares.
 *
 * @param {function(string, string): string} t The translator.
 * @return {Object<string, string>} Translated labels, keyed `<schema>.<rule>`.
 * @spec openspec/changes/simple-tour-and-readable-labels/specs/notifications/spec.md
 */
export function notificationLabels(t) {
	return {
		'client.clientUpdated': t('pipelinq', 'A client I own changes'),
		'contact.newContact': t('pipelinq', 'A new contact is added'),
		'lead.newLead': t('pipelinq', 'A new lead comes in'),
		'lead.leadWon': t('pipelinq', 'A lead is won'),
		'lead.leadLost': t('pipelinq', 'A lead is lost'),
		'lead.leadUpdated': t('pipelinq', 'A lead assigned to me changes'),
		'ticket.newTicket': t('pipelinq', 'A new ticket comes in'),
		'ticket.ticketUpdated': t('pipelinq', 'A ticket assigned to me changes'),
		'crmTask.taskCompleted': t('pipelinq', 'A task assigned to me is completed'),
		'crmTask.taskExpired': t('pipelinq', 'A task assigned to me expires'),
		'crmTask.taskUpdated': t('pipelinq', 'A task assigned to me changes'),
		'enquiry.newEnquiry': t(
			'pipelinq',
			'A new enquiry comes in through the website',
		),
		'surveyResponse.detractorResponse': t(
			'pipelinq',
			'An unhappy customer needs a follow-up',
		),
		'salesContract.contractExpiring': t(
			'pipelinq',
			'A contract I own is up for renewal',
		),
	}
}
