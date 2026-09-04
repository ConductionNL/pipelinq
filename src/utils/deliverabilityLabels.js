/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Pure label/verdict mapping for the marketing-mail-transports
 * deliverability panel (DeliverabilitySettings.vue). Extracted so the
 * mapping logic is testable without mounting the Vue component — this
 * repo's vitest suite targets dependency-free client-side helpers only
 * (vitest.config.js), not component rendering.
 *
 * The English label text returned here is passed through `t('pipelinq', …)`
 * at the call site, not translated inside this module.
 */

const KIND_LABELS = {
	instance: 'Instance mail server',
	mailAccount: 'Mail account',
	provider: 'Bulk provider',
}

const DMARC_LABELS = {
	found: 'DMARC found',
	missing: 'DMARC missing: bulk senders to Gmail are rejected without it',
	invalid: 'DMARC record present but malformed',
	unknown: 'DMARC not checked yet',
}

/**
 * Human label for a mailTransport `kind` value.
 *
 * @param {string} kind One of `instance`, `mailAccount`, `provider`.
 * @return {string} The English label text (untranslated), or `kind` itself
 *                   when it is not one of the known values.
 */
export function kindLabel(kind) {
	return KIND_LABELS[kind] || kind || ''
} // end kindLabel()

/**
 * Human verdict text for a mailTransport `dmarcStatus` value.
 *
 * @param {string} dmarcStatus One of `found`, `missing`, `invalid`, `unknown`.
 * @return {string} The English verdict text (untranslated).
 */
export function dmarcVerdictText(dmarcStatus) {
	return DMARC_LABELS[dmarcStatus] || DMARC_LABELS.unknown
} // end dmarcVerdictText()

/**
 * CSS badge class for a transport's DKIM verification state.
 *
 * @param {boolean} dkimVerified Whether DKIM was found.
 * @return {string} `deliverability-settings__badge--on` or `--off`.
 */
export function dkimBadgeClass(dkimVerified) {
	return dkimVerified
		? 'deliverability-settings__badge--on'
		: 'deliverability-settings__badge--off'
} // end dkimBadgeClass()

/**
 * CSS badge class for a transport's `dmarcStatus`.
 *
 * @param {string} dmarcStatus One of `found`, `missing`, `invalid`, `unknown`.
 * @return {string} A `deliverability-settings__badge--*` class name.
 */
export function dmarcBadgeClass(dmarcStatus) {
	if (dmarcStatus === 'found') {
		return 'deliverability-settings__badge--on'
	}
	if (dmarcStatus === 'unknown') {
		return 'deliverability-settings__badge--sandbox'
	}
	return 'deliverability-settings__badge--off'
} // end dmarcBadgeClass()
