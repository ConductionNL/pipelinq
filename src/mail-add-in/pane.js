/**
 * The Outlook task pane (contact-moments-mail-add-in, D4).
 *
 * Plain DOM, no Nextcloud shell: the pane runs inside Outlook. It shows the
 * sender's contacts and clients with Open in pipelinq, offers Add as contact
 * for an unknown sender, and Log this mail on any record shown.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md
 */

import { translate as t } from '@nextcloud/l10n'
import { createApi, idOf, SignedOutError } from './api.js'
import { clearCredentials, loadCredentials, saveCredentials } from './credentials.js'
import { openOfficeDialog, signIn } from './loginFlow.js'
import { readOpenMail } from './openMail.js'

/**
 * Build an element.
 *
 * @param {string} tag        The tag.
 * @param {object} [props]    Properties to set (className, textContent, type, ...).
 * @param {Array}  [children] Child nodes.
 * @return {HTMLElement}
 */
function el(tag, props = {}, children = []) {
	const node = document.createElement(tag)
	for (const [key, value] of Object.entries(props)) {
		if (key === 'role' || key.startsWith('data-') || key.startsWith('aria-')) {
			node.setAttribute(key, value)
		} else {
			node[key] = value
		}
	}
	for (const child of children) {
		if (child) {
			node.appendChild(child)
		}
	}
	return node
}

/**
 * The pipelinq page of a record.
 *
 * @param {string} appUrl The app's absolute URL.
 * @param {object} record `{entityType, entityId}`.
 * @return {string}
 */
export function recordUrl(appUrl, record) {
	const base = String(appUrl ?? '').replace(/\/?$/, '/')
	const page = record.entityType === 'client' ? 'clients' : 'contacts'
	return base + page + '/' + encodeURIComponent(record.entityId)
}

/**
 * Create the pane.
 *
 * @param {object}   options        The dependencies.
 * @param {HTMLElement} options.root  Where the pane renders.
 * @param {object}   options.office The Office.js global.
 * @param {typeof fetch} options.fetch  `fetch`.
 * @param {object}   options.urls   The initial state: loginFlowUrl, lookupUrl, contactUrl, logUrl, appUrl.
 * @param {(ms: number) => Promise<void>} [options.wait] Resolves after the given ms (the login flow's poll pause).
 * @return {{start: () => Promise<void>}}
 */
