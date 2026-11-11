/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * What the body sections of a social post (its variants, its publications)
 * share: the post they are bound to, from the `postId` prop or the section
 * context the page host provides, the read on mount, and the re-read on
 * `cn:page:refresh`, which the approval step in the page header sends after
 * a move.
 *
 * The section defines `load()`.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
 */
import { subscribe, unsubscribe } from '@nextcloud/event-bus'

export default {
	inject: {
		cnSectionContext: { default: null },
	},

	props: {
		/** The post id, on the post detail page (`@objectId`). */
		postId: {
			type: String,
			default: '',
		},
	},

	mounted() {
		this.load()
		subscribe('cn:page:refresh', this.load)
	},

	beforeUnmount() {
		unsubscribe('cn:page:refresh', this.load)
	},

	methods: {
		/**
		 * The id this section is bound to, either the prop or the section
		 * context the page host provides.
		 *
		 * @return {string} The post id.
		 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
		 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
		 */
		effectiveId() {
			if (this.postId) {
				return this.postId
			}
			const context = this.cnSectionContext || {}
			return context.objectId || context.id || ''
		},
	},
}
