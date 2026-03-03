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
				map: [['@', './src']],
				extensions: ['.js', '.ts', '.vue', '.json'],
			},
		},
		// @conduction/nextcloud-vue is resolved at build time via webpack alias to
		// local source; skip all import validation for this package in ESLint.
		'import/ignore': ['@conduction/nextcloud-vue'],
	},

	rules: {
		'jsdoc/require-jsdoc': 'off',
		'vue/first-attribute-linebreak': 'off',
		'@typescript-eslint/no-explicit-any': 'off',
		'n/no-missing-import': 'off',
		'import/namespace': 'off',
		'import/default': 'off',
		'import/no-named-as-default': 'off',
		'import/no-named-as-default-member': 'off',
		// @conduction/nextcloud-vue is resolved via webpack alias at build time;
		// ESLint resolves against the published npm package which may lag behind.
		'import/no-unresolved': ['error', { ignore: ['^@conduction/nextcloud-vue'] }],
	},
}])
