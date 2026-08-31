<template>
	<div class="barcode-scanner">
		<div class="barcode-scanner__row">
			<NcTextField
				ref="field"
				class="barcode-scanner__field"
				:label="t('pipelinq', 'Scan barcode')"
				:modelValue="value"
				:disabled="status === 'loading'"
				@update:modelValue="onInput"
				@keydown.enter="emitManual" />

			<div class="barcode-scanner__status" aria-live="polite">
				<NcLoadingIcon v-if="status === 'loading'" :size="20" />
				<Check
					v-else-if="status === 'found'"
					:size="20"
					class="barcode-scanner__icon--success" />
			</div>

			<NcButton
				variant="tertiary"
				:aria-label="t('pipelinq', 'Submit barcode')"
				:disabled="status === 'loading'"
				@click="emitManual">
				<template #icon>
					<Barcode :size="20" />
				</template>
			</NcButton>

			<NcButton
				v-if="supported"
				variant="tertiary"
				:aria-label="t('pipelinq', 'Open camera')"
				:disabled="status === 'loading'"
				@click="onOpenCamera">
				<template #icon>
					<Camera :size="20" />
				</template>
			</NcButton>
		</div>

		<NcNoteCard
			v-if="status === 'error' && errorMessage"
			type="error"
			class="barcode-scanner__error">
			{{ errorMessage }}
		</NcNoteCard>

		<div
			v-if="scanning"
			class="barcode-scanner__overlay"
			@keydown.esc="onCloseCamera">
			<video
				ref="videoEl"
				class="barcode-scanner__video"
				autoplay
				playsinline
				muted />
			<div class="barcode-scanner__reticle" aria-hidden="true">
				<svg viewBox="0 0 200 120" width="200" height="120">
					<rect
						x="4"
						y="4"
						width="192"
						height="112"
						rx="8"
						fill="none"
						stroke="#fff"
						stroke-width="3"
						stroke-dasharray="24 12" />
				</svg>
			</div>
			<p class="barcode-scanner__hint">
				{{ t('pipelinq', 'Aim at barcode…') }}
			</p>
			<NcButton
				variant="primary"
				class="barcode-scanner__close"
				@click="onCloseCamera">
				<template #icon>
					<Close :size="20" />
				</template>
				{{ t('pipelinq', 'Close camera') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcNoteCard, NcTextField } from '@nextcloud/vue'
import Barcode from 'vue-material-design-icons/Barcode.vue'
import Camera from 'vue-material-design-icons/Camera.vue'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'
import { useBarcodeScanner } from '../../composables/useBarcodeScanner.js'

export default {
	name: 'BarcodeScanner',
	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		Barcode,
		Camera,
		Check,
		Close,
	},

	props: {
		/** Autofocus the HID input on mount so a keyboard-wedge scanner types into it. */
		autofocus: {
			type: Boolean,
			// A keyboard-wedge scanner types into whatever holds focus, so this
			// input must claim it by default or the first scan of a session is lost.
			// eslint-disable-next-line vue/no-boolean-default
			default: true,
		},

		/** Scan lifecycle status: idle | loading | found | error. */
		status: {
			type: String,
			default: 'idle',
			validator: (v) => ['idle', 'loading', 'found', 'error'].includes(v),
		},

		/** Error message shown when status is 'error'. */
		errorMessage: {
			type: String,
			default: '',
		},
	},

	emits: ['camera-error', 'scan'],
	setup(props, { emit }) {
		const { supported, scanning, videoEl, startCamera, stopCamera } =
			useBarcodeScanner((barcode) => {
				emit('scan', barcode)
			})
		return { supported, scanning, videoEl, startCamera, stopCamera }
	},

	data() {
		return {
			value: '',
		}
	},

	mounted() {
		if (this.autofocus) {
			this.focusField()
		}
	},

	methods: {
		/**
		 * Focus the HID input.
		 */
		focusField() {
			this.$nextTick(() => {
				const el = this.$refs.field?.$el?.querySelector('input')
				if (el) {
					el.focus()
				}
			})
		},

		/**
		 * Track typed / scanned input.
		 *
		 * @param {string} v The new value.
		 */
		onInput(v) {
			this.value = v
		},

		/**
		 * Emit a manually typed/submitted barcode and reset the field.
		 */
		emitManual() {
			const barcode = this.value.trim()
			if (barcode === '') {
				return
			}
			this.$emit('scan', barcode)
			this.value = ''
			this.focusField()
		},

		/**
		 * Open the camera viewfinder.
		 *
		 * @spec openspec/specs/pos-barcode-scan/spec.md#REQ-PBS-002
		 */
		async onOpenCamera() {
			try {
				await this.startCamera()
			} catch {
				this.$emit('camera-error')
			}
		},

		/**
		 * Close the camera viewfinder without emitting a scan.
		 */
		onCloseCamera() {
			this.stopCamera()
			this.focusField()
		},
	},
}
</script>

<style scoped>
.barcode-scanner__row {
	display: flex;
	align-items: flex-end;
	gap: 4px;
}

.barcode-scanner__field {
	flex: 1;
	min-width: 240px;
}

.barcode-scanner__status {
	display: flex;
	align-items: center;
	justify-content: center;
	min-width: 24px;
	height: 44px;
}

.barcode-scanner__icon--success {
	color: var(--color-success);
}

.barcode-scanner__error {
	margin-top: 8px;
}

.barcode-scanner__overlay {
	position: fixed;
	inset: 0;
	z-index: 10000;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	background: rgba(0, 0, 0, 0.85);
}

.barcode-scanner__video {
	max-width: 100%;
	max-height: 70vh;
	border-radius: var(--border-radius-large);
}

.barcode-scanner__reticle {
	position: absolute;
	top: 50%;
	inset-inline-start: 50%;
	transform: translate(-50%, -50%);
	pointer-events: none;
}

.barcode-scanner__hint {
	margin-top: 16px;
	color: #fff;
	font-size: 1.1em;
}

.barcode-scanner__close {
	margin-top: 16px;
}
</style>
