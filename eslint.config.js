const {
	defineConfig,
} = require('@eslint/config-helpers')

const js = require('@eslint/js')

const {
	FlatCompat,
} = require('@eslint/eslintrc')

// The Vue-3 rule preset ships INSIDE @conduction/nextcloud-vue, so it can only
// be enabled after the dependency is installed. `conductionVue3Fixes` is an
// ARRAY OF THREE configs (not one object) and registers no plugins of its own,
// which is why it layers cleanly on top of the @nextcloud v8 base. It must be
// spread LAST so its rule severities win.
//
// Do NOT reach for `@nextcloud/eslint-config/vue3` instead: that preset sets
// `parserOptions.parser` to a bare string, which routes template expressions
// through @typescript-eslint/parser, drops `v-for` scope and manufactures
// hundreds of bogus `vue/valid-v-for` errors.
const {
	conductionVue3Fixes,
} = require('@conduction/nextcloud-vue/eslint')

const compat = new FlatCompat({
	baseDirectory: __dirname,
	recommendedConfig: js.configs.recommended,
	allConfig: js.configs.all,
})

module.exports = defineConfig([
	{
		extends: compat.extends('@nextcloud'),

		settings: {
			'import/resolver': {
				alias: {
					map: [
						['@', './src'],
						// Resolve the library from the INSTALLED package, not the
						// sibling monorepo checkout: `apps-extra/nextcloud-vue` sits
						// on the Vue 2 (beta.*) line, so pointing the resolver there
						// validates imports against the wrong major.
						['@conduction/nextcloud-vue', './node_modules/@conduction/nextcloud-vue/src'],
					],
					extensions: ['.js', '.ts', '.vue', '.json', '.css'],
				},
			},
		},

		rules: {
			'jsdoc/require-jsdoc': 'off',
			'jsdoc/check-tag-names': ['warn', { definedTags: ['spec'] }],
			'vue/first-attribute-linebreak': 'off',
			'vue/enforce-style-attribute': ['error', { allow: ['scoped'] }],
			'@typescript-eslint/no-explicit-any': 'off',
			'n/no-missing-import': 'off',
			'import/named': 'off', // disable named import checking — alias resolver can't parse transitive Vue SFC exports
			'import/namespace': 'off',
			'import/default': 'off',
			'import/no-named-as-default': 'off',
			'import/no-named-as-default-member': 'off',
		},
	},
	...conductionVue3Fixes,
])
