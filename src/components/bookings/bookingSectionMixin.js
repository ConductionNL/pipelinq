// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A booking body section's booking: its id from the `bookingId` prop or the
 * page's injected section context, the booking read from the object store,
 * and a silent re-read on `cn:page:refresh`, which a header action sends.
 *
 * The section supplies `loadLinked()`, which reads the records the booking
 * names once the booking itself is in.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
import { showError } from '@nextcloud/dialogs'
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { useObjectStore } from '../../store/modules/object.js'
import { sectionValues } from '../../utils/sectionContext.js'

export default {
	inject: {
		cnSectionContext: { default: null },
	},

	props: {
		/** The booking id (token-resolved from `@objectId` by CnBodySections). */
		bookingId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			booking: {},
			loading: false,
			resourceLookup: {},
		}
	},

	computed: {
		objectStore() {
			return useObjectStore()
		},

		/**
		 * The booking id, from the prop or the section context.
		 *
		 * @return {string}
		 */
		resolvedId() {
			if (this.bookingId) {
				return this.bookingId
			}
			const bag = sectionValues(this.cnSectionContext)
			return (bag && bag.objectId) || ''
		},
	},

	watch: {
		resolvedId: {
			immediate: true,
			handler() {
				this.load()
			},
		},
	},

	/**
	 * Re-read the booking on `cn:page:refresh` without the spinner.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	mounted() {
		this.onPageRefresh = (payload) => {
			const done = this.load({ silent: true })
			payload?.waitUntil?.(done)
		}
		subscribe('cn:page:refresh', this.onPageRefresh)
	},

	beforeUnmount() {
		unsubscribe('cn:page:refresh', this.onPageRefresh)
	},

	methods: {
		/**
		 * Load the booking, then the records it names.
		 *
		 * @param {{silent?: boolean}} [opts] `silent` skips the spinner (a refresh).
		 * @spec openspec/specs/appointment-booking/spec.md
		 */
		async load(opts = {}) {
			if (!this.resolvedId) {
				return
			}
			if (opts.silent !== true) {
				this.loading = true
			}
			try {
				this.booking = (await this.objectStore.fetchObject('appointmentBooking', this.resolvedId)) || {}
				await this.loadLinked()
			} catch (err) {
				showError(err?.response?.data?.error || t('pipelinq', 'Could not load booking.'))
			} finally {
				this.loading = false
			}
		},
	},
}
