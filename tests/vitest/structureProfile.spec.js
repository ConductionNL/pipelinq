/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The structure profiles: one manifest, a simple menu and the full one.
 *
 * Every assertion here builds the menu with the library's REAL
 * `buildManifest`. The profile leans on three of its behaviours (first
 * definition of a key wins, a missing `relocations` leaves the menu alone, a
 * removal drops a leaf), and a fake would only prove that the fake agrees
 * with this file.
 *
 * What has to stay true:
 *   - the full profile is exactly what it was before profiles existed;
 *   - the simple menu is the eight entries of the design, in order, under
 *     three captions;
 *   - a module that is off is out of the menu, a module that is on is in it;
 *   - nothing is lost: every entry the full menu offers is in the simple menu
 *     or its settings, or a page the simple menu opens has a card for it.
 *
 * The gates read `src/menu-layout.json` only (gate-53), so the no-loss rule
 * for the simple file is held here and nowhere else.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation-ia/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { execFileSync } from 'child_process'
import fs from 'fs'
import os from 'os'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'
import { saveMenuStructure } from '../../src/services/menuStructureSetting.js'
import {
	applyHomePage,
	applyMenuModules,
	holdUnreachableTours,
	MODULES_SETTING,
	resolveMenuModules,
} from '../../src/utils/menuModules.js'
import {
	applyPageDefaults,
	applyPageOverlay,
	applyProfileTours,
	buildProfiledManifest,
	navTheming,
	resolveNavPlaceholders,
	resolveStructureProfile,
	STRUCTURE_FULL,
	STRUCTURE_SETTING,
	STRUCTURE_SIMPLE,
} from '../../src/utils/structureProfile.js'

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
const iconsSource = read('src', 'icons.js')
const mainSource = read('src', 'main.js')
const en = readJson('l10n', 'en.json').translations
const nl = readJson('l10n', 'nl.json').translations
const MODULE_KEYS = Object.keys(simpleFile.modules)

/** A fresh manifest each time: buildManifest merges into what it is given. */
const manifest = () => readJson('src', 'manifest.json')
function build(file) {
	return buildProfiledManifest(buildManifest, manifest(), fragments, file)
}
/** The simple profile as main.js builds it, with the named modules on. */
function buildSimple(enabled = []) {
	return build(applyMenuModules(simpleFile, enabled))
}

/**
 * Every entry of a built menu, children included.
 *
 * @param {Array<object>} menu The built menu.
 * @return {Array<object>} The flat list.
 */
function flat(menu) {
	return menu.flatMap((entry) => [entry, ...flat(entry.children || [])])
}

/**
 * The entries of one section, in the order the navigation draws them.
 *
 * @param {Array<object>} menu The built menu.
 * @param {string} name `main`, `footer`, `settings` or `integrations`.
 * @return {Array<object>} The entries, by `order`.
 */
function section(menu, name) {
	return menu
		.filter((entry) => (entry.section || 'main') === name)
		.sort((a, b) => (a.order ?? Infinity) - (b.order ?? Infinity))
}

/**
 * Validate a built manifest against the schema the installed library ships.
 *
 * @param {object} built The built manifest.
 * @return {void} Throws, with the validator's output, when it does not validate.
 */
function validateBuilt(built) {
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'pipelinq-manifest-'))
	const file = path.join(dir, 'manifest.json')
	fs.writeFileSync(file, JSON.stringify(built))
	try {
		execFileSync('node', [path.join(ROOT, 'tests', 'validate-manifest.js')], {
			env: { ...process.env, APP_MANIFEST: file },
			stdio: 'pipe',
		})
	} catch (error) {
		throw new Error(`${error.stdout}\n${error.stderr}`, { cause: error })
	} finally {
		fs.rmSync(dir, { recursive: true, force: true })
	}
}

