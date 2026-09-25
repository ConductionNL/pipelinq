<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Detail-grid widget for CashShiftDetail: the shift's cash drops, with the
  - Add drop action in the card header. The button is always there and is
  - disabled, with the reason as its tooltip, once the shift is no longer open,
  - so a closed shift does not read as a page missing the action.
  -
  - The list is the library's own object-list, fed the manifest `content`. Its
  - generic Add is not used: it would write a cashDrop straight to OpenRegister,
  - skipping what POST /api/pos-shifts/{id}/drop enforces (POS operator only,
  - an open shift, a positive amount, droppedBy / droppedAt set by the server).
  - Add drop opens the drop dialog and posts there instead; the resulting
  - `cn:page:refresh` makes the list re-read.
  -->
<template>
	<CnWidgetWrapper
		class="cash-shift-drops cn-detail-page__catalog-card"
		:title="title || t('pipelinq', 'Drops')"
		titleIconPosition="left"
		:showRefresh="false"
		:showRequestFeature="false">
		<template #title-icon>
			<CnIcon name="CashMinus" :size="20" />
		</template>
		<template #actions>
			<!-- The reason sits on a wrapper: a disabled button gets no mouse
			     events, so a `title` on the button itself never shows. -->
			<span
				class="cash-shift-drops__add"
				:title="canDrop ? null : t('pipelinq', 'Drops can only be added to an open shift.')">
				<NcButton
					variant="secondary"
					:disabled="busy || !canDrop"
					@click="showDrop = true">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('pipelinq', 'Add drop') }}
				</NcButton>
			</span>
		</template>

		<CnObjectListWidget :content="listContent" />

		<CashShiftDropDialog
			v-if="showDrop"
			:submitting="busy"
			@close="showDrop = false"
			@confirm="recordDrop" />
	</CnWidgetWrapper>
</template>

<script>
import { CnIcon, CnObjectListWidget, CnWidgetWrapper } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import CashShiftDropDialog from '../../modals/CashShiftDropDialog.vue'
import { postShiftAction } from '../../services/posShiftActions.js'

export default {
	name: 'CashShiftDropsWidget',
	components: { CashShiftDropDialog, CnIcon, CnObjectListWidget, CnWidgetWrapper, NcButton, Plus },
	// The host also spreads the content keys and register / schema / store;
	// none of them belong on the root element.
	inheritAttrs: false,

	props: {
		/** The manifest widget content, handed to the object-list as-is. */
		content: {
			type: Object,
			default: () => ({}),
		},

		/** The shift, from the detail page. */
		objectData: {
			type: Object,
			default: null,
		},

		/** The shift id, from the detail page. */
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
			busy: false,
			showDrop: false,
		}
	},

	computed: {
		shiftId() {
			return this.objectId || this.objectData?.id || ''
		},

		/** @return {boolean} A drop can only be recorded on an open shift. */
		canDrop() {
			return Boolean(this.objectData) && (this.objectData.status || 'open') === 'open'
		},

		/** @return {object} The list content; adding goes through Add drop. */
		listContent() {
			return { ...this.content, allowCreate: false }
		},
	},

	methods: {
		/**
		 * Record a mid-shift drop.
		 *
		 * @param {object} payload The drop payload (amount, reason).
		 * @return {Promise<void>}
		 */
		async recordDrop(payload) {
			this.busy = true
			const ok = await postShiftAction(this.shiftId, 'drop', payload, t('pipelinq', 'Drop recorded.'))
			this.busy = false
			if (ok) {
				this.showDrop = false
			}
		},
	},
}
</script>

<style scoped>
.cash-shift-drops__add {
	display: inline-flex;
}

/* Let the hover through to the wrapper that carries the reason. */
.cash-shift-drops__add :deep(button:disabled) {
	pointer-events: none;
}
</style>