export function createPane({ root, office, fetch, urls, wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms)) }) {
	const settings = office.context.roamingSettings
	let mail = null
	let api = null

	/**
	 * Replace the pane's content.
	 *
	 * @param {...HTMLElement} nodes The new content.
	 * @return {void}
	 */
	function show(...nodes) {
		root.replaceChildren(el('h2', { className: 'pq-mail-pane__title', textContent: t('pipelinq', 'Pipelinq') }), ...nodes)
	}

	/**
	 * A message in the pane, `role="alert"` for a problem.
	 *
	 * @param {string}  text    The message.
	 * @param {boolean} [alert] Whether it reports a problem.
	 * @return {HTMLElement}
	 */
	function note(text, alert = false) {
		const props = { className: alert ? 'pq-mail-pane__note pq-mail-pane__note--alert' : 'pq-mail-pane__note', textContent: text }
		if (alert) {
			props.role = 'alert'
		}
		return el('p', props)
	}

	/**
	 * The signed-out view.
	 *
	 * @param {string} [message] Why the colleague sees it again.
	 * @return {void}
	 */
	function showSignedOut(message = '') {
		const button = el('button', { type: 'button', className: 'pq-mail-pane__primary', textContent: t('pipelinq', 'Sign in') })
		button.addEventListener('click', () => startSignIn())
		show(
			message ? note(message, true) : null,
			note(t('pipelinq', 'Sign in with your Nextcloud account to see the sender of this mail in Pipelinq.')),
			button,
		)
	}

	/**
	 * Run the login flow, then look the sender up.
	 *
	 * @return {Promise<void>}
	 */
	async function startSignIn() {
		show(note(t('pipelinq', 'Finish signing in in the window that opened.')))
		try {
			const credentials = await signIn({
				fetch,
				loginFlowUrl: urls.loginFlowUrl,
				openLogin: (url) => openOfficeDialog(office, url),
				wait,
			})
			await saveCredentials(settings, credentials)
			api = createApi({ fetch, urls, credentials })
		} catch {
			showSignedOut(t('pipelinq', 'Signing in did not finish. Try again.'))
			return
		}
		await showSender()
	}

	/**
	 * Forget the sign-in.
	 *
	 * @return {Promise<void>}
	 */
	async function signOut() {
		api = null
		try {
			await clearCredentials(settings)
		} catch {
			// Forgotten for this session at least.
		}
		showSignedOut()
	}

	/**
	 * Handle a failed call: a revoked app password signs the pane out.
	 *
	 * @param {Error} error The failure.
	 * @return {Promise<void>|void}
	 */
	function failed(error) {
		if (error instanceof SignedOutError) {
			api = null
			return clearCredentials(settings)
				.catch(() => {})
				.then(() => showSignedOut(t('pipelinq', 'Your sign-in has ended. Sign in again.')))
		}
		show(note(error?.message || t('pipelinq', 'Something went wrong.'), true), signOutButton())
	}

	/**
	 * The sign-out button at the foot of the pane.
	 *
	 * @return {HTMLElement}
	 */
	function signOutButton() {
		const button = el('button', { type: 'button', className: 'pq-mail-pane__secondary', textContent: t('pipelinq', 'Sign out') })
		button.addEventListener('click', () => signOut())
		return el('footer', { className: 'pq-mail-pane__footer' }, [button])
	}

	/**
	 * One record with Open in pipelinq and Log this mail.
	 *
	 * @param {object} record The lookup's summary.
	 * @return {HTMLElement}
	 */
	function recordItem(record) {
		const status = el('p', { className: 'pq-mail-pane__status', 'aria-live': 'polite' })
		const log = el('button', { type: 'button', className: 'pq-mail-pane__primary', textContent: t('pipelinq', 'Log this mail') })
		log.addEventListener('click', async () => {
			log.disabled = true
			try {
				await api.logMail({
					entityType: record.entityType,
					entityId: record.entityId,
					subject: mail.subject,
					sentAt: mail.sentAt,
					preview: mail.preview,
				})
				status.textContent = t('pipelinq', 'Logged as a contact moment.')
			} catch (e) {
				log.disabled = false
				failed(e)
			}
		})
		const open = el('a', {
			href: recordUrl(urls.appUrl, record),
			target: '_blank',
			rel: 'noopener noreferrer',
			textContent: t('pipelinq', 'Open in pipelinq'),
		})
		const kind = record.entityType === 'client' ? t('pipelinq', 'Client') : t('pipelinq', 'Contact')
		return el('li', { className: 'pq-mail-pane__record', 'data-entity-id': record.entityId }, [
			el('strong', { textContent: record.name || record.email }),
			el('span', { className: 'pq-mail-pane__meta', textContent: record.clientName ? `${kind} · ${record.clientName}` : kind }),
			el('div', { className: 'pq-mail-pane__actions' }, [open, log]),
			status,
		])
	}

	/**
	 * The Add as contact form for an unknown sender.
	 *
	 * @param {object|null} organisation The client suggested by the mail domain.
	 * @return {HTMLElement}
	 */
	function addContactForm(organisation) {
		const name = el('input', { type: 'text', id: 'pq-mail-name', value: mail.name, required: true })
		const email = el('input', { type: 'email', id: 'pq-mail-email', value: mail.email, required: true })
		const link = organisation
			? el('input', { type: 'checkbox', id: 'pq-mail-organisation', checked: true })
			: null
		const submit = el('button', { type: 'submit', className: 'pq-mail-pane__primary', textContent: t('pipelinq', 'Add as contact') })
		const form = el('form', { className: 'pq-mail-pane__form' }, [
			el('label', { htmlFor: 'pq-mail-name', textContent: t('pipelinq', 'Name') }),
			name,
			el('label', { htmlFor: 'pq-mail-email', textContent: t('pipelinq', 'Email address') }),
			email,
			link
				? el('div', { className: 'pq-mail-pane__check' }, [
					link,
					el('label', { htmlFor: 'pq-mail-organisation', textContent: t('pipelinq', 'Link to {organisation}', { organisation: organisation.name }) }),
				])
				: null,
			submit,
		])
		form.addEventListener('submit', async (event) => {
			event.preventDefault()
			submit.disabled = true
			try {
				const created = await api.addContact({
					name: name.value,
					email: email.value,
					client: link?.checked ? organisation.entityId : '',
				})
				const record = {
					entityType: 'contact',
					entityId: idOf(created.object),
					name: name.value.trim(),
					email: email.value.trim().toLowerCase(),
					clientName: link?.checked ? organisation.name : '',
				}
				showRecords([record], t('pipelinq', 'Added as a contact.'))
			} catch (e) {
				submit.disabled = false
				failed(e)
			}
		})
		return form
	}

	/**
	 * The records of the sender.
	 *
	 * @param {Array<object>} records The records.
	 * @param {string} [message] A confirmation above them.
	 * @return {void}
	 */
	function showRecords(records, message = '') {
		show(
			message ? note(message) : null,
			el('ul', { className: 'pq-mail-pane__records' }, records.map(recordItem)),
			signOutButton(),
		)
	}

	/**
	 * Look the sender up and show the answer.
	 *
	 * @return {Promise<void>}
	 */
	async function showSender() {
		if (!mail.email) {
			show(note(t('pipelinq', 'This mail has no sender address.')), signOutButton())
			return
		}
		show(note(t('pipelinq', 'Looking up {email}…', { email: mail.email })))
		let result
		try {
			result = await api.lookup(mail.email)
		} catch (e) {
			await failed(e)
			return
		}
		const matches = Array.isArray(result?.matches) ? result.matches : []
		if (matches.length > 0) {
			showRecords(matches)
			return
		}
		show(
			note(t('pipelinq', '{email} is not in Pipelinq yet.', { email: mail.email })),
			addContactForm(result?.organisation ?? null),
			signOutButton(),
		)
	}

	/**
	 * Read the open mail and show the pane.
	 *
	 * @return {Promise<void>}
	 */
	async function start() {
		mail = await readOpenMail(office.context.mailbox.item)
		const credentials = loadCredentials(settings)
		if (credentials === null) {
			showSignedOut()
			return
		}
		api = createApi({ fetch, urls, credentials })
		await showSender()
	}

	return { start }
}
