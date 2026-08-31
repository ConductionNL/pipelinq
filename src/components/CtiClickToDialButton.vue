<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - CtiClickToDialButton renders a phone icon next to any phone number;
  - clicking it issues a click-to-dial via the CTI API and surfaces a toast.
  -
  - @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-5.3
  -->
<template>
	<span class="cti-click-to-dial">
		<a :href="'tel:' + targetNumber" class="cti-click-to-dial__number">{{
			targetNumber
		}}</a>
		<NcButton
			v-if="enabled"
			variant="tertiary-no-background"
			:aria-label="t('pipelinq', 'Call {number}', { number: targetNumber })"
			:disabled="dialing"
			class="cti-click-to-dial__btn"
			data-testid="cti-click-to-dial-btn"
			@click="dial">
			<template #icon>
				<PhoneIcon :size="18" />
			</template>
		</NcButton>
	</span>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton } from '@nextcloud/vue'
import PhoneIcon from 'vue-material-design-icons/Phone.vue'
import { clickToDial } from '../services/ctiApi.js'

export default {
	name: 'CtiClickToDialButton',
	components: { NcButton, PhoneIcon },
	props: {
		targetNumber: { type: String, required: true },
		extension: { type: String, default: '' },
		/**
		 * Whether the button may place a call.
		 *
		 * Defaults ON deliberately: this is an opt-OUT flag, and naming it
		 * `disabled` would collide with the native attribute on the NcButton
		 * it renders.
		 */
		// eslint-disable-next-line vue/no-boolean-default
		enabled: { type: Boolean, default: true },
	},

	emits: ['initiated'],
	data() {
		return {
			dialing: false,
		}
	},

	methods: {
		async dial() {
			if (!this.targetNumber || !this.extension) {
				showError(
					t('pipelinq', 'Click-to-dial: extension or target missing.'),
				)
				return
			}
			this.dialing = true
			try {
				const result = await clickToDial(this.targetNumber, this.extension)
				if (result && result.success) {
					showSuccess(
						t('pipelinq', 'Call initiated — your extension will ring.'),
					)
					this.$emit('initiated', result)
				} else {
					showError(
						t('pipelinq', 'Click-to-dial failed: {error}', {
							error: (result && result.error) || 'unknown error',
						}),
					)
				}
			} catch (e) {
				showError(
					t('pipelinq', 'Click-to-dial failed: {error}', {
						error: e.message || 'network error',
					}),
				)
			} finally {
				this.dialing = false
			}
		},
	},
}
</script>

<style scoped>
.cti-click-to-dial {
	display: inline-flex;
	align-items: center;
	gap: 4px;
}

.cti-click-to-dial__btn {
	min-width: 24px;
}
</style>