describe('the full profile', () => {
	it('is exactly what buildManifest made before profiles existed, with the newer index header controls held back', () => {
		const before = buildManifest(manifest(), fragments, fullFile)
		expect(build(fullFile)).toEqual(
			applyPageDefaults(before, fullFile.pageDefaults),
		)
		// The only difference: every index page that did not choose shows
		// the plain header row it had on nextcloud-vue ^2.61.0.
		const after = build(fullFile)
		for (const page of before.pages) {
			const now = after.pages.find((item) => item.id === page.id)
			if (page.type === 'index' && page.config?.headerFilters === undefined) {
				expect(now, page.id).toEqual({
					...page,
					config: { ...page.config, headerFilters: false },
				})
			} else {
				expect(now, page.id).toEqual(page)
			}
		}
		// And the module step leaves a file without modules alone.
		expect(applyMenuModules(fullFile, MODULE_KEYS)).toBe(fullFile)
		expect(applyHomePage(before, fullFile.home)).toEqual({
			manifest: before,
			homePage: null,
		})
	})

	it('still counts 48 entries: 40 main, 3 footer, 5 settings, 0 integrations', () => {
		const menu = build(fullFile).menu
		const count = (name) =>
			flat(menu.filter((entry) => (entry.section || 'main') === name)).length
		expect(flat(menu)).toHaveLength(48)
		expect(count('main')).toBe(40)
		expect(count('footer')).toBe(3)
		expect(count('settings')).toBe(5)
		expect(count('integrations')).toBe(0)
	})

	it('holds the header controls back on index pages only, and the simple profile draws the board header', () => {
		expect(fullFile.pageDefaults).toEqual({ index: { headerFilters: false } })
		expect(simpleFile.pageDefaults).toEqual({
			index: {
				showTitle: true,
				showTitleIcon: false,
				headerFilters: false,
				headerButtons: [
					{ action: 'export', label: 'Download' },
					{ action: 'actions-menu' },
					{ action: 'add', variant: 'primary', icon: 'Plus' },
				],
			},
		})
		const simpleIndex = buildSimple().pages.filter(
			(page) => page.type === 'index',
		)
		expect(simpleIndex.length).toBeGreaterThan(0)
		for (const page of simpleIndex) {
			expect(page.config?.headerFilters, page.id).toBe(false)
			expect(page.config?.showTitle, page.id).toBe(true)
		}
		const unchanged = { pages: [{ id: 'x', type: 'detail', config: {} }] }
		expect(applyPageDefaults(unchanged, undefined)).toBe(unchanged)
		expect(
			applyPageDefaults(
				{
					pages: [
						{ id: 'y', type: 'index', config: { headerFilters: true } },
					],
				},
				fullFile.pageDefaults,
			).pages[0].config.headerFilters,
		).toBe(true)
	})

	it('does not link to the Modules page, which only the simple menu needs', () => {
		const routes = flat(build(fullFile).menu).map((entry) => entry.route)
		expect(routes).not.toContain('Modules')
	})
})

