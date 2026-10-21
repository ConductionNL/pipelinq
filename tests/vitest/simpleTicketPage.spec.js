// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The ticket page in the simple structure.
 *
 * The page gets its simple shape from an overlay in
 * `src/menu-layout.simple.json`. An overlay that names something that does
 * not exist changes nothing and says nothing, so every name in it is checked
 * here against the thing it names: the schema's lifecycle, the schema's
 * properties, the registry, the icons and the translations. The stage, the
 * menu groups and the pills are resolved with the library's own functions.
 *
 * @spec openspec/changes/simple-ticket-page/specs/ticket-detail-page/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import {
	groupMenuEntries,
	normalisePinnedAction,
	resolveNextStep,
	resolvePill,
	stageEntry,
	stageOf,
} from '@conduction/nextcloud-vue/src/utils/detailActionModel.js'
import { evaluateVisibleWhenLocal } from '@conduction/nextcloud-vue/src/utils/visibleWhen.js'
import { execFileSync } from 'child_process'
import fs from 'fs'
import os from 'os'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import { applyMenuModules } from '../../src/utils/menuModules.js'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

// The library barrel does not load outside a bundler. The stand-in draws what
// it is handed, so the section's own work (which ticket, which messages,
// read only) is what the mount below sees.
vi.mock('@conduction/nextcloud-vue', () => ({
	CnConversationThread: {
		name: 'CnConversationThread',
		props: { messages: Array, allowReply: Boolean },
		render() {
			return h('div', [
				...this.messages.map((message) =>
					h('p', `${message.side}:${message.text}`),
				),
				this.allowReply ? h('textarea') : null,
			])
		},
	},
}))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const readJson = (...parts) => JSON.parse(read(...parts))

const fragments = fs
	.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))
const fullFile = readJson('src', 'menu-layout.json')
const simpleFile = readJson('src', 'menu-layout.simple.json')
const registrySource = read('src', 'registry.js')
const iconsSource = read('src', 'icons.js')
const en = readJson('l10n', 'en.json').translations
const nl = readJson('l10n', 'nl.json').translations

const manifest = () => readJson('src', 'manifest.json')
function build(file) {
	return buildProfiledManifest(buildManifest, manifest(), fragments, file)
}
const pageOf = (built) => built.pages.find((page) => page.id === 'TicketDetail')
const simple = pageOf(build(applyMenuModules(simpleFile, []))).config
const full = pageOf(build(fullFile))

/** The ticket schema: every fragment that adds to it, merged. */
const registerDir = path.join(ROOT, 'lib', 'Settings', 'register.d')
const ticketFragments = fs
	.readdirSync(registerDir)
	.filter((name) => name.endsWith('.json'))
	.map((name) => readJson('lib', 'Settings', 'register.d', name))
	.map((file) => file.components?.schemas?.ticket)
	.filter(Boolean)
const ticketProperties = Object.assign(
	{},
	...ticketFragments.map((fragment) => fragment.properties || {}),
)
const lifecycle = ticketFragments.find(
	(fragment) => fragment.configuration?.['x-openregister-lifecycle'],
).configuration['x-openregister-lifecycle']
const STATUSES = ticketProperties.status.enum
const TYPES = ticketProperties.ticketType.enum

const actionsById = Object.fromEntries(
	simple.headerActions.map((action) => [action.id, action]),
)
function visible(action, ticket) {
	return (
		!action.visibleWhen || evaluateVisibleWhenLocal(action.visibleWhen, ticket)
	)
}
/** The action a ticket's primary button runs, or null when it has none. */
function primaryFor(ticket) {
	const pinned = normalisePinnedAction(
		stageEntry(simple.primaryActionByStage, stageOf(ticket, simple.stageField)),
		'cn-stage-primary',
	)
	const action = pinned && actionsById[pinned.id]
	return action && visible(action, ticket) ? action : null
}

