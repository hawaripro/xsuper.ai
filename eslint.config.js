import globals from 'globals';
import react from 'eslint-plugin-react';

export default [
    { ignores: ['node_modules/**', 'public/**', 'vendor/**', '.impeccable/**', '.tmp*/**'] },
    {
        files: ['resources/js/**/*.{js,jsx}'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            parserOptions: { ecmaFeatures: { jsx: true } },
            globals: globals.browser,
        },
        plugins: { react },
        rules: {
            'no-undef': 'error',
            'react/jsx-no-undef': 'error',
        },
    },
];
