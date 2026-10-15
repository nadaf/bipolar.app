import js from '@eslint/js';
import globals from 'globals';
export default [
  js.configs.recommended,
  {
    files: ['**/*.js'],
    languageOptions: { ecmaVersion: 2022, sourceType: 'script', globals: { ...globals.browser, ...globals.serviceworker } },
    rules: {
      'no-unused-vars': ['error', { args: 'none' }],
      'no-implied-eval': 'error', 'no-eval': 'error', 'no-new-func': 'error',
      'no-restricted-properties': ['error', { object: 'document', property: 'write' }],
      eqeqeq: ['error', 'smart'],
    },
  },
];
