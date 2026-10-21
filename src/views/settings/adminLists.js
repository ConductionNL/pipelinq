// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The lists on the admin settings page, as CnIndexPage props. The page has
 * no router, so every list is `embedded`: a row opens in place, in its
 * `rowModal` (mounted by the page through `cnOpenModal`) or in the built-in
 * form dialog. Lists over register objects read and write them through the
 * object store; the POS lists read the endpoints that validate them.
 *
 * @spec openspec/changes/pl-abstract-admin-sections/specs/admin-settings/spec.md#requirement-the-admin-lists-are-declared-index-pages
 */

/** The options every admin list shares: a plain table that opens rows in place. */
const ADMIN_LIST = {
	embedded: true,
	viewMode: 'table',
	selectable: false,
	allowSavedViews: false,
	showViewAction: false,
	showCopyAction: false,
}

/**
 * A list over register objects of one type.
 *
 * @param {{register: string, schema: string}|undefined} type The type's register and schema.
 * @return {object} The register and schema props, empty while the type is unknown.
 */
function objectSource(type) {
	return type && type.register && type.schema ? { register: String(type.register), schema: String(type.schema) } : {}
}

/**
 * The admin lists.
 *
 * @param {(app: string, text: string) => string} t The translate function.
 * @param {(slug: string) => ({register: string, schema: string}|undefined)} typeOf The register and schema of an object type.
 * @param {(name: string, props?: object) => void} openModal Open one of the page's modals.
 * @param {Array<{id: string, title: string}>} skills The skills an agent can hold.
 * @return {Record<string, object>} CnIndexPage props by list.
 */
export function adminLists(t, typeOf, openModal, skills = []) {
	return {
		productCategory: {
			...ADMIN_LIST,
			...objectSource(typeOf('productCategory')),
			title: t('pipelinq', 'Product Categories'),
			includeColumns: ['name', 'description'],
			fieldOverrides: { description: { hidden: false } },
			emptyText: t('pipelinq', 'No product categories configured yet.'),
		},
		skill: {
			...ADMIN_LIST,
			...objectSource(typeOf('skill')),
			title: t('pipelinq', 'Skills'),
			includeColumns: ['title', 'categories', 'isActive'],
			fieldOverrides: { description: { hidden: false } },
		},
		agentProfile: {
			...ADMIN_LIST,
			...objectSource(typeOf('agentProfile')),
			title: t('pipelinq', 'Agent Profiles'),
			includeColumns: ['userId', 'skills', 'maxConcurrent', 'isAvailable'],
			// The user is picked from the Nextcloud users (the schema's
			// `format: user`), the skills from the skills above.
			fieldOverrides: {
				skills: {
					widget: 'multiselect',
					items: { type: 'string', enum: skills.map((skill) => skill.id) },
					enumLabels: Object.fromEntries(skills.map((skill) => [skill.id, skill.title])),
				},
			},
		},
		posRole: {
			...ADMIN_LIST,
			title: t('pipelinq', 'POS roles'),
			// Cards: the five-column role table is wider than the admin card.
			viewMode: 'cards',
			entitySource: 'endpoint',
			sourceConfig: { url: '/apps/pipelinq/api/pos/roles', rowsPath: 'roles' },
			columns: [
				{ key: 'name', label: t('pipelinq', 'Name') },
				{ key: 'canVoid', label: t('pipelinq', 'May void transactions'), type: 'boolean' },
				{ key: 'canRefund', label: t('pipelinq', 'May process refunds'), type: 'boolean' },
				{ key: 'canNoSale', label: t('pipelinq', 'May open the cash drawer (no-sale)'), type: 'boolean' },
				{ key: 'maxDiscountPercent', label: t('pipelinq', 'Maximum discount %'), widget: 'number' },
			],
			rowModal: 'PosRoleFormDialog',
			createModal: 'PosRoleFormDialog',
			emptyText: t('pipelinq', 'No POS roles defined yet'),
		},
		posStaff: {
			...ADMIN_LIST,
			title: t('pipelinq', 'POS staff'),
			entitySource: 'endpoint',
			sourceConfig: { url: '/apps/pipelinq/api/pos/staff', rowsPath: 'staff' },
			columns: [
				{ key: 'displayName', label: t('pipelinq', 'Display name') },
				{ key: 'posRole', label: t('pipelinq', 'Role'), widget: 'fkResolve', widgetProps: { ...objectSource(typeOf('posRole')), labelField: 'name' } },
				{ key: 'isActive', label: t('pipelinq', 'Active'), type: 'boolean' },
				{ key: 'lastLoginAt', label: t('pipelinq', 'Last login'), widget: 'date' },
			],
			rowModal: 'PosStaffFormDialog',
			createModal: 'PosStaffFormDialog',
			emptyText: t('pipelinq', 'No staff members defined yet.'),
		},
		posTenderType: {
			...ADMIN_LIST,
			title: t('pipelinq', 'POS tender types'),
			viewMode: 'cards',
			entitySource: 'endpoint',
			sourceConfig: { url: '/apps/pipelinq/api/pos/tender-types', rowsPath: 'results' },
			columns: [
				{ key: 'name', label: t('pipelinq', 'Name') },
				{ key: 'code', label: t('pipelinq', 'Code') },
				{ key: 'glAccount', label: t('pipelinq', 'GL account') },
				{ key: 'isActive', label: t('pipelinq', 'Active'), type: 'boolean' },
				{ key: 'sortOrder', label: t('pipelinq', 'Sort'), widget: 'number' },
			],
			rowModal: 'PosTenderTypeFormDialog',
			createModal: 'PosTenderTypeFormDialog',
			actions: [
				{
					id: 'delete',
					label: t('pipelinq', 'Delete'),
					icon: 'Delete',
					handler: (row) => openModal('CnActionDialog', {
						objectId: row.id,
						object: row,
						config: {
							title: t('pipelinq', 'Delete tender type'),
							summary: { title: '{name}', meta: '{code}', icon: 'CashRegister' },
							note: { variant: 'info', text: t('pipelinq', 'A tender type that active tenders still use cannot be deleted.') },
							doneText: t('pipelinq', 'The tender type is deleted.'),
							confirm: {
								label: t('pipelinq', 'Delete'),
								request: { method: 'DELETE', url: '/apps/pipelinq/api/pos/tender-types/@objectId' },
							},
						},
					}),
				},
			],
			emptyText: t('pipelinq', 'No tender types defined yet.'),
		},
	}
}