describe('the full structure', () => {
	it("keeps today's ticket page, to the letter", () => {
		expect(full).toEqual(
			manifest().pages.find((page) => page.id === 'TicketDetail'),
		)
		// The header actions on the full page are Make a letter
		// (work-letter-from-filinq-template, shown only with filinq) and
		// Assign to me (detail-pages-read-at-a-glance), which sets the assignee
		// and changes no status.
		expect(full.config.headerActions.map((action) => action.id)).toEqual([
			'make-letter',
			'assign-to-me',
		])
		expect(full.config.sideColumn).toBeUndefined()
	})
})

describe('the header, as DqZaak draws it', () => {
	it('reads the list as a text breadcrumb, has no type eyebrow, sits in a card and shows the tabs as the folder tabs of the board', () => {
		expect(simple.breadcrumb).toEqual({
			label: 'Questions and reports',
			route: 'Tickets',
		})
		expect(simple.showTypeEyebrow).toBe(false)
		expect(simple.headerCard).toBe(true)
		const tabs = simple.widgets.find((widget) => widget.id === 'ticket-panels')
		expect(tabs.content.variant).toBeUndefined()
		expect(simple.follow).toBe(false)
		expect(simple.favourite).toBe(false)
		expect(simple.headerMeta).toBe('{channel} · received {occurredAt|date}')
		// The full page declares none of it, so it renders as before.
		for (const key of ['breadcrumb', 'showTypeEyebrow', 'headerCard']) {
			expect(full.config[key], key).toBeUndefined()
		}
	})
})

describe('the status actions', () => {
	// Status changes only: Assign to me is an api-call too, but it sets the
	// assignee through pipelinq's own endpoint and leaves the status alone.
	const transitions = simple.headerActions.filter(
		(action) => action.type === 'api-call' && action.url.endsWith('/transition'),
	)

	it('include Assign to me, which changes no status', () => {
		expect(actionsById['assign-to-me']).toMatchObject({
			type: 'api-call',
			url: '/apps/pipelinq/api/tickets/@objectId/assign-to-me',
		})
	})

	it('are transitions of the ticket lifecycle, posted to the transition endpoint', () => {
		expect(transitions.length).toBeGreaterThan(4)
		for (const action of transitions) {
			expect(action.url, action.id).toBe(
				'/apps/openregister/api/objects/@objectId/transition',
			)
			expect(action.method, action.id).toBe('POST')
			expect(
				Object.keys(lifecycle.transitions),
				`${action.id}: ${action.payload.action}`,
			).toContain(action.payload.action)
		}
		expect(lifecycle.field).toBe(simple.stageField)
	})

	it('show only on a status their transition can leave', () => {
		for (const action of transitions) {
			const from = lifecycle.transitions[action.payload.action].from
			const shownOn = STATUSES.filter((status) =>
				TYPES.some((ticketType) => visible(action, { status, ticketType })),
			)
			expect(shownOn.length, action.id).toBeGreaterThan(0)
			for (const status of shownOn) {
				expect(from, `${action.id} shows on ${status}`).toContain(status)
			}
		}
	})

	it('offer each ticket type its own way to finish', () => {
		const finishing = (ticket) =>
			transitions
				.filter((action) => action.group === 'Finish')
				.filter((action) => visible(action, ticket))
				.map((action) => action.id)
		const at = (ticketType) => finishing({ status: 'in_progress', ticketType })
		expect(at('request')).toEqual(['ticket-complete', 'ticket-reject'])
		expect(at('complaint')).toEqual(['ticket-resolve', 'ticket-reject'])
		expect(at('interaction')).toEqual(['ticket-close', 'ticket-reject'])
		// A finished ticket offers no status action at all.
		for (const status of lifecycle.final) {
			for (const ticketType of TYPES) {
				expect(
					transitions.filter((action) =>
						visible(action, { status, ticketType }),
					),
					`${status} ${ticketType}`,
				).toEqual([])
			}
		}
	})
})