describe('the simple profile', () => {
	const built = buildSimple()
	const main = section(built.menu, 'main')

	it('shows eight entries under two captions, in the order of the design', () => {
		// PqDashboard: Dashboard, My work and Queue sit at the top with no
		// caption above them; the board's first caption is Klantcontact.
		expect(main.map((entry) => entry.id)).toEqual([
			'KccWerkplek',
			'MyWork',
			'Queue',
			'ContactCaption',
			'Tickets',
			'ContactMomentsMenu',
			'AppointmentsMenu',
			'RelationsCaption',
			'Clients',
			'OrganisationsMenu',
		])
		const captions = main.filter((entry) => entry.type === 'caption')
		expect(captions.map((entry) => nl[entry.label])).toEqual([
			'Klantcontact',
			'Relaties',
		])
		const entries = main.filter((entry) => entry.type !== 'caption')
		expect(entries).toHaveLength(8)
		expect(entries.map((entry) => nl[entry.label])).toEqual([
			'Dashboard',
			'Mijn werk',
			'Wachtrij',
			'Vragen en meldingen',
			'Contactmomenten',
			'Afspraken',
			'Inwoners en bedrijven',
			'Organisaties',
		])
	})

	it('is flat: no entry holds another', () => {
		for (const entry of built.menu) {
			expect(entry.children ?? [], entry.id).toEqual([])
		}
	})

	it('keeps relocations out of the file, because any relocation step drops the captions', () => {
		// The library's relocation step ends by filtering out every entry with
		// no route, href, action or children. That is a caption. `{}` is enough
		// to run it, so the key has to be absent.
		expect(Object.hasOwn(simpleFile, 'relocations')).toBe(false)
		const withRelocations = build({
			...applyMenuModules(simpleFile, []),
			relocations: {},
		})
		expect(
			withRelocations.menu.filter((entry) => entry.type === 'caption'),
			'the library now keeps captions through relocations; the note in the profile file is stale',
		).toEqual([])
	})

	it('gives every entry a label in English and Dutch, an icon the app registers and a page that exists', () => {
		const pageIds = new Set(built.pages.map((page) => page.id))
		// The labels this profile writes itself need an English source entry
		// too. A label the manifest already carried is the manifest's to keep.
		const own = new Set(
			[
				...simpleFile.menu,
				simpleFile.modulesCaption,
				...MODULE_KEYS.flatMap((key) => simpleFile.modules[key].menu),
			]
				.map((entry) => entry.label)
				.filter(Boolean),
		)
		expect(own.size).toBeGreaterThan(10)
		for (const label of own) {
			expect(en[label], `en "${label}"`).toBeTruthy()
		}
		for (const entry of flat(buildSimple(MODULE_KEYS).menu)) {
			expect(nl[entry.label], `${entry.id}: nl "${entry.label}"`).toBeTruthy()
			if (entry.type === 'caption' || entry.href || !entry.route) {
				continue
			}
			expect(entry.icon, entry.id).toBeTruthy()
			expect(
				iconsSource,
				`${entry.id} names an icon src/icons.js lacks`,
			).toContain(`\n\t${entry.icon},\n`)
			expect(pageIds.has(entry.route), `${entry.id} -> ${entry.route}`).toBe(
				true,
			)
		}
	})

	it('keeps route and page of every entry it rewords', () => {
		const source = flat(build(fullFile).menu)
		for (const id of ['KccWerkplek', 'MyWork', 'Queue', 'Tickets', 'Clients']) {
			const original = source.find((entry) => entry.id === id)
			const shown = main.find((entry) => entry.id === id)
			expect(shown.route, id).toBe(original.route)
		}
		// Reports sits in the Advanced foldout, as in every app.
		const reports = section(built.menu, 'settings').find(
			(entry) => entry.id === 'ReportsMenu',
		)
		expect(reports).toMatchObject({ label: 'Reports', route: 'Reports' })
	})

	it('opens contact moments and organisations as views of a list, on values the seeded schema declares', () => {
		const moments = main.find((entry) => entry.id === 'ContactMomentsMenu')
		expect(moments.route).toBe('Tickets')
		const ticket = readJson(
			'lib',
			'Settings',
			'register.d',
			'99-unify-ticket-supertype.json',
		).components.schemas.ticket
		expect(ticket.properties.ticketType.enum).toContain(moments.query.ticketType)

		const organisations = main.find((entry) => entry.id === 'OrganisationsMenu')
		expect(organisations.route).toBe('Clients')
		const client = readJson('lib', 'Settings', 'pipelinq_register.json')
			.components.schemas.client
		expect(client.properties.type.enum).toContain(organisations.query.type)

		// Both lists fetch for themselves, which is what reads a filter from
		// the address.
		for (const id of ['Tickets', 'Clients']) {
			const page = built.pages.find((item) => item.id === id)
			expect(page.type, id).toBe('index')
			expect(page.config.schema, id).toBeTruthy()
		}
	})

	it('opens appointments on the bookings list and moves their set-up to settings', () => {
		const appointments = main.find((entry) => entry.id === 'AppointmentsMenu')
		expect(appointments.route).toBe('Bookings')
		const settings = section(built.menu, 'settings').map((entry) => entry.id)
		expect(settings).toEqual(expect.arrayContaining(['Services', 'Resources']))
		// Everything the full profile has in settings is still there.
		const fullSettings = section(build(fullFile).menu, 'settings').map(
			(entry) => entry.id,
		)
		expect(settings).toEqual(expect.arrayContaining(fullSettings))
	})

	it('keeps the footer links and adds the way to the Modules page', () => {
		const ids = (menu) => section(menu, 'footer').map((entry) => entry.id)
		expect(ids(built.menu)).toEqual([
			'Documentation',
			'ModulesMenu',
			'StoreMenu',
			'FeaturesRoadmapMenu',
		])
		// Reports moved into the Advanced foldout; nothing else left the footer.
		expect(
			ids(build(fullFile).menu).filter((id) => id !== 'ReportsMenu'),
		).toEqual(ids(built.menu).filter((id) => id !== 'ModulesMenu'))
	})

	it('repeats the lists of the full profile, so an entry retired there does not come back here', () => {
		expect(simpleFile.removals).toEqual(
			expect.arrayContaining(fullFile.removals),
		)
		expect(simpleFile.settingsSection).toEqual(
			expect.arrayContaining(fullFile.settingsSection),
		)
		expect(simpleFile.removalsReplacedBy).toMatchObject(
			fullFile.removalsReplacedBy,
		)
	})

	it('loses nothing: every entry of the full menu is shown, or a page the simple menu opens has a card for it', () => {
		const shown = flat(built.menu)
		const shownIds = new Set(shown.map((entry) => entry.id))
		const shownRoutes = new Set(
			shown.map((entry) => entry.route).filter(Boolean),
		)
		// The pages a reader reaches from an opened page by one card.
		const carded = new Set()
		for (const page of built.pages) {
			if (!shownRoutes.has(page.id)) {
				continue
			}
			for (const card of page.config?.cards ?? []) {
				carded.add(card.route)
			}
		}
		const lost = flat(build(fullFile).menu)
			.filter((entry) => entry.route || entry.href)
			.filter((entry) => !shownIds.has(entry.id))
			.filter((entry) => !shownRoutes.has(entry.route))
			.filter((entry) => !carded.has(entry.route))
			.map((entry) => entry.id)
		expect(lost).toEqual([])

		// The control: these entries are really out of the menu, so the check
		// above is passing on the cards and not on the menu.
		for (const id of ['Dashboard', 'Leads', 'Tasks', 'Contacts', 'Segments']) {
			expect(shownIds.has(id), id).toBe(false)
		}
		for (const route of [
			'Dashboard',
			'Leads',
			'Tasks',
			'Contacts',
			'SlaAttainment',
		]) {
			expect(carded.has(route), route).toBe(true)
		}
	})

	it('reaches the four contact centre reports from the one Reports entry', () => {
		const reports = built.pages.find((page) => page.id === 'Reports')
		const routes = reports.config.cards.map((card) => card.route)
		expect(routes).toEqual(
			expect.arrayContaining([
				'Rapportage',
				'ChannelAnalyticsView',
				'AgentPerformanceView',
				'SlaAttainment',
			]),
		)
		// The card is the profile's: the full profile's page is as it was.
		const fullReports = build(fullFile).pages.find(
			(page) => page.id === 'Reports',
		)
		expect(fullReports).toEqual(
			manifest().pages.find((page) => page.id === 'Reports'),
		)
		expect(routes).toHaveLength(fullReports.config.cards.length + 1)
	})

	it('builds the same 97 pages as the full profile, so every route stays', () => {
		const ids = (source) => source.pages.map((page) => page.id)
		const full = build(fullFile)
		expect(ids(built)).toEqual(ids(full))
		expect(built.pages).toHaveLength(97)
		expect(built.pages.map((page) => page.route)).toEqual(
			full.pages.map((page) => page.route),
		)
	})

	it('validates against the manifest schema the installed library ships, with and without modules', () => {
		validateBuilt(built)
		validateBuilt(buildSimple(MODULE_KEYS))
		validateBuilt(applyHomePage(built, simpleFile.home).manifest)
		// Three validator processes. The default five seconds is not enough
		// on a busy machine, and a timeout here reads as a broken manifest.
	}, 60_000)
})

