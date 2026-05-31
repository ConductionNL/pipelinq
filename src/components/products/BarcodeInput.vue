<template>
	<div class="barcode-input">
		<NcTextField
			ref="field"
			class="barcode-input__field"
			:label="t('pipelinq', 'Scan or type a barcode')"
			:value="value"
			@update:value="onInput"
			@keydown.enter="emitScan" />
		<NcButton
			type="tertiary"
			:aria-label="t('pipelinq', 'Submit barcode')"
			@click="emitScan">
			<template #icon>
				<Barcode :size="20" />
			</template>
		</NcButton>
	</div>
</template>

<script>
import { NcButton, NcTextField } from '@nextcloud/vue'
import Barcode from 'vue-material-design-icons/Barcode.vue'

export default {
	name: 'BarcodeInput',
	components: {
		NcButton,
		NcTextField,
		Barcode,
	},
	props: {
		autofocus: {
			type: Boolean,
			default: true,
		},
	},
	emits: ['scan'],
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
		 * Focus the input so a keyboard-wedge (USB HID) scanner types into it.
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
		 * Emit the complete barcode and reset the field for the next scan.
		 */
		emitScan() {
			const barcode = this.value.trim()
			if (barcode === '') {
				return
			}
			this.$emit('scan', barcode)
			this.value = ''
			this.focusField()
		},
	},
}
</script>

<style scoped>
.barcode-input {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}

.barcode-input__field {
	flex: 1;
	min-width: 240px;
}
</style>