describe('the primary button', () => {
	it('names the next step from the status', () => {
		for (const stage of Object.keys(simple.primaryActionByStage)) {
			expect(STATUSES, stage).toContain(stage)
			expect(
				actionsById[simple.primaryActionByStage[stage]],
				stage,
			).toBeTruthy()
		}
		const request = (status) => primaryFor({ status, ticketType: 'request' })
		expect(request('new').id).toBe('ticket-start')
		expect(request('in_progress').id).toBe('ticket-answer')
		expect(request('awaiting_customer').id).toBe('ticket-answer')
		for (const status of lifecycle.final) {
			expect(request(status), status).toBeNull()
		}
	})

	it('is absent on a contact moment in progress, which keeps Close in the menu', () => {
		const moment = { status: 'in_progress', ticketType: 'interaction' }
		expect(primaryFor(moment)).toBeNull()
		expect(visible(actionsById['ticket-close'], moment)).toBe(true)
		// A new contact moment can still be taken on.
		expect(primaryFor({ status: 'new', ticketType: 'interaction' }).id).toBe(
			'ticket-start',
		)
	})

	it('opens the answer dialog, which saves through the one answer endpoint', () => {
		const answer = actionsById['ticket-answer']
		expect(answer).toMatchObject({
			type: 'open-modal',
			target: 'TicketAnswerDialog',
		})
		expect(registrySource).toMatch(
			/TicketAnswerDialog: \{\s+kind: 'modal',\s+component: TicketAnswerDialog,/,
		)
		const dialog = read('src', 'dialogs', 'TicketAnswerDialog.vue')
		expect(dialog).toContain("'/apps/pipelinq/api/tickets/{id}/answer'")
		expect(dialog).toContain('url="/apps/pipelinq/api/saved-replies"')
		expect(dialog).toContain("emit('cn:page:refresh', {})")
	})
})

describe('the what-now card', () => {
	const card = (ticket) =>
		resolveNextStep(simple.nextStep, ticket, simple.stageField)

	it('names a checklist for every stage that has a primary button, on fields the ticket has', () => {
		expect(Object.keys(simple.nextStep.stages).sort()).toEqual(
			Object.keys(simple.primaryActionByStage).sort(),
		)
		for (const [stage, entry] of Object.entries(simple.nextStep.stages)) {
			expect(STATUSES, stage).toContain(stage)
			expect(lifecycle.final, stage).not.toContain(stage)
			expect(entry.title, stage).toBeTruthy()
			expect(entry.after, stage).toBeTruthy()
			expect(entry.checklist.length, stage).toBeGreaterThan(0)
			for (const item of entry.checklist) {
				// Done is read from a field, never written as a literal or a guess.
				expect(
					Object.keys(item).filter((key) =>
						['done', 'doneWhen', 'doneField'].includes(key),
					),
					item.label,
				).toEqual(['doneField'])
				expect(ticketProperties[item.doneField], item.doneField).toBeTruthy()
			}
		}
	})

	it('ticks what the ticket already holds and leaves the rest to the button', () => {
		const fresh = card({ status: 'new', ticketType: 'request', client: 'c1' })
		expect(fresh.title).toBe('What now? Step 1: new')
		expect(fresh.items.map((item) => [item.label, item.done])).toEqual([
			['A handler is assigned', false],
			['The customer is known', true],
		])
		expect(primaryFor({ status: 'new', ticketType: 'request' }).id).toBe(
			'ticket-start',
		)
		const waiting = (portalReplies) =>
			card({
				status: 'awaiting_customer',
				ticketType: 'request',
				portalReplies,
			})
		expect(waiting([]).items[0].done).toBe(false)
		expect(waiting([{ text: 'Yes, that is right.' }]).items[0].done).toBe(true)
	})

	it('is absent on a finished ticket', () => {
		for (const status of lifecycle.final) {
			expect(card({ status, ticketType: 'request' }), status).toBeNull()
		}
	})
})

describe('the quick actions and the menu', () => {
	it('pin at most three declared actions', () => {
		expect(simple.quickActions.length).toBeLessThanOrEqual(3)
		for (const id of simple.quickActions) {
			expect(actionsById[id], id).toBeTruthy()
		}
	})

	it('log a contact moment on this ticket, seeded only with what always resolves', () => {
		const log = actionsById['ticket-log-contact']
		expect(log).toMatchObject({
			type: 'open-form',
			register: 'pipelinq',
			schema: 'ticket',
		})
		for (const field of Object.keys(log.props)) {
			expect(ticketProperties[field], field).toBeTruthy()
		}
		expect(TYPES).toContain(log.props.ticketType)
		expect(log.props.parentTicket).toBe('@objectId')
		// A token the library cannot resolve stays in the form as written. A
		// ticket without a contact would save `@object.contact` as a foreign
		// key, so no seed value may read a field of the record.
		for (const value of Object.values(log.props)) {
			expect(String(value).startsWith('@object.'), value).toBe(false)
		}
	})

	it('group the rest, and hide nothing behind an admin flag', () => {
		const pinned = new Set([
			...simple.quickActions,
			...Object.values(simple.primaryActionByStage),
		])
		const entries = simple.headerActions.filter(
			(action) => !pinned.has(action.id),
		)
		const groups = groupMenuEntries(entries, actionsById, { isAdmin: false })
		expect(groups.map((group) => group.label)).toEqual(['Status', 'Finish'])
		expect(groups.flatMap((group) => group.entries)).toHaveLength(entries.length)
		expect(simple.headerActions.some((action) => action.adminOnly)).toBe(false)
	})
})

describe('the pills and the side column', () => {
	it('give every status and every type a label', () => {
		for (const status of STATUSES) {
			const pill = resolvePill(simple.statusPill, { status })
			expect(pill.label, status).not.toBe(status)
		}
		for (const ticketType of TYPES) {
			const pill = resolvePill(simple.typePill, { ticketType })
			expect(pill.label, ticketType).not.toBe(ticketType)
		}
		expect(Object.keys(simple.statusPill.labels).sort()).toEqual(
			[...STATUSES].sort(),
		)
	})

	it('show the customer, the deadline, the linked case and what is related, from fields the ticket has', () => {
		expect(
			simple.sideColumn.map((card) =>
				typeof card === 'string' ? card : card.id,
			),
		).toEqual([
			'side-customer',
			'side-deadline',
			'side-case',
			'ticket-related',
			'ticket-resident-view',
			'ticket-resident-view-portal',
			'ticket-resident-view-internal',
		])
		for (const card of simple.sideColumn.filter(
			(item) => typeof item !== 'string',
		)) {
			expect(card.type).toBe('data')
			expect(card.content.editable).toBe(false)
			for (const field of card.content.include) {
				expect(ticketProperties[field], `${card.id}: ${field}`).toBeTruthy()
			}
		}
	})
})

describe('the body', () => {
	const widgetIds = simple.widgets.map((widget) => widget.id)

	it('is one tabs widget over the full width, with at most five tabs showing', () => {
		expect(simple.layout).toHaveLength(1)
		expect(simple.layout[0]).toMatchObject({
			widgetId: 'ticket-panels',
			gridX: 0,
			gridWidth: 12,
		})
		const panels = simple.widgets.find((widget) => widget.id === 'ticket-panels')
		expect(panels.type).toBe('tabs')
		expect(panels.content.maxVisibleTabs).toBe(5)
		for (const tab of panels.content.tabs) {
			// A tab holds one widget, or a stack of them (`widgetIds`).
			for (const id of tab.widgetIds || [tab.widgetId]) {
				expect(widgetIds, tab.label).toContain(id)
			}
		}
	})

	it('keeps every widget the page had, as a tab', () => {
		const panels = simple.widgets.find((widget) => widget.id === 'ticket-panels')
		const tabbed = panels.content.tabs.flatMap(
			(tab) => tab.widgetIds || [tab.widgetId],
		)
		// The strip itself is the page's own, given the board's four tabs.
		const children = full.config.widgets.filter(
			(widget) => widget.id !== 'ticket-panels',
		)
		for (const widget of children) {
			expect(tabbed, widget.id).toContain(widget.id)
			if (['ticket-decision-routes', 'ticket-data'].includes(widget.id)) {
				// The widgets the simple page changes: see the tests below.
				continue
			}
			expect(simple.widgets.find((item) => item.id === widget.id)).toEqual(
				widget,
			)
		}
		expect(
			simple.widgets.filter((widget) => widget.id === 'ticket-panels'),
		).toHaveLength(1)
	})

	it('offers Convert to case once: in the Case section, not again on the Decisions tab', () => {
		const head = (config) =>
			config.widgets.find((widget) => widget.id === 'ticket-decision-routes')
		const section = (config) =>
			(config.widgets || []).filter(
				(item) => item.type === 'RequestConversionWidget',
			)
		// The simple page has the Case section, so the tab's head leaves it out.
		expect(section(simple)).toHaveLength(1)
		expect(head(simple).content.convert).toBe(false)
		expect(head(simple).type).toBe('TicketDecisionRoutesWidget')
		// The full page has no Case section, so the tab's head offers it.
		expect(section(full.config)).toHaveLength(0)
		expect(head(full.config).content.convert).toBeUndefined()
		// The widget reads the switch from its content; the action shows by default.
		expect(registrySource).toContain('hideConvert: content.convert === false')
	})

	it('lists the contact moments logged on this ticket, which is what Log contact writes', () => {
		const list = simple.widgets.find(
			(widget) => widget.id === 'ticket-contactmoments',
		)
		expect(list.content.filter).toEqual({
			parentTicket: actionsById['ticket-log-contact'].props.parentTicket,
			ticketType: actionsById['ticket-log-contact'].props.ticketType,
		})
		for (const column of list.content.columns) {
			expect(ticketProperties[column.key], column.key).toBeTruthy()
		}
	})

	it('stacks the details, the conversation, the dossier, the Woo request and the case in the Overview tab', () => {
		const panels = simple.widgets.find((widget) => widget.id === 'ticket-panels')
		expect(panels.content.tabs.map((tab) => tab.label)).toEqual([
			'Overview',
			'Contact moments',
			'Related',
			'Decisions',
			'History',
		])
		expect(panels.content.tabs[0].widgetIds).toEqual([
			'ticket-data',
			'ticket-conversation',
			'ticket-dossier',
			'ticket-woo',
			'ticket-case',
		])
		const counted = panels.content.tabs.filter(
			(tab) => tab.countFrom === 'widget',
		)
		expect(counted.map((tab) => tab.label)).toEqual([
			'Contact moments',
			'Related',
		])
		const data = simple.widgets.find((widget) => widget.id === 'ticket-data')
		expect(data.content.columns).toBe(2)
		for (const key of data.content.include) {
			expect(ticketProperties[key], key).toBeTruthy()
		}
	})

	it('moves every section into a tab except the suggested colleagues, and drops only the answer box', () => {
		expect(simple.bodyWidgets.map((section) => section.id)).toEqual([
			'routing-suggestions',
		])
		const moved = {
			'dossier-snapshot': [
				'ticket-dossier',
				'DossierSnapshotWidget',
				'DossierSnapshotSection',
			],
			'woo-conversion': [
				'ticket-woo',
				'WooConversionWidget',
				'WooConversionSection',
			],
		}
		for (const section of full.config.bodyWidgets) {
			if (
				section.id === 'customer-reply'
				|| section.id === 'routing-suggestions'
				|| section.type === 'facts'
			) {
				continue
			}
			const [id, type, component] = moved[section.id]
			expect(section.component).toBe(component)
			const widget = simple.widgets.find((item) => item.id === id)
			expect(widget.type, section.id).toBe(type)
			expect(registrySource).toMatch(
				new RegExp(
					`\\n\\t${type}: \\{\\s+kind: 'widget',\\s+component: sectionWidget\\(\\s+'${type}',\\s+${component},`,
				),
			)
		}
		for (const [id, type] of [
			['ticket-conversation', 'conversation'],
			['ticket-case', 'RequestConversionWidget'],
		]) {
			expect(simple.widgets.find((item) => item.id === id).type).toBe(type)
		}
	})

	it('shows the resident view as the same facts cards on both pages', () => {
		for (const [full_, simple_] of [['resident-view', 'ticket-resident-view'], ['resident-view-portal', 'ticket-resident-view-portal'], ['resident-view-internal', 'ticket-resident-view-internal']]) {
			const section = full.config.bodyWidgets.find((item) => item.id === full_)
			const widget = simple.widgets.find((item) => item.id === simple_)
			expect(section.type).toBe('facts')
			expect(widget.type).toBe('facts')
			expect(widget.content.endpointSource).toEqual(section.content.endpointSource)
			expect(widget.content.rowsPath).toBe(section.content.rowsPath)
			expect(widget.visibleWhen).toEqual({ field: 'ticketType', op: 'eq', value: 'request' })
		}
	})

	it('offers convert to case through the section that asks first whether it can', () => {
		const source = read(
			'src',
			'views',
			'requests',
			'RequestConversionSection.vue',
		)
		expect(registrySource).toContain(
			'({ objectId }) => ({ requestId: objectId })',
		)
		expect(source).toContain('requestId: {')
		expect(read('appinfo', 'routes.php')).toContain(
			"'/api/handoff/request/{id}/convert-to-case'",
		)
	})
})

describe('the words', () => {
	it('exist in English and Dutch for every label the overlay adds', () => {
		const overlay = simpleFile.pages.find((page) => page.id === 'TicketDetail')
		const words = new Set()
		const collect = (value, key) => {
			if (Array.isArray(value)) {
				value.forEach((item) => collect(item, key))
			} else if (value && typeof value === 'object') {
				for (const [name, inner] of Object.entries(value)) {
					collect(
						inner,
						name === 'labels' ? 'label' : key === 'label' ? key : name,
					)
				}
			} else if (
				typeof value === 'string'
				&& [
					'label',
					'title',
					'formTitle',
					'successMessage',
					'group',
					'moreLabel',
					'ariaLabel',
					'emptyText',
					'after',
					'hint',
				].includes(key)
			) {
				words.add(value)
			}
		}
		const { _note, ...rest } = overlay
		collect(rest, '')
		expect(words.size).toBeGreaterThan(30)
		for (const word of words) {
			expect(en[word], `en "${word}"`).toBeTruthy()
			expect(nl[word], `nl "${word}"`).toBeTruthy()
		}
		expect(nl['Take on']).toBe('In behandeling nemen')
		expect(nl['Answer the customer']).toBe('De klant beantwoorden')
		expect(nl['Close the ticket']).toBe('Afsluiten')
	})

	it('use icons the app registers', () => {
		for (const item of [...simple.headerActions, ...simple.widgets]) {
			if (item.icon) {
				expect(iconsSource, item.id).toContain(`\n\t${item.icon},\n`)
			}
		}
	})
})

describe('the built page', () => {
	it('validates against the manifest schema the installed library ships', () => {
		const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'pipelinq-ticket-'))
		const file = path.join(dir, 'manifest.json')
		fs.writeFileSync(
			file,
			JSON.stringify(build(applyMenuModules(simpleFile, []))),
		)
		try {
			execFileSync(
				'node',
				[path.join(ROOT, 'tests', 'validate-manifest.js')],
				{
					env: { ...process.env, APP_MANIFEST: file },
					stdio: 'pipe',
				},
			)
		} catch (error) {
			throw new Error(`${error.stdout}\n${error.stderr}`, { cause: error })
		} finally {
			fs.rmSync(dir, { recursive: true, force: true })
		}
	}, 60_000)
})

describe('the conversation', () => {
	const thread = () => simple.widgets.find((widget) => widget.id === 'ticket-conversation')

	it('puts replies and answers in one thread, read only', () => {
		expect(thread().type).toBe('conversation')
		expect(thread().content).toMatchObject({
			fields: [
				{ field: 'portalReplies', side: 'them' },
				{ field: 'portalAnswers', side: 'us' },
			],
			textField: 'message',
			timeField: 'createdAt',
			usLabel: 'Employee',
			allowReply: false,
		})
	})

	it('shows nothing on a contact moment', () => {
		expect(thread().visibleWhen).toEqual({ field: 'ticketType', op: 'neq', value: 'interaction' })
	})

	it('is read from fields the ticket has; an answer saved before answers were kept is backfilled on the server', () => {
		for (const field of ['portalReplies', 'portalAnswers', 'customerMessage']) {
			expect(ticketProperties[field], field).toBeTruthy()
		}
		expect(read('appinfo', 'info.xml')).toContain('BackfillConversationFields')
	})
})
