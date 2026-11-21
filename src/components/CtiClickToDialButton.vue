<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - CtiClickToDialButton renders a phone icon next to any phone number;
  - clicking it issues a click-to-dial via the CTI API and surfaces a toast.
  -
  - Without an `extension` it reads the signed-in agent's own dial line and
  - shows only when a telephony platform is configured and the agent has an
  - extension; during a call it is disabled.
  -
  - @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-5.3
  - @spec openspec/changes/specify-capability-rows/tasks.md#task-36.1
  -->
<template>
	<span v-if="showNumber || canDial" class="cti-click-to-dial">
		<a v-if="showNumber" :href="'tel:' + targetNumber" class="cti-click-to-dial__number">{{
			targetNumber
		}}</a>
		<NcButton
			v-if="canDial"
			variant="tertiary-no-background"
			:aria-label="busy ? busyLabel : t('pipelinq', 'Call {number}', { number: targetNumber })"
			:title="busy ? busyLabel : t('pipelinq', 'Call {number}', { number: targetNumber })"
			:disabled="dialing || busy"
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
import { forgetDialLine, loadDialLine } from '../services/dialLine.js'

/**
 * @param {string} error Why the dial failed.
 */
function showDialFailure(error) {
	showError(t('pipelinq', 'Click-to-dial failed: {error}', { error }))
}

export default {
	name: 'CtiClickToDialButton',
	components: { NcButton, PhoneIcon },
	props: {
		targetNumber: { type: String, required: true },
		/**
		 * The extension to call from. Left empty, the button reads the
		 * signed-in agent's own dial line and shows only when it is on.
		 */
		extension: { type: String, default: '' },
		/**
		 * Whether to show the number as a `tel:` link before the button.
		 * Off where the host already shows the number.
		 */
		// eslint-disable-next-line vue/no-boolean-default
		showNumber: { type: Boolean, default: true },
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
			line: null,
		}
	},

	computed: {
		/**
		 * The extension the call is placed from: the given one, else the agent's own line.
		 *
		 * @return {string}
		 * @spec openspec/changes/specify-capability-rows/tasks.md#task-36.1
		 */
		fromExtension() {
			if (this.extension) {
				return this.extension
			}
			return this.line?.enabled ? this.line.extension : ''
		},

		/**
		 * Whether the button shows: allowed, a number, and an extension to call from.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/specify-capability-rows/tasks.md#task-36.1
		 */
		canDial() {
			return this.enabled && Boolean(this.targetNumber) && this.fromExtension !== ''
		},

		/**
		 * Whether the agent's own line is on a call or in its wrap-up.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/specify-capability-rows/tasks.md#task-36.1
		 */
		busy() {
			return !this.extension && this.line?.busy === true
		},

		/**
		 * @return {string} Why a busy line cannot dial.
		 * @spec openspec/changes/specify-capability-rows/tasks.md#task-36.1
		 */
		busyLabel() {
			return t('pipelinq', 'Cannot initiate call while on another call')
		},
	},

	/**
	 * Read the agent's own dial line when no extension is given.
	 *
	 * @spec openspec/changes/specify-capability-rows/tasks.md#task-36.1
	 */
	async created() {
		if (this.enabled && !this.extension) {
			this.line = await loadDialLine()
		}
	},

	methods: {
		/**
		 * Say the call is placed and tell the host, or say why it was not.
		 *
		 * @param {object|null} result The dial answer.
		 * @spec openspec/changes/specify-capability-rows/tasks.md#task-36.1
		 */
		reportDial(result) {
			if (result && result.success) {
				showSuccess(
					t('pipelinq', 'Call initiated — your extension will ring.'),
				)
				// The line is on a call now; the next button reads it again.
				forgetDialLine()
				this.$emit('initiated', result)
				return
			}
			showDialFailure((result && result.error) || 'unknown error')
		},

		/**
		 * Place the call from the extension, unless the line is busy.
		 *
		 * @spec openspec/changes/specify-capability-rows/tasks.md#task-36.1
		 */
		async dial() {
			if (!this.targetNumber || !this.fromExtension || this.busy) {
				showError(
					t('pipelinq', 'Click-to-dial: extension or target missing.'),
				)
				return
			}
			this.dialing = true
			try {
				this.reportDial(await clickToDial(this.targetNumber, this.fromExtension))
			} catch (e) {
				showDialFailure(e.message || 'network error')
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
