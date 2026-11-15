// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * What the report sections share: the colour of each contact channel, and
 * a mixin that reads a setting from the section's workspace and loads the
 * section on mount and whenever its period changes.
 */

/** The colour of each contact channel; an unknown channel is grey. */
export const CHANNEL_COLORS = {
	telefoon: '#4c84db',
	email: '#f5a623',
	balie: '#7ed321',
	chat: '#9b59b6',
	social: '#e74c3c',
	brief: '#1abc9c',
	unknown: '#95a5a6',
}

/**
 * @param {string} channel The channel.
 * @return {string} Its colour.
 */
export function channelColor(channel) {
	return CHANNEL_COLORS[channel] ?? CHANNEL_COLORS.unknown
}

/**
 * The host defines `effectivePeriod` and `fetchData()`.
 */
export const reportSectionMixin = {
	inject: {
		cnSectionContext: { default: null },
	},

	watch: {
		effectivePeriod() {
			this.fetchData()
		},
	},

	mounted() {
		this.fetchData()
	},

	methods: {
		/**
		 * A setting from the section's workspace (`@workspace`), or a fallback.
		 *
		 * @param {string} key The setting, such as `period`.
		 * @param {string} fallback The value without one.
		 * @return {string} The value.
		 */
		fromWorkspace(key, fallback) {
			const ctx = this.cnSectionContext && this.cnSectionContext.workspace
			return (ctx && ctx[key]) || fallback
		},
	},
}
