<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<div class="segment-builder">
		<div class="builder-header">
			<h3 class="builder-title">
				{{ t('pipelinq', 'Segment rules') }}
			</h3>
			<div class="builder-estimate" role="status" aria-live="polite">
				<NcLoadingIcon v-if="estimating" :size="20" />
				<span v-else-if="estimateError" class="builder-estimate__error">
					{{ t('pipelinq', 'Could not estimate audience size.') }}
				</span>
				<span v-else>
					{{ t('pipelinq', 'Estimated members:') }}
					<strong>{{ estimateLabel }}</strong>
				</span>
			</div>
		</div>

		<SegmentRuleNode
			:node="tree"
			:depth="0"
			:entityType="entityType"
			:fieldOptions="fieldOptions"
			:errors="errors"
			@update:node="onTreeUpdate"
			@validateLeaf="validateLeaf" />

		<p v-if="validationError" class="builder-error" role="alert">
			{{ validationError }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon } from '@nextcloud/vue'
import SegmentRuleNode from './SegmentRuleNode.vue'

const DEBOUNCE_ESTIMATE_MS = 400
const DEBOUNCE_VALIDATE_MS = 250

/**
 * Default rule tree shape used when modelValue is empty.
 *
 * @return {object} An empty AND-group with no children.
 */
function emptyTree() {
	return { type: 'AND', children: [] }
}