describe('the brand block', () => {
	const theming = {
		name: 'Gemeente Zuiddrecht',
		logo: '/core/img/logo/logo.svg',
		emblem: '/apps/thematiq/img/emblem.svg',
	}
	const withTheming = () =>
		buildProfiledManifest(
			buildManifest,
			manifest(),
			fragments,
			applyMenuModules(simpleFile, []),
			{ theming },
		)

	it('names the app over the instance the theming capabilities answer, and the full profile declares none', () => {
		expect(simpleFile.nav.brand).toEqual({
			name: 'pipelinq',
			caption: '@theming.name',
			logo: '@theming.emblem|@theming.logo',
		})
		expect(fullFile.nav).toBeUndefined()
		// The set's emblem, never the wordmark beside the app's own name.
		expect(withTheming().nav.brand).toEqual({
			name: 'pipelinq',
			caption: 'Gemeente Zuiddrecht',
			logo: '/apps/thematiq/img/emblem.svg',
		})
		expect(build(fullFile).nav?.brand).toBeUndefined()
	})

	it('falls back to the wordmark on a set without an emblem', () => {
		for (const emblem of ['', undefined]) {
			expect(
				resolveNavPlaceholders(simpleFile.nav, { ...theming, emblem }).brand
					.logo,
			).toBe('/core/img/logo/logo.svg')
		}
	})

	it('reads the emblem from thematiq and the rest from Nextcloud theming', () => {
		expect(
			navTheming({
				theming: { name: 'Gemeente Zuiddrecht', logo: '/logo.svg' },
				nldesign: { logos: { emblem: '/emblem.svg' } },
			}),
		).toEqual({
			name: 'Gemeente Zuiddrecht',
			logo: '/logo.svg',
			emblem: '/emblem.svg',
		})
		expect(navTheming(null)).toEqual({ emblem: '' })
		expect(
			navTheming({
				theming: { name: 'X' },
				nldesign: { logos: { emblem: 7 } },
			}),
		).toEqual({ name: 'X', emblem: '' })
	})

	it('shows the app alone on an instance that answers nothing, and never a placeholder', () => {
		for (const answer of [null, {}, { name: '', logo: 7 }]) {
			expect(resolveNavPlaceholders(simpleFile.nav, answer).brand).toEqual({
				name: 'pipelinq',
				caption: '',
				logo: '',
			})
		}
		expect(JSON.stringify(buildSimple().nav)).not.toContain('@theming')
	})

	it('is what main.js hands the theming capabilities to', () => {
		expect(mainSource).toContain(
			"import { getCapabilities } from '@nextcloud/capabilities'",
		)
		expect(mainSource).toContain('theming: navTheming(getCapabilities())')
		expect(mainSource).toMatch(
			/import \{[^}]*\bnavTheming,[^}]*\} from '\.\/utils\/structureProfile\.js'/,
		)
	})

	it('validates against the manifest schema with the brand resolved', () => {
		validateBuilt(withTheming())
	}, 60_000)
})

