/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * pipelinq's Nextcloud Dashboard widgets, registered from configuration with
 * the library's registrar: four lists and two short create forms.
 *
 * The ids are FROZEN: Nextcloud's Dashboard app stores each user's chosen
 * widgets by id (lib/Dashboard/*::getId()).
 *
 * @spec openspec/specs/dashboard/spec.md#requirement-nextcloud-dashboard-widget-api-integration
 */

import { registerNcDashboardWidgets } from '@conduction/nextcloud-vue'

import '@conduction/nextcloud-vue/css/index.css'

const LEAD_HREF = '/apps/pipelinq/leads/{id}'
const LEADS = { href: '/apps/pipelinq/leads', label: 'View all' }
const TICKET_HREF = '/apps/pipelinq/tickets/{id}'
const NEWEST = { '@self.updated': 'desc' }

const PRIORITY_LABELS = { low: 'Low', normal: 'Normal', high: 'High', urgent: 'Urgent' }
const PRIORITY_OPTIONS = Object.entries(PRIORITY_LABELS).map(([value, label]) => ({ value, label }))
const CLIENT_FIELD = { key: 'client', label: 'Client', type: 'object', register: 'pipelinq', schema: 'client', placeholder: 'Search client…' }

/** A request is a `ticket` with ticketType `request` (unify-ticket-supertype). */
const NEW_REQUEST = {
	register: 'pipelinq',
	schema: 'ticket',
	fields: [
		{ key: 'title', label: 'Title', required: true, placeholder: 'Request title (required)' },
		CLIENT_FIELD,
		{
			key: 'category',
			label: 'Category',
			type: 'select',
			options: [
				{ value: 'general', label: 'General' },
				{ value: 'technical', label: 'Technical' },
				{ value: 'billing', label: 'Billing' },
				{ value: 'support', label: 'Support' },
			],
		},
		{ key: 'priority', label: 'Priority', type: 'select', options: PRIORITY_OPTIONS, default: 'first' },
		{
			key: 'channel',
			label: 'Channel',
			type: 'select',
			options: [
				{ value: 'phone', label: 'Phone' },
				{ value: 'email', label: 'Email' },
				{ value: 'walk-in', label: 'Walk-in' },
				{ value: 'web', label: 'Web' },
			],
		},
	],
	defaults: { ticketType: 'request', status: 'new', occurredAt: '@now' },
	submitLabel: 'Create request',
	successText: 'Request created!',
	successLinkText: 'View request',
	againLabel: 'Create another',
	successHref: TICKET_HREF,
}

const NEW_LEAD = {
	register: 'pipelinq',
	schema: 'lead',
	fields: [
		{ key: 'title', label: 'Title', required: true, placeholder: 'Lead title (required)' },
		CLIENT_FIELD,
		{ key: 'pipeline', label: 'Pipeline', type: 'select', optionsFrom: { register: 'pipelinq', schema: 'pipeline' }, default: 'first' },
		{ key: 'value', label: 'Value', type: 'number', placeholder: 'Estimated value (EUR)' },
		{
			key: 'source',
			label: 'Source',
			type: 'select',
			options: [
				{ value: 'website', label: 'Website' },
				{ value: 'referral', label: 'Referral' },
				{ value: 'cold-call', label: 'Cold call' },
				{ value: 'advertisement', label: 'Advertisement' },
				{ value: 'event', label: 'Event or fair' },
				{ value: 'other', label: 'Other' },
			],
		},
	],
	defaults: { status: 'open' },
	derive: {
		stage: { from: 'pipeline', path: 'stages', sortBy: 'order', field: 'name' },
		stageOrder: { from: 'pipeline', path: 'stages', sortBy: 'order', field: 'order' },
	},
	submitLabel: 'Create lead',
	successText: 'Lead created!',
	successLinkText: 'View lead',
	againLabel: 'Create another',
	successHref: LEAD_HREF,
}

registerNcDashboardWidgets([
	{
		id: 'pipelinq_my_leads_widget',
		config: {
			source: { register: 'pipelinq', schema: 'lead', params: { assignee: '@me', status: 'open', _limit: 20 } },
			limit: 20,
			title: '{title}',
			subtitle: ['{priority}', '{stage}'],
			valueLabels: { priority: PRIORITY_LABELS },
			due: { field: 'expectedCloseDate', withSubtitle: true },
			href: LEAD_HREF,
			viewAll: LEADS,
			emptyText: 'No leads assigned to you',
		},
	},
	{
		id: 'pipelinq_deals_overview_widget',
		config: {
			source: { register: 'pipelinq', schema: 'lead', params: { _order: { '@self.created': 'desc' }, _extend: ['client'] } },
			limit: 20,
			title: '{title}',
			subtitle: ['{client.name}', '{value|currency}', '{stage}'],
			href: LEAD_HREF,
			viewAll: LEADS,
			emptyText: 'No leads found',
		},
	},
	{
		id: 'pipelinq_recent_activities_widget',
		config: {
			sources: [
				{ register: 'pipelinq', schema: 'lead', params: { _order: NEWEST }, kind: 'Lead', href: LEAD_HREF },
				{ register: 'pipelinq', schema: 'ticket', params: { ticketType: 'request', _order: NEWEST }, kind: 'Request', href: TICKET_HREF },
			],
			sortBy: '@self.updated',
			limit: 10,
			title: '{title}',
			subtitle: ['{_kind}', '{@self.updated|relative}'],
			emptyText: 'No recent activities',
		},
	},
	{
		id: 'pipelinq_find_client_widget',
		config: {
			source: { register: 'pipelinq', schema: 'client' },
			limit: 20,
			title: '{name}',
			subtitle: ['{email}', '{phone}'],
			href: '/apps/pipelinq/clients/{id}',
			search: { label: 'Search', placeholder: 'Search clients…', emptyText: 'No clients found for "{query}"' },
			rowActions: [
				{ id: 'view', label: 'View client', href: '/apps/pipelinq/clients/{id}' },
				{
					id: 'request',
					label: 'Create request for this client',
					create: {
						...NEW_REQUEST,
						heading: 'Creating request for {name}',
						fields: [{ key: 'title', label: 'Title', required: true, placeholder: 'Request title' }],
						defaults: { ...NEW_REQUEST.defaults, client: '{id}', priority: 'normal' },
						openAfterCreate: true,
					},
				},
				{
					id: 'lead',
					label: 'Create lead for this client',
					create: {
						register: 'pipelinq',
						schema: 'lead',
						heading: 'Creating lead for {name}',
						fields: [{ key: 'title', label: 'Title', required: true, placeholder: 'Lead title' }],
						defaults: { status: 'open', client: '{id}' },
						submitLabel: 'Create lead',
						successHref: LEAD_HREF,
						openAfterCreate: true,
					},
				},
				{ id: 'copy-email', label: 'Copy email', copy: 'email' },
			],
			create: {
				label: 'New client',
				register: 'pipelinq',
				schema: 'client',
				fields: [
					{ key: 'name', label: 'Name', required: true, placeholder: 'Client name (required)' },
					{
						key: 'type',
						label: 'Type',
						type: 'select',
						options: [
							{ value: 'person', label: 'Person' },
							{ value: 'organization', label: 'Organization' },
						],
						default: 'first',
					},
					{ key: 'email', label: 'Email', placeholder: 'Email address' },
				],
				submitLabel: 'Add client',
				successText: 'Client added',
				successLinkText: 'View client',
				successHref: '/apps/pipelinq/clients/{id}',
			},
			emptyText: 'No clients found',
		},
	},
	{ id: 'pipelinq_create_lead_widget', type: 'quick-create', config: NEW_LEAD },
	{
		id: 'pipelinq_start_request_widget',
		type: 'quick-create',
		config: {
			...NEW_REQUEST,
			listHeading: 'Recent requests',
			list: {
				source: { register: 'pipelinq', schema: 'ticket', params: { ticketType: 'request', _order: { '@self.created': 'desc' } } },
				limit: 3,
				title: '{title}',
				subtitle: '{status}',
				valueLabels: { status: { new: 'New', in_progress: 'In progress' } },
				href: TICKET_HREF,
				emptyText: 'No recent requests',
			},
		},
	},
], { appId: 'pipelinq' })
