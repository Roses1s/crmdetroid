// Конфиг ESLint (flat config). Проверяются браузерные скрипты js/*.js
// и e2e-тест tests/e2e-jsdom.mjs (Node). Запуск: npx eslint .
import js from '@eslint/js';
import globals from 'globals';

export default [
    js.configs.recommended,
    {
        files: ['js/**/*.js'],
        languageOptions: {
            ecmaVersion: 2021,
            sourceType: 'script', // обычные <script>, не модули
            globals: {
                ...globals.browser,
            },
        },
        rules: {
            // catch(e){} с пустым/неиспользуемым e — осознанный паттерн (localStorage может кидать).
            // Аргументы/переменные с префиксом _ — намеренно неиспользуемые (например _sync
            // в saveCarrierForm: оставлен для совместимости сигнатуры вызовов).
            'no-unused-vars': ['error', {
                caughtErrors: 'none',
                args: 'after-used',
                argsIgnorePattern: '^_',
                varsIgnorePattern: '^_',
            }],
            'no-empty': ['error', { allowEmptyCatch: true }],
            eqeqeq: ['error', 'smart'],
            'no-var': 'error',
            'prefer-const': 'error',
        },
    },
    {
        files: ['tests/**/*.mjs'],
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'module',
            globals: {
                ...globals.node,
            },
        },
        rules: {
            'no-unused-vars': ['error', { caughtErrors: 'none', args: 'after-used' }],
            'no-empty': ['error', { allowEmptyCatch: true }],
        },
    },
    {
        ignores: ['node_modules/**'],
    },
];
