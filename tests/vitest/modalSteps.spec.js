// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Three modal steps: the article editor's start state, the social post
 * save (create or update, then submit), and the incoming call lookup with
 * the preview the address asks for.
 *
 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-the-interface
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const social = vi.hoisted(() => ({
	createPost: vi.fn(),
	fetchAccounts: vi.fn(),
	movePost: vi.fn(),
	updatePost: vi.fn(),
}))
const cti = vi.hoisted(() => ({
	escalationQueues: vi.fn(),
	incomingCall: vi.fn(),
	screenPop: vi.fn(),
	submitDisposition: vi.fn(),
}))

vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: {}, CnMarkdownEditor: {} }))
vi.mock('@nextcloud/dialogs', () => ({ FilePickerClosed: class {}, getFilePickerBuilder: vi.fn(), showError: vi.fn() }))
vi.mock('@nextcloud/dialogs/style.css', () => ({}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcLoadingIcon: {},
	NcNoteCard: {},
	NcSelect: {},
	NcTextArea: {},
	NcTextField: {},
}))
vi.mock('../../src/components/LabelSelect.vue', () => ({ default: {} }))
vi.mock('../../src/services/articlesApi.js', () => ({ createArticle: vi.fn(), updateArticle: vi.fn() }))
vi.mock('../../src/services/socialApi.js', () => social)
vi.mock('../../src/services/ctiApi.js', () => cti)
vi.mock('../../src/modals/CtiDispositionModal.vue', () => ({ default: {} }))
vi.mock('../../src/modals/NewContactIntakeModal.vue', () => ({ default: {} }))
vi.mock('../../src/modals/ScreenPopModal.vue', () => ({ default: {} }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))

let article
let compose
let card

beforeAll(async () => {
	globalThis.t = (app, text) => text
	article = (await import('../../src/modals/ArticleEditModal.vue')).default
	compose = (await import('../../src/modals/SocialPostComposeModal.vue')).default
	card = (await import('../../src/components/IncomingCallCard.vue')).default
}, 60000)

beforeEach(() => {
	Object.values(social).forEach((fn) => fn.mockReset())
	cti.screenPop.mockReset()
})

describe('ArticleEditModal.data', () => {
	it('copies the article into the model, the tags and the links', () => {
		const tags = ['a']
		const link = { url: 'https://a.nl' }
		const source = {
			title: 'T',
			slug: 's',
			summary: 'S',
			body: 'B',
			heroImage: 'h.png',
			language: 'en',
			portalPageRef: 'p',
			tags,
			links: [link],
		}
		const state = article.data.call({ article: source })
		expect(state.saving).toBe(false)
		expect(state.error).toBe('')
		expect(Object.keys(state.model)).toEqual(['title', 'slug', 'summary', 'body', 'heroImage', 'language', 'portalPageRef'])
		expect(state.model).toEqual({ title: 'T', slug: 's', summary: 'S', body: 'B', heroImage: 'h.png', language: 'en', portalPageRef: 'p' })
		expect(state.tags).toEqual(['a'])
		expect(state.tags).not.toBe(tags)
		expect(state.links).toEqual([{ url: 'https://a.nl' }])
		expect(state.links[0]).not.toBe(link)
	})

	it('starts a new article empty, in Dutch', () => {
		const state = article.data.call({ article: null })
		expect(state.model).toEqual({ title: '', slug: '', summary: '', body: '', heroImage: '', language: 'nl', portalPageRef: '' })
		expect(state.tags).toEqual([])
		expect(state.links).toEqual([])
		const odd = article.data.call({ article: { tags: 'x', links: 'y' } })
		expect(odd.tags).toEqual([])
		expect(odd.links).toEqual([])
	})
})

/**
 * @param {object|null} post The post being edited.
 * @return {object} A compose modal context.
 */
function composeCtx(post) {
	return { ...compose.methods, post, model: { body: 'Hi' }, chosenAccounts: ['a1'], saving: false, error: 'old', $emit: vi.fn() }
}

