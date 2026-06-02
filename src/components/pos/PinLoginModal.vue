<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -
  - @spec openspec/changes/pos-staff-pin-permissions/tasks.md#7
-->
<template>
	<NcDialog
		:name="t('pipelinq', 'Staff login')"
		:open="true"
		size="small"
		@closing="$emit('close')">
		<div class="pin-login">
			<NcSelect
				v-model="selectedStaff"
				class="pin-login__staff"
				:options="staffOptions"
				:input-label="t('pipelinq', 'Staff member')"
				:placeholder="t('pipelinq', 'Select staff member')"
				:loading="loadingStaff"
				label="displayName"
				:clearable="false" />

			<div
				class="pin-login__display"
				role="status"
				aria-live="polite"
				:aria-label="t('pipelinq', 'Entered PIN length: {n}', { n: pin.length })">
				<span
					v-for="i in pinMax"
					:key="i"
					class="pin-login__dot"
					:class="{ 'pin-login__dot--filled': i <= pin.length }" />
			</div>

			<div class="pin-login__keypad" role="group" :aria-label="t('pipelinq', 'PIN keypad')">
				<NcButton
					v-for="digit in digits"
					:key="digit"
					class="pin-login__key"
					:aria-label="String(digit)"
					:disabled="busy || pin.length >= pinMax"
					@click="press(digit)">
					{{ digit }}
				</NcButton>
				<NcButton
					class="pin-login__key"
					:aria-label="t('pipelinq', 'Clear')"
					:disabled="busy || pin.length === 0"
					@click="clear">
					⌫
				</NcButton>
				<NcButton
					class="pin-login__key"
					:aria-label="String(0)"
					:disabled="busy || pin.length >= pinMax"
					@click="press(0)">
					0
				</NcButton>
				<NcButton
					class="pin-login__key pin-login__key--submit"
					type="primary"
					:aria-label="t('pipelinq', 'Log in')"
					:disabled="busy || !canSubmit"
					@click="submit">
					✓
				</NcButton>
			</div>

			<NcNoteCard v-if="message" :type="messageType">
				{{ message }}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcSelect, NcNoteCard } from '@nextcloud/vue'
import { usePosSessionStore } from '../../store/modules/posSessionStore.js'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'PinLoginModal',
	components: {
		NcDialog,
		NcButton,
		NcSelect,
		NcNoteCard,
	},
	emits: ['close', 'login-success'],
	setup() {
		return {
			sessionStore: usePosSessionStore(),
			objectStore: useObjectStore(),
		}
	},
	data() {
		return {
			pin: '',
			pinMin: 4,
			pinMax: 6,
			digits: [1, 2, 3, 4, 5, 6, 7, 8, 9],
			staffOptions: [],
			selectedStaff: null,
			loadingStaff: false,
			busy: false,
			message: '',
			messageType: 'error',
		}
	},
	computed: {
		/**
		 * Whether the entered PIN and selected staff allow a submit.
		 *
		 * @return {boolean} True when ready to authenticate.
		 */
		canSubmit() {
			return !!this.selectedStaff && this.pin.length >= this.pinMin
		},
	},
	mounted() {
		this.loadStaff()
		window.addEventListener('keydown', this.onKey)
	},
	beforeDestroy() {
		window.removeEventListener('keydown', this.onKey)
	},
	methods: {
		/**
		 * Load the active staff members for the picker.
		 */
		async loadStaff() {
			this.loadingStaff = true
			try {
				await this.objectStore.fetchCollection('posStaff', { _limit: 200 })
				const rows = this.objectStore.getCollection('posStaff')?.results || []
				this.staffOptions = rows
					.filter(s => s.isActive !== false)
					.map(s => ({ id: s.id, displayName: s.displayName }))
			} catch (e) {
				this.message = t('pipelinq', 'Could not load staff members.')
				this.messageType = 'error'
			} finally {
				this.loadingStaff = false
			}
		},
		/**
		 * Append a digit to the PIN.
		 *
		 * @param {number} digit The pressed digit.
		 */
		press(digit) {
			if (this.pin.length < this.pinMax) {
				this.pin += String(digit)
			}
		},
		/**
		 * Remove the last entered digit.
		 */
		clear() {
			this.pin = this.pin.slice(0, -1)
		},
		/**
		 * Keyboard support: digits, backspace, enter (WCAG keyboard access).
		 *
		 * @param {KeyboardEvent} event The keydown event.
		 */
		onKey(event) {
			if (/^[0-9]$/.test(event.key)) {
				this.press(Number(event.key))
			} else if (event.key === 'Backspace') {
				this.clear()
			} else if (event.key === 'Enter' && this.canSubmit) {
				this.submit()
			}
		},
		/**
		 * Authenticate the selected staff member with the entered PIN.
		 */
		async submit() {
			if (!this.canSubmit || this.busy) {
				return
			}
			this.busy = true
			this.message = ''
			try {
				const response = await fetch(
					OC.generateUrl('/apps/pipelinq/api/pos/staff/auth'),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
						body: JSON.stringify({ staffId: this.selectedStaff.id, pin: this.pin }),
					},
				)
				const data = await response.json().catch(() => ({}))
				if (!response.ok) {
					this.message = data.error || t('pipelinq', 'Incorrect PIN')
					this.messageType = 'error'
					this.pin = ''
					return
				}
				this.sessionStore.openSession(data)
				this.$emit('login-success', data)
				this.$emit('close')
			} catch (e) {
				this.message = t('pipelinq', 'Login failed. Please try again.')
				this.messageType = 'error'
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.pin-login {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 8px 0;

	&__display {
		display: flex;
		justify-content: center;
		gap: 12px;
		min-height: 24px;
	}

	&__dot {
		width: 16px;
		height: 16px;
		border-radius: 50%;
		border: 2px solid var(--color-border-dark);

		&--filled {
			background-color: var(--color-primary-element);
			border-color: var(--color-primary-element);
		}
	}

	&__keypad {
		display: grid;
		grid-template-columns: repeat(3, 1fr);
		gap: 8px;
	}

	&__key {
		min-height: 56px;
		font-size: 1.4em;
	}
}
</style>
