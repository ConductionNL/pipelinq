/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * The lead create form as CnFormDialog configuration: its fields, the values
 * a new lead starts with, and the record that is saved.
 *
 * The client and contact pickers create what they cannot find through the
 * app's own dialogs (`createModal`): a client or contact needs a Nextcloud
 * contact minted on the server, so the generic form cannot save one. The
 * contact picker and its dialog follow the chosen client.
 *
 * The server places the lead in a stage when it has none and fills in the
 * stage order and entry time (LeadStageCreatingListener), so the form only
 * offers the stages of the chosen pipeline.
 *
 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form-mvp
 */

import { normaliseCurrency } from './leadCurrency.js'
import { pipelineAppliesTo } from './pipelineUtils.js'

/** The lead priorities, in the order the form offers them. */
const PRIORITIES = ['low', 'normal', 'high', 'urgent']

/**
 * The pipelines a lead can be put on.
 *
 * @param {Array<object>} pipelines The stored pipelines.
 * @return {Array<object>} The lead pipelines.
 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form-mvp
 */
export function leadPipelines(pipelines) {
	return (Array.isArray(pipelines) ? pipelines : []).filter((p) => p && p.id && pipelineAppliesTo(p, 'lead'))
}

/**
 * The stages of a pipeline, by their order.
 *
 * @param {object|null|undefined} pipeline The pipeline.
 * @return {Array<object>} The stages.
 */
function sortedStages(pipeline) {
	return [...((pipeline && pipeline.stages) || [])].sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
}

/**
 * The fields of the lead create form.
 *
 * @param {object} options The inputs.
 * @param {(text: string) => string} options.translate Translates an English source string.
 * @param {Array<object>} options.pipelines The stored pipelines.
 * @param {Array<string>} options.sources The lead source tags.
 * @param {(tag: string) => string} options.sourceLabel The label of a source tag.
 * @param {object} options.priorityLabels English label per priority.
 * @return {Array<object>} CnFormDialog field definitions.
 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form-mvp
 */
export function leadCreateFields({ translate, pipelines, sources, sourceLabel, priorityLabels }) {
	const onLeads = leadPipelines(pipelines)
	return [
		{ key: 'title', label: translate('Title'), widget: 'text', required: true },
		{ key: 'description', label: translate('Description'), widget: 'textarea' },
		{ key: 'category', label: translate('Category'), widget: 'text' },
		{ key: 'value', label: translate('Value'), widget: 'number', validation: { minimum: 0 } },
		{ key: 'currency', label: translate('Currency'), widget: 'text', validation: { pattern: '^[A-Za-z]{3}$' } },
		{
			key: 'source',
			label: translate('Source'),
			widget: 'select',
			enum: [...sources],
			enumLabels: Object.fromEntries(sources.map((tag) => [tag, sourceLabel(tag)])),
		},
		{
			key: 'priority',
			label: translate('Priority'),
			widget: 'select',
			enum: PRIORITIES,
			enumLabels: Object.fromEntries(PRIORITIES.map((p) => [p, translate(priorityLabels[p] || p)])),
		},
		{ key: 'expectedCloseDate', label: translate('Expected close date'), widget: 'date' },
		{
			key: 'client',
			label: translate('Client'),
			widget: 'select',
			description: translate('Leave empty to save the lead without a client.'),
			reference: { register: 'pipelinq', schema: 'client', labelField: 'name' },
			allowCreate: true,
			createModal: 'ClientCreateDialog',
		},
		{
			key: 'contact',
			label: translate('Contact'),
			widget: 'select',
			reference: { register: 'pipelinq', schema: 'contact', labelField: 'name' },
			allowCreate: true,
			pickerFilter: { client: '@answer.client' },
			createModal: 'ContactCreateDialog',
			createModalProps: { client: '@answer.client' },
		},
		{
			key: 'pipeline',
			label: translate('Pipeline'),
			widget: 'select',
			required: true,
			enum: onLeads.map((p) => p.id),
			enumLabels: Object.fromEntries(onLeads.map((p) => [p.id, p.title || p.id])),
		},
		{
			key: 'stage',
			label: translate('Stage'),
			widget: 'select',
			optionsFrom: {
				register: 'pipelinq',
				schema: 'pipeline',
				where: { id: '@answer.pipeline' },
				itemsPath: 'stages',
				valueField: 'name',
				labelField: 'name',
			},
		},
	]
}

/**
 * The values a new lead starts with: the default lead pipeline (else the
 * first) in its first open stage, normal priority and the reporting currency.
 *
 * @param {Array<object>} pipelines The stored pipelines.
 * @param {string} currency The reporting currency.
 * @return {object} The initial values.
 * @spec openspec/changes/pipeline-numbers-tell-the-truth/specs/lead-management/spec.md
 */
export function leadCreateInitialData(pipelines, currency) {
	const onLeads = leadPipelines(pipelines)
	const pipeline = onLeads.find((p) => p.isDefault === true) || onLeads[0]
	const data = { priority: 'normal', currency }
	if (pipeline) {
		data.pipeline = pipeline.id
		const firstOpen = sortedStages(pipeline).find((s) => !s.isClosed)
		if (firstOpen) {
			data.stage = firstOpen.name
		}
	}
	return data
}

/**
 * The lead to save: empty values left out (an empty client is no key at
 * all), the currency in upper case, and a stage the chosen pipeline does not
 * have dropped so the server places the lead in its first open stage.
 *
 * @param {object} formData The form values.
 * @param {Array<object>} pipelines The stored pipelines.
 * @return {object} The lead.
 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form-mvp
 */
export function leadCreatePayload(formData, pipelines) {
	const lead = {}
	for (const [key, value] of Object.entries(formData || {})) {
		if (value !== null && value !== undefined && value !== '') {
			lead[key] = value
		}
	}
	if (lead.currency) {
		lead.currency = normaliseCurrency(lead.currency)
	}
	if (lead.stage) {
		const pipeline = (Array.isArray(pipelines) ? pipelines : []).find((p) => p && p.id === lead.pipeline)
		if (!sortedStages(pipeline).some((s) => s.name === lead.stage)) {
			delete lead.stage
		}
	}
	return lead
}
