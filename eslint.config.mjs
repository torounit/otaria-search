/**
 * ESLint flat config for Otaria Search.
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
		name: 'otaria-search/custom-rules',

		rules: {
			'@wordpress/i18n-text-domain': [
				'error',
				{
					allowedTextDomain: 'otaria-search',
				},
			],
		},
	},

	{
		name: 'otaria-search/typescript-overrides',
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
