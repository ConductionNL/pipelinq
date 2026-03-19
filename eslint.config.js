const {
	defineConfig,
} = require('@eslint/config-helpers')

const js = require('@eslint/js')

const {
	FlatCompat,
} = require('@eslint/eslintrc')

const compat = new FlatCompat({
	baseDirectory: __dirname,
	recommendedConfig: js.configs.recommended,
	allConfig: js.configs.all,
})

module.exports = defineConfig([{
	extends: compat.extends('@nextcloud'),

	settings: {
		'import/resolver': {
			alias: {
				map: [
					['@', './src'],
					['@conduction/nextcloud-vue', '../nextcloud-vue/src'],
				],
				extensions: ['.js', '.ts', '.vue', '.json', '.css'],
			},
		},
	},

	rules: {
		'jsdoc/require-jsdoc': 'off',
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
}])
