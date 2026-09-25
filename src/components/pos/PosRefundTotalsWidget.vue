<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Detail-grid widget for PosRefundDetail: the refund totals (amount excl.
  - VAT, VAT, total refund), computed over the refund's lines by
  - PosRefundTotalsPanel. Re-reads the lines on `cn:page:refresh`.
  -->
<template>
	<CnWidgetWrapper
		class="pos-refund-totals-widget"
		:title="title || t('pipelinq', 'Refund totals')"
		titleIconPosition="left"
		:showActions="false"
		:showRefresh="false"
		:showRequestFeature="false">
		<template #title-icon>
			<CnIcon name="Cash" :size="20" />
		</template>

		<NcLoadingIcon v-if="loading" :size="24" />
		<PosRefundTotalsPanel v-else :lines="lines" />
	</CnWidgetWrapper>
</template>

<script>
import { CnIcon, CnWidgetWrapper } from '@conduction/nextcloud-vue'
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { NcLoadingIcon } from '@nextcloud/vue'
import PosRefundTotalsPanel from './PosRefundTotalsPanel.vue'
import { fetchRefundLines } from '../../services/posRefundLines.js'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'PosRefundTotalsWidget',
	components: { CnIcon, CnWidgetWrapper, NcLoadingIcon, PosRefundTotalsPanel },
	// The host also hands over register / schema / store and the widget
	// content; none of them belong on the root element.
	inheritAttrs: false,

	props: {
		/** The refund, from the detail page. */
		objectData: {
			type: Object,
			default: null,
		},

		/** The refund id, from the detail page. */
		objectId: {
			type: String,
			default: '',
		},

		/** The manifest widget title. */
		title: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			lines: [],
			loading: false,
		}
	},

	computed: {
		refundId() {
			return this.objectId || this.objectData?.id || ''
		},
	},

	watch: {
		refundId: {
			immediate: true,
			handler() {
				this.load()
			},
		},
	},

	created() {
		subscribe('cn:page:refresh', this.onPageRefresh)
	},

	beforeUnmount() {
		unsubscribe('cn:page:refresh', this.onPageRefresh)
	},

	methods: {
		/**
		 * Load the refund's lines.
		 *
		 * @param {object} [options] Options.
		 * @param {boolean} [options.silent] Keep the totals on screen while
		 *   reloading.
		 * @return {Promise<void>}
		 */
		async load({ silent = false } = {}) {
			if (!this.refundId) {
				return
			}
			this.loading = !silent
			try {
				this.lines = await fetchRefundLines(useObjectStore(), this.refundId)
			} catch {
				this.lines = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object} [payload] The refresh payload.
		 */
		onPageRefresh(payload) {
			const done = this.load({ silent: true })
			payload?.waitUntil?.(done)
		},
	},
}
</script>

<style scoped>
.pos-refund-totals-widget {
	height: 100%;
	display: flex;
	flex-direction: column;
}

/* The panel caps itself at 360px and hugs the end edge, for the wide refund
   form. In this narrow card it should use the full width. */
.pos-refund-totals-widget :deep(.pos-refund-totals) {
	max-width: none;
	margin-inline-start: 0;
}
</style>