export default {
	name: 'SegmentBuilder',
	components: {
		NcLoadingIcon,
		SegmentRuleNode,
	},

	props: {
		modelValue: {
			type: Object,
			default: () => emptyTree(),
		},

		entityType: {
			type: String,
			required: true,
			validator: (v) => ['contact', 'customer'].includes(v),
		},

		fieldOptions: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['update:modelValue', 'validity-change'],
	data() {
		return {
			tree: this.cloneTree(this.modelValue),
			estimating: false,
			estimateError: false,
			estimatedSize: null,
			errors: {},
			validationError: '',
			estimateTimer: null,
			validateTimer: null,
		}
	},

	computed: {
		/**
		 * Renderable estimate label for the audience size.
		 *
		 * @return {string} Either the numeric estimate or a dash.
		 */
		estimateLabel() {
			if (this.estimatedSize === null) {
				return '—'
			}
			return String(this.estimatedSize)
		},

		/**
		 * Whether the current rule tree has any leaf condition.
		 *
		 * @return {boolean} True when at least one leaf is present.
		 */
		hasAnyLeaf() {
			return this.countLeaves(this.tree) > 0
		},
	},

	watch: {
		modelValue: {
			handler(next) {
				// Avoid loops: only re-clone if reference actually differs.
				if (next !== this.tree) {
					this.tree = this.cloneTree(next)
				}
			},

			deep: true,
		},
	},

	beforeUnmount() {
		if (this.estimateTimer) {
			clearTimeout(this.estimateTimer)
		}
		if (this.validateTimer) {
			clearTimeout(this.validateTimer)
		}
	},

	methods: {
		/**
		 * Deep clone the rule tree so parent props remain immutable.
		 *
		 * @param {object} node The node to clone.
		 * @return {object} A structural copy.
		 */
		cloneTree(node) {
			if (!node || typeof node !== 'object') {
				return emptyTree()
			}
			try {
				return JSON.parse(JSON.stringify(node))
			} catch {
				return emptyTree()
			}
		},

		/**
		 * Recursively count leaf predicates in a node tree.
		 *
		 * @param {object} node The tree (or sub-tree) node.
		 * @return {number} Leaf count.
		 */
		countLeaves(node) {
			if (!node) {
				return 0
			}
			if (Array.isArray(node.children)) {
				return node.children.reduce(
					(sum, child) => sum + this.countLeaves(child),
					0,
				)
			}
			return node.field ? 1 : 0
		},

		/**
		 * Handle a tree update from the recursive node component.
		 * Emits update:modelValue and schedules estimate + validate calls.
		 *
		 * @param {object} updated The new tree value.
		 * @spec openspec/specs/marketing-ui/spec.md#requirement-segment-builder-ui-composes-rule-trees
		 */
		onTreeUpdate(updated) {
			this.tree = updated
			this.$emit('update:modelValue', this.cloneTree(updated))
			this.scheduleEstimate()
			this.scheduleValidate()
		},

		/**
		 * Debounce a backend size-estimate call.
		 *
		 * @spec openspec/specs/marketing-ui/spec.md#scenario-live-size-estimate-shown
		 */
		scheduleEstimate() {
			if (this.estimateTimer) {
				clearTimeout(this.estimateTimer)
			}
			if (!this.hasAnyLeaf) {
				this.estimatedSize = 0
				this.estimating = false
				this.estimateError = false
				return
			}
			this.estimating = true
			this.estimateError = false
			this.estimateTimer = setTimeout(
				() => this.runEstimate(),
				DEBOUNCE_ESTIMATE_MS,
			)
		},

		/**
		 * Perform the size estimate by posting the current rules to the
		 * segment-size endpoint. The endpoint accepts an inline rule payload
		 * so the segment does not need to be persisted yet.
		 */
		async runEstimate() {
			try {
				const url = generateUrl('/apps/pipelinq/api/segments/size')
				const { data } = await axios.post(url, {
					entityType: this.entityType,
					rules: this.tree,
				})
				this.estimatedSize =
					typeof data?.estimatedSize === 'number'
						? data.estimatedSize
						: typeof data?.size === 'number'
							? data.size
							: 0
				this.estimateError = false
			} catch {
				this.estimateError = true
			} finally {
				this.estimating = false
			}
		},

		/**
		 * Debounce a backend rule-tree validate call.
		 *
		 * @spec openspec/specs/marketing-ui/spec.md#scenario-visual-rule-tree-with-live-validation
		 */
		scheduleValidate() {
			if (this.validateTimer) {
				clearTimeout(this.validateTimer)
			}
			this.validateTimer = setTimeout(
				() => this.runValidate(),
				DEBOUNCE_VALIDATE_MS,
			)
		},

		/**
		 * Validate the current rule tree against the server-side SegmentService
		 * validator. Errors are stored per-path so leaf rows can render their
		 * field-level message.
		 */
		async runValidate() {
			this.validationError = ''
			this.errors = {}
			if (!this.hasAnyLeaf) {
				this.$emit('validity-change', false)
				return
			}
			try {
				const url = generateUrl('/apps/pipelinq/api/segments/validate')
				const { data } = await axios.post(url, {
					entityType: this.entityType,
					rules: this.tree,
				})
				if (data?.valid === false) {
					this.validationError =
						data?.error || this.t('pipelinq', 'Invalid rules.')
					this.errors = data?.fieldErrors || {}
					this.$emit('validity-change', false)
				} else {
					this.$emit('validity-change', true)
				}
			} catch (e) {
				const response = e?.response?.data
				this.validationError =
					response?.error
					|| this.t('pipelinq', 'Could not validate rules.')
				this.errors = response?.fieldErrors || {}
				this.$emit('validity-change', false)
			}
		},

		/**
		 * Trigger an immediate validate call when a leaf field/operator/value
		 * blur event bubbles up from the recursive node component.
		 */
		validateLeaf() {
			this.scheduleValidate()
		},
	},
}
</script>

<style scoped>
.segment-builder {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.builder-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}

.builder-title {
	margin: 0;
	font-size: 1.05em;
	color: var(--color-text-maxcontrast);
}

.builder-estimate {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	color: var(--color-text-lighter);
}

.builder-estimate__error {
	color: var(--color-error);
}

.builder-error {
	color: var(--color-error);
	font-weight: 600;
	margin: 4px 0 0;
}
</style>