describe('the modules', () => {
	const modulesPage = () =>
		buildSimple().pages.find((page) => page.id === 'Modules')

	it('are six, and each one is off until it is named', () => {
		expect(MODULE_KEYS).toEqual([
			'sales',
			'marketing',
			'pos',
			'products',
			'contracts',
			'loyalty',
		])
		const shown = new Set(flat(buildSimple().menu).map((entry) => entry.id))
		for (const key of MODULE_KEYS) {
			for (const id of simpleFile.modules[key].ids) {
				expect(shown.has(id), `${key}: ${id}`).toBe(false)
			}
		}
		expect(shown.has('ModulesCaption')).toBe(false)
	})

	it('own real menu entries, and no entry belongs to two modules', () => {
		const known = new Set(
			flat(buildManifest(manifest(), fragments, {}).menu).map(
				(entry) => entry.id,
			),
		)
		const seen = new Set()
		for (const key of MODULE_KEYS) {
			const module = simpleFile.modules[key]
			expect(en[module.label], key).toBeTruthy()
			expect(nl[module.label], key).toBeTruthy()
			for (const id of module.ids) {
				expect(known.has(id), `${key}: ${id} is not a menu entry`).toBe(true)
				expect(seen.has(id), `${id} is in two modules`).toBe(false)
				seen.add(id)
			}
			for (const entry of module.menu) {
				// An entry the module brings itself (a caption, or a link with
				// its own label and route) is not in the full menu.
				if (entry.label) continue
				expect(known.has(entry.id), `${key}: ${entry.id}`).toBe(true)
			}
		}
	})

	it.each(MODULE_KEYS)(
		'%s joins the menu when it is switched on, and only it',
		(key) => {
			const menu = buildSimple([key]).menu
			const shown = new Set(flat(menu).map((entry) => entry.id))
			for (const id of simpleFile.modules[key].ids) {
				expect(shown.has(id), id).toBe(true)
			}
			for (const other of MODULE_KEYS.filter((item) => item !== key)) {
				for (const id of simpleFile.modules[other].ids) {
					expect(shown.has(id), `${other}: ${id}`).toBe(false)
				}
			}
			// After Relations. Sales and Marketing carry their own caption (the
			// board groups them as Verkoop and Marketing); the rest sit under
			// the one Modules caption.
			const order = section(menu, 'main').map((entry) => entry.id)
			const at = (id) => order.indexOf(id)
			const own = new Set([
				...simpleFile.modules[key].ids,
				...simpleFile.modules[key].menu.map((entry) => entry.id),
			])
			for (const entry of simpleFile.modules[key].menu) {
				// Products belongs to the products module, so it stays out
				// of the menu while only Sales is on.
				if (!shown.has(entry.id)) continue
				expect(at(entry.id), entry.id).toBeGreaterThan(at('OrganisationsMenu'))
			}
			// The daily entries do not move.
			const daily = (ids) => ids.filter((id) => !own.has(id)).slice(0, 10)
			expect(daily(order)).toEqual(
				daily(section(buildSimple().menu, 'main').map((entry) => entry.id)),
			)
		},
	)

	it('have a card on the Modules page for every entry, on or off', () => {
		const page = modulesPage()
		// The library's typed page of link cards. Not a custom page (gate-69)
		// and not a second reports page (ADR-112). What it draws is in
		// modulesPage.spec.js, which mounts it.
		expect(page.type).toBe('links')
		expect(page.component).toBeUndefined()
		expect(
			buildSimple().pages.filter((item) => item.type === 'reports'),
		).toHaveLength(1)
		expect(
			[...manifest().pages, ...fragments.flatMap((f) => f.pages || [])].filter(
				(item) => item.type === 'custom' && item.id === 'Modules',
			),
		).toEqual([])
		const cards = page.config.cards
		const pageIds = new Set(buildSimple().pages.map((item) => item.id))
		const entries = flat(buildManifest(manifest(), fragments, {}).menu)
		for (const key of MODULE_KEYS) {
			expect(page.config.categories[key], key).toBe(
				simpleFile.modules[key].label,
			)
			for (const id of simpleFile.modules[key].ids) {
				const route = entries.find((entry) => entry.id === id).route
				const card = cards.find((item) => item.route === route)
				expect(card, `${key}: no card opens ${route}`).toBeTruthy()
				expect(card.category, id).toBe(key)
			}
		}
		for (const card of cards) {
			expect(pageIds.has(card.route), card.id).toBe(true)
			expect(iconsSource, card.id).toContain(`\n\t${card.icon},\n`)
			for (const text of [card.label, card.description]) {
				expect(en[text], `en "${text}"`).toBeTruthy()
				expect(nl[text], `nl "${text}"`).toBeTruthy()
			}
		}
		for (const text of [
			page.title,
			page.config.description,
			...Object.values(page.config.categories),
		]) {
			expect(en[text], `en "${text}"`).toBeTruthy()
			expect(nl[text], `nl "${text}"`).toBeTruthy()
		}
	})

	it('read a stored list, and drop a word that is not a module', () => {
		expect(resolveMenuModules('', simpleFile)).toEqual([])
		expect(resolveMenuModules(undefined, simpleFile)).toEqual([])
		expect(resolveMenuModules(['sales'], simpleFile)).toEqual([])
		expect(resolveMenuModules(' Loyalty, sales ,verkoop', simpleFile)).toEqual([
			'sales',
			'loyalty',
		])
		expect(resolveMenuModules('sales', fullFile)).toEqual([])
	})

	it('do not change the profile file they are applied to', () => {
		const before = JSON.stringify(simpleFile)
		applyMenuModules(simpleFile, MODULE_KEYS)
		applyMenuModules(simpleFile, [])
		expect(JSON.stringify(simpleFile)).toBe(before)
	})
})

