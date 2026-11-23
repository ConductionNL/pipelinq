// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Let p-queue 10 (nested under @nextcloud/vue >= 9.14) construct under jsdom.
 *
 * PQueue extends the global EventTarget and, when `process` exists, calls
 * node:events `setMaxListeners(Infinity, this)`. In a jsdom spec the global
 * EventTarget is jsdom's, which Node's check does not accept, so merely
 * importing NcBlurHash (preloadImage's module-level queue) threw
 * ERR_INVALID_ARG_TYPE and the whole spec file failed to load. Browsers have
 * no `process`, so production never takes this path.
 *
 * Only that one argument-type refusal is ignored; any other error still throws.
 */

import events from 'node:events'

const setMaxListeners = events.setMaxListeners

events.setMaxListeners = function(n, ...eventTargets) {
	try {
		return setMaxListeners.call(this, n, ...eventTargets)
	} catch (error) {
		if (error?.code === 'ERR_INVALID_ARG_TYPE' && eventTargets.length > 0) {
			return undefined
		}
		throw error
	}
}
