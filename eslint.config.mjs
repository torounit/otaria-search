/**
 * ESLint flat config for WP MariaDB Vector Search.
 *
 * @see https://eslint.org/docs/latest/use/configure/configuration-files
 */

// @ts-ignore TS7016
import wordpress from '@wordpress/eslint-plugin';

export default [
	{
		ignores: [
			'**/build/**',
			'**/node_modules/**',
			'**/vendor/**',
			'eslint.config.mjs',
		],
	},

	...wordpress.configs.recommended,

	{
		name: 'wp-mariadb-vector-search/custom-rules',

		rules: {
			'@wordpress/i18n-text-domain': [
				'error',
				{
					allowedTextDomain: 'wp-mariadb-vector-search',
				},
			],
		},
	},

	{
		name: 'wp-mariadb-vector-search/typescript-overrides',
		files: [ '**/*.ts?(x)' ],

		rules: {
			'@typescript-eslint/consistent-type-imports': [
				'error',
				{
					prefer: 'type-imports',
					disallowTypeAnnotations: false,
				},
			],
			'jsdoc/require-param': 'off',
		},
	},
];