describe('the start page', () => {
	it('is the contact centre dashboard, and the sales overview gets an address of its own', () => {
		const built = buildSimple()
		const { manifest: homed, homePage } = applyHomePage(built, simpleFile.home)
		expect(homePage).toBe('KccWerkplek')
		const route = (source, id) =>
			source.pages.find((page) => page.id === id).route
		expect(route(built, 'Dashboard')).toBe('/')
		expect(route(homed, 'Dashboard')).toBe('/sales-overview')
		expect(route(homed, 'KccWerkplek')).toBe(route(built, 'KccWerkplek'))
		// Nothing else moved, and no two pages share an address.
		const routes = homed.pages.map((page) => page.route)
		expect(new Set(routes).size).toBe(routes.length)
		expect(routes).not.toContain('/')
		expect(
			homed.pages.filter((page, at) => page.route !== built.pages[at].route),
		).toHaveLength(1)
	})

	it('changes nothing when the profile names no page, a page that is missing or an address in use', () => {
		const built = buildSimple()
		for (const home of [
			undefined,
			{},
			{ page: 'NoSuchPage', rootMovesTo: '/sales-overview' },
			{ page: 'KccWerkplek' },
			{ page: 'KccWerkplek', rootMovesTo: '/tickets' },
			{ page: 'Dashboard', rootMovesTo: '/sales-overview' },
		]) {
			expect(applyHomePage(built, home)).toEqual({
				manifest: built,
				homePage: null,
			})
		}
	})

	it('is what main.js redirects the app root to', () => {
		expect(mainSource).toContain('profileFile.home')
		expect(mainSource).toContain(
			"routes.push({ path: '/', redirect: { name: homePage } })",
		)
	})
})

