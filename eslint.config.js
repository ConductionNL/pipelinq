const fs = require('fs')
const path = require('path')

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

// Mirror webpack.config.js: alias the local monorepo source when present.
const localLibPath = path.resolve(__dirname, '../nextcloud-vue/src')
const localLibExists = fs.existsSync(localLibPath)

const aliasMap = [['@', './src']]
if (localLibExists) {
	aliasMap.push(['@conduction/nextcloud-vue', '../nextcloud-vue/src'])
}

module.exports = defineConfig([{
	extends: compat.extends('@nextcloud'),

	settings: {
		'import/resolver': {
			alias: {
				map: aliasMap,
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
		// @conduction/* packages are resolved via webpack alias (local monorepo
		// or npm fallback) — the ESLint alias resolver cannot follow them when
		// the local path is absent, so suppress the unresolved error here.
		'import/no-unresolved': ['error', { ignore: ['^@conduction/'] }],
	},
}])
