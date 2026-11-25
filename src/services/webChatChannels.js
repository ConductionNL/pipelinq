// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
/**
 * Web chat channels for the admin Web chat section (requests-web-chat-and-bot D2).
 *
 * @spec openspec/changes/archive/2026-11-25-requests-web-chat-and-bot/tasks.md#task-1.2
 */
import axios from '@nextcloud/axios'
import { generateFilePath, generateUrl } from '@nextcloud/router'

const CHANNELS = '/apps/openregister/api/objects/pipelinq/webChatChannel'
const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'

/**
 * A random channel token: 32 letters and digits. Not secret, part of the embed line.
 *
 * @param {Crypto} [cryptoApi] The crypto source.
 * @return {string}
 * @spec openspec/changes/archive/2026-11-25-requests-web-chat-and-bot/tasks.md#task-1.2
 */
export function newChannelToken(cryptoApi = globalThis.crypto) {
	const bytes = new Uint8Array(32)
	cryptoApi.getRandomValues(bytes)
	return Array.from(bytes, (byte) => ALPHABET[byte % ALPHABET.length]).join('')
}

/**
 * The exact origins in a text, one per line or comma separated; anything that is not an http(s) origin is left out.
 *
 * @param {string} text The text.
 * @return {string[]}
 * @spec openspec/changes/archive/2026-11-25-requests-web-chat-and-bot/tasks.md#task-1.2
 */
export function parseOrigins(text) {
	const origins = []
	for (const part of String(text || '').split(/[\s,]+/)) {
		const candidate = part.trim().replace(/\/+$/, '').toLowerCase()
		if (/^https?:\/\/[a-z0-9.-]+(:\d{1,5})?$/.test(candidate) && !origins.includes(candidate)) {
			origins.push(candidate)
		}
	}
	return origins
}

/**
 * The line a website pastes for this channel.
 *
 * @param {string} origin This Nextcloud's origin.
 * @param {string} scriptPath The embed script's path.
 * @param {string} windowPath The chat window's path.
 * @return {string}
 * @spec openspec/changes/archive/2026-11-25-requests-web-chat-and-bot/tasks.md#task-1.2
 */
export function embedLine(origin, scriptPath, windowPath) {
	const chat = encodeURIComponent(origin + windowPath)
	return '<script src="' + origin + scriptPath + '?chat=' + chat + '" async></script>'
}

/**
 * The embed line of a channel on this Nextcloud.
 *
 * @param {object} channel The channel.
 * @return {string}
 * @spec openspec/changes/archive/2026-11-25-requests-web-chat-and-bot/tasks.md#task-1.2
 */
export function channelEmbedLine(channel) {
	return embedLine(
		window.location.origin,
		generateFilePath('pipelinq', 'js', 'pipelinq-web-chat.js'),
		generateUrl('/apps/pipelinq/chat/{token}', { token: channel.channelToken }),
	)
}

/**
 * The channels.
 *
 * @return {Promise<object[]>}
 * @spec openspec/changes/archive/2026-11-25-requests-web-chat-and-bot/tasks.md#task-1.2
 */
export async function fetchChannels() {
	const { data } = await axios.get(generateUrl(CHANNELS), { params: { _limit: 100 } })
	return Array.isArray(data?.results) ? data.results : []
}

/**
 * Write a channel: a new one is off, with a fresh token.
 *
 * @param {object} channel The channel.
 * @return {Promise<object>}
 * @spec openspec/changes/archive/2026-11-25-requests-web-chat-and-bot/tasks.md#task-1.2
 */
export async function saveChannel(channel) {
	const id = channel.id || channel?.['@self']?.id || ''
	const body = { ...channel }
	delete body['@self']
	delete body.id
	if (id === '') {
		const { data } = await axios.post(generateUrl(CHANNELS), { assistantEnabled: false, waitSeconds: 180, ...body, active: false, channelToken: newChannelToken() })
		return data
	}
	const { data } = await axios.put(generateUrl(CHANNELS + '/{id}', { id }), body)
	return data
}