describe('the getting-started tour', () => {
	const SALES_TOUR = 'pipelinq:getting-started'
	const CONTACT_CENTRE_TOUR = 'pipelinq:contact-centre'
	const navTargets = (tour) =>
		tour.steps
			.filter((step) => step.target?.kind === 'nav-item')
			.map((step) => step.target.ref)
	const tourById = (source, id) =>
		source.walkthrough.tours.find((tour) => tour.id === id)
	/**
	 * The condition CnAppRoot (nextcloud-vue 2.71) mounts the user settings'
	 * Walkthrough section on (`walkthroughEnabled`): no tour, no section, so
	 * nobody can start, continue or start over.
	 */
	const settingsOfferTour = (source) => {
		const w = source.walkthrough
		return !!(
			w
			&& w.enabled !== false
			&& Array.isArray(w.tours)
			&& w.tours.length > 0
		)
	}

	it('is a sales journey that the simple menu cannot carry, so it is held back there', () => {
		const built = buildSimple()
		// The library finds a `nav-item` by the route its entry opens.
		const shown = new Set(flat(built.menu).map((entry) => entry.route))
		const missing = navTargets(tourById(built, SALES_TOUR)).filter(
			(ref) => !shown.has(ref),
		)
		// The control: the tour really points at entries the simple menu lacks.
		expect(missing).toEqual(['Contacts', 'Products', 'Leads', 'Contracts'])

		const held = holdUnreachableTours(built)
		expect(tourById(held, SALES_TOUR)).toBeUndefined()
		// Held back, not deleted: the manifest and the built input keep it.
		expect(tourById(built, SALES_TOUR)).toBeDefined()
		expect(manifest().walkthrough.tours).toHaveLength(1)
		validateBuilt(held)
	})

	it('leaves the simple structure a tour of its own, so the user settings can start it', () => {
		for (const enabled of [[], MODULE_KEYS]) {
			const held = holdUnreachableTours(buildSimple(enabled))
			// On development the simple structure kept no tour: CnAppRoot
			// then left the Walkthrough section out of the user settings.
			expect(settingsOfferTour(held), enabled.join(',') || 'no modules').toBe(
				true,
			)
			expect(held.walkthrough.tours.map((tour) => tour.id)).toEqual([
				CONTACT_CENTRE_TOUR,
			])
			validateBuilt(held)
		}
		// Two validator processes: the default five seconds is not enough on
		// a busy machine, and a timeout here reads as a missing tour.
	}, 60_000)

	it('only points the contact centre tour at pages the simple menu opens', () => {
		const built = buildSimple()
		const tour = tourById(built, CONTACT_CENTRE_TOUR)
		const shown = new Set(flat(built.menu).map((entry) => entry.route))
		const refs = navTargets(tour)
		// The control: the tour does point at menu entries.
		expect(refs).toEqual(['Tickets', 'MyWork', 'Queue', 'Clients', 'Modules'])
		expect(refs.filter((ref) => !shown.has(ref))).toEqual([])

		const pages = new Map(built.pages.map((page) => [page.id, page]))
		expect(pages.has(tour.steps[0].target.ref)).toBe(true)
		// A route-match step names a page that exists, and an `index-add` step
		// follows the step that opened an index page of the schema it waits for.
		tour.steps.forEach((step, i) => {
			if (step.advanceOn?.type === 'route-match') {
				expect(pages.has(step.advanceOn.route), step.id).toBe(true)
			}
			if (step.target?.ref === 'index-add') {
				const page = pages.get(tour.steps[i - 1].advanceOn.route)
				expect(page.type, step.id).toBe('index')
				expect(page.config.schema, step.id).toBe(step.advanceOn.schema)
			}
		})
	})

	it('has English and Dutch text for every step of the contact centre tour', () => {
		const tour = tourById(buildSimple(), CONTACT_CENTRE_TOUR)
		const texts = [tour.title]
		for (const step of tour.steps) {
			texts.push(...[step.title, step.body, step.task].filter(Boolean))
		}
		for (const text of texts) {
			expect(en[text], text).toBe(text)
			expect(nl[text], text).toBeTruthy()
			expect(nl[text], text).not.toBe(text)
			expect(text, text).not.toMatch(/\u2014|--/)
		}
	})

	it('stays in the full structure, where every entry it points at is in the menu', () => {
		const full = build(fullFile)
		expect(holdUnreachableTours(full)).toBe(full)
		expect(full.walkthrough.enabled).toBe(true)
		// The full file declares no tours: the sales tour is the only one.
		expect(fullFile.tours).toBeUndefined()
		expect(full.walkthrough.tours.map((tour) => tour.id)).toEqual([SALES_TOUR])
		expect(mainSource).toMatch(
			/structureProfile === STRUCTURE_FULL\s+\? profiledManifest\s+: holdUnreachableTours\(profiledManifest\)/,
		)
	})

	it('leaves a manifest without tours alone', () => {
		const bare = { menu: [], pages: [] }
		expect(holdUnreachableTours(bare)).toBe(bare)
		expect(applyProfileTours(bare, undefined)).toBe(bare)
	})

	it('never replaces a manifest tour with a profile tour of the same id', () => {
		const built = { walkthrough: { tours: [{ id: 'a', steps: [] }] } }
		expect(applyProfileTours(built, [{ id: 'a', steps: [{ id: 'x' }] }])).toBe(
			built,
		)
		const added = applyProfileTours(built, [{ id: 'b', steps: [] }])
		expect(added.walkthrough.tours.map((tour) => tour.id)).toEqual(['a', 'b'])
		expect(built.walkthrough.tours).toHaveLength(1)
	})
})