describe('SocialPostComposeModal.save', () => {
	it('creates a new post and submits it when asked', async () => {
		social.createPost.mockResolvedValue({ uuid: 'n1' })
		social.movePost.mockResolvedValue({ uuid: 'n1', status: 'submitted' })
		const ctx = composeCtx(null)
		const pending = compose.methods.save.call(ctx, true)
		expect(ctx.saving).toBe(true)
		expect(ctx.error).toBe('')
		await pending
		expect(social.createPost).toHaveBeenCalledWith({ body: 'Hi', accountIds: ['a1'] })
		expect(social.updatePost).not.toHaveBeenCalled()
		expect(social.movePost).toHaveBeenCalledWith('n1', 'submit')
		expect(ctx.$emit).toHaveBeenCalledWith('saved', { uuid: 'n1', status: 'submitted' })
		expect(ctx.saving).toBe(false)
	})

	it('updates an existing post by id or uuid and does not submit without asking', async () => {
		social.updatePost.mockResolvedValue({ id: 'p1' })
		const ctx = composeCtx({ id: 'p1', uuid: 'u1' })
		await compose.methods.save.call(ctx, false)
		expect(social.updatePost).toHaveBeenCalledWith('p1', { body: 'Hi', accountIds: ['a1'] })
		expect(social.movePost).not.toHaveBeenCalled()
		expect(ctx.$emit).toHaveBeenCalledWith('saved', { id: 'p1' })

		social.updatePost.mockResolvedValue({ uuid: 'u1' })
		await compose.methods.save.call(composeCtx({ uuid: 'u1' }), false)
		expect(social.updatePost).toHaveBeenLastCalledWith('u1', { body: 'Hi', accountIds: ['a1'] })
	})

	it('does not submit a saved post without an id', async () => {
		social.createPost.mockResolvedValue(null)
		const ctx = composeCtx(null)
		await compose.methods.save.call(ctx, true)
		expect(social.movePost).not.toHaveBeenCalled()
		expect(ctx.$emit).toHaveBeenCalledWith('saved', null)
	})

	it('shows the server error, or a general one', async () => {
		social.createPost.mockRejectedValue({ response: { data: { error: 'Nope' } } })
		const ctx = composeCtx(null)
		await compose.methods.save.call(ctx, false)
		expect(ctx.error).toBe('Nope')
		expect(ctx.saving).toBe(false)
		expect(ctx.$emit).not.toHaveBeenCalled()

		social.createPost.mockRejectedValue(new Error('x'))
		await compose.methods.save.call(ctx, false)
		expect(ctx.error).toBe('The post could not be saved.')
	})
})

/**
 * @param {object} call The call being looked up.
 * @return {object} An incoming call card context.
 */
function cardCtx(call) {
	return {
		...card.methods,
		call,
		looking: false,
		pop: null,
		chooserOpen: false,
		intakeOpen: false,
		wrapUp: null,
		wrapUpFor: vi.fn(() => 'wrap'),
	}
}

describe('IncomingCallCard.lookUp', () => {
	it('fills the pop from the lookup', async () => {
		cti.screenPop.mockResolvedValue({ action: 'open', matches: [{ id: 1 }], e164: '+31', rawNumber: '06' })
		const call = { id: 'c1', fromNumber: '06' }
		const ctx = cardCtx(call)
		const pending = card.methods.lookUp.call(ctx, call)
		expect(ctx.looking).toBe(true)
		await pending
		expect(cti.screenPop).toHaveBeenCalledWith('06')
		expect(ctx.pop).toEqual({ action: 'open', matches: [{ id: 1 }], e164: '+31', rawNumber: '06' })
		expect(ctx.looking).toBe(false)
		expect(ctx.chooserOpen).toBe(false)
		expect(ctx.intakeOpen).toBe(false)
		expect(ctx.wrapUp).toBeNull()
	})

	it('reads a failed or empty lookup as an intake without matches', async () => {
		cti.screenPop.mockRejectedValue(new Error('500'))
		const call = { id: 'c1', fromNumber: '06' }
		const ctx = cardCtx(call)
		await card.methods.lookUp.call(ctx, call)
		expect(ctx.pop).toEqual({ action: 'intake', matches: [], e164: '', rawNumber: '' })

		cti.screenPop.mockResolvedValue({ matches: 'x' })
		await card.methods.lookUp.call(ctx, call)
		expect(ctx.pop).toEqual({ action: 'intake', matches: [], e164: '', rawNumber: '' })
	})

	it('drops the answer for a call that is gone or replaced', async () => {
		cti.screenPop.mockResolvedValue({ action: 'open' })
		const call = { id: 'c1', fromNumber: '06' }
		const gone = cardCtx(null)
		await card.methods.lookUp.call(gone, call)
		expect(gone.pop).toBeNull()
		expect(gone.looking).toBe(true)
		const replaced = cardCtx({ id: 'c2' })
		await card.methods.lookUp.call(replaced, call)
		expect(replaced.pop).toBeNull()
	})

	it('opens the preview the address asks for', async () => {
		const two = { matches: [{ id: 1 }, { id: 2 }] }
		cti.screenPop.mockResolvedValue(two)
		let call = { id: 'c1', previewOpen: 'chooser' }
		let ctx = cardCtx(call)
		await card.methods.lookUp.call(ctx, call)
		expect(ctx.chooserOpen).toBe(true)

		cti.screenPop.mockResolvedValue({ matches: [{ id: 1 }] })
		ctx = cardCtx(call)
		await card.methods.lookUp.call(ctx, call)
		expect(ctx.chooserOpen).toBe(false)

		call = { id: 'c1', previewOpen: 'intake' }
		ctx = cardCtx(call)
		await card.methods.lookUp.call(ctx, call)
		expect(ctx.intakeOpen).toBe(true)
		expect(ctx.chooserOpen).toBe(false)

		call = { id: 'c1', previewOpen: 'disposition' }
		ctx = cardCtx(call)
		await card.methods.lookUp.call(ctx, call)
		expect(ctx.wrapUpFor).toHaveBeenCalledWith(call)
		expect(ctx.wrapUp).toBe('wrap')
		expect(ctx.intakeOpen).toBe(false)
	})
})
