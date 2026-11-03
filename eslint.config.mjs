/**
 * ESLint flat config for Squirrel Search.
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
		name: 'squirrel-search/custom-rules',

		rules: {
			'@wordpress/i18n-text-domain': [
				'error',
				{
					allowedTextDomain: 'squirrel-search',
				},
			],
		},
	},

	{
		name: 'squirrel-search/typescript-overrides',
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