describe('a page overlay', () => {
	it('replaces config keys, appends to lists and leaves the original alone', () => {
		const page = { id: 'P', title: 'P', config: { a: 1, list: [{ id: 'x' }] } }
		const out = applyPageOverlay(page, {
			id: 'P',
			config: { a: 2, b: 3 },
			configAppend: { list: [{ id: 'y' }], fresh: [{ id: 'z' }] },
		})
		expect(out).toEqual({
			id: 'P',
			title: 'P',
			config: {
				a: 2,
				b: 3,
				list: [{ id: 'x' }, { id: 'y' }],
				fresh: [{ id: 'z' }],
			},
		})
		expect(page).toEqual({
			id: 'P',
			title: 'P',
			config: { a: 1, list: [{ id: 'x' }] },
		})
	})

	it('patches list items by name, removes on null, and orders last', () => {
		const page = {
			id: 'P',
			config: { list: [{ id: 'a', n: 1 }, { key: 'b' }, 'c', { label: 'd' }] },
		}
		const out = applyPageOverlay(page, {
			id: 'P',
			configPatch: { list: { a: { n: 2 }, b: null } },
			configAppend: { list: [{ id: 'e' }] },
			configOrder: { list: ['e', 'd'] },
			slots: { 'widget-e': 'E' },
		})
		expect(out.config.list).toEqual([
			{ id: 'e' },
			{ label: 'd' },
			{ id: 'a', n: 2 },
			'c',
		])
		expect(out.slots).toEqual({ 'widget-e': 'E' })
	})

	it('is skipped and reported when it names a page the manifest does not have', () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		const out = build({
			...applyMenuModules(simpleFile, []),
			pages: [{ id: 'NoSuchPage', config: { a: 1 } }],
		})
		expect(out.pages.some((page) => page.id === 'NoSuchPage')).toBe(false)
		expect(warn).toHaveBeenCalledTimes(1)
		warn.mockRestore()
	})
})

describe('the structure setting', () => {
	it('reads anything that is not the word full as simple', () => {
		for (const raw of [
			undefined,
			null,
			'',
			'simple',
			'Full',
			'uitgebreid',
			1,
			true,
		]) {
			expect(resolveStructureProfile(raw)).toBe(STRUCTURE_SIMPLE)
		}
		expect(resolveStructureProfile('full')).toBe(STRUCTURE_FULL)
	})

	it('is what main.js picks the layout file by', () => {
		expect(mainSource).toContain("import menuLayout from './menu-layout.json'")
		expect(mainSource).toContain(
			"import simpleMenuLayout from './menu-layout.simple.json'",
		)
		expect(mainSource).toContain("loadState('pipelinq', STRUCTURE_SETTING, '')")
		expect(mainSource).toContain("loadState('pipelinq', MODULES_SETTING, '')")
		expect(mainSource).toMatch(
			/structureProfile === STRUCTURE_FULL\s+\? menuLayout\s+: applyMenuModules\(/,
		)
		expect(mainSource).toMatch(
			/buildProfiledManifest\(buildManifest, bundledManifest, fragments, profileFile, \{\s+theming:/,
		)
	})

	const answer = (config) => async () => ({
		ok: true,
		status: 200,
		json: async () => ({ success: true, config }),
	})

	it('is saved with the modules under their own keys, through the settings write', async () => {
		const calls = []
		const fetchImpl = async (url, init) => {
			calls.push({ url, init })
			return answer({
				[STRUCTURE_SETTING]: 'full',
				[MODULES_SETTING]: 'sales,pos',
			})()
		}
		const stored = await saveMenuStructure(
			{ structure: 'full', modules: ['sales', 'pos'] },
			{ url: '/apps/pipelinq/api/settings', requestToken: 'token', fetchImpl },
		)
		expect(stored).toEqual({ structure: 'full', modules: ['sales', 'pos'] })
		expect(calls).toHaveLength(1)
		expect(calls[0].init.method).toBe('PUT')
		expect(calls[0].init.headers.requesttoken).toBe('token')
		expect(JSON.parse(calls[0].init.body)).toEqual({
			menu_structure: 'full',
			menu_modules: 'sales,pos',
		})
	})

	it('does not report a refused save as saved', async () => {
		const refused = async () => ({
			ok: false,
			status: 403,
			json: async () => ({}),
		})
		await expect(
			saveMenuStructure(
				{ structure: 'full', modules: [] },
				{ url: '/x', requestToken: 't', fetchImpl: refused },
			),
		).rejects.toThrow('403')
	})

	it('does not report a save the server dropped as saved', async () => {
		// The settings write answers success for a key it does not know.
		for (const wanted of [
			{ structure: 'full', modules: [] },
			// Simple is the default, so a dropped save of it would read back as
			// simple by accident. The stored word itself has to come back.
			{ structure: 'simple', modules: [] },
		]) {
			await expect(
				saveMenuStructure(wanted, {
					url: '/x',
					requestToken: 't',
					fetchImpl: answer({}),
				}),
			).rejects.toThrow('did not store')
		}
		// The structure came back and the modules did not.
		await expect(
			saveMenuStructure(
				{ structure: 'simple', modules: ['sales'] },
				{
					url: '/x',
					requestToken: 't',
					fetchImpl: answer({ [STRUCTURE_SETTING]: 'simple' }),
				},
			),
		).rejects.toThrow('did not store')
	})
})
