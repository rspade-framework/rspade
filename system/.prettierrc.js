module.exports = {
  plugins: ['@prettier/plugin-php'],
  printWidth: 140,
  tabWidth: 4,
  useTabs: false,
  singleQuote: true,
  trailingComma: 'es5',
  bracketSpacing: true,
  phpVersion: '8.0',
  overrides: [
    {
      files: '*.php',
      options: {
        parser: 'php',
        singleQuote: true,
        printWidth: 140,
        tabWidth: 4,
      },
    },
  ],
};