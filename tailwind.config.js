/** @type {import('tailwindcss').Config} */
module.exports = {
  darkMode: 'class',
  content: [
    './index.html',
    './src/**/*.{js,jsx}',
  ],
  theme: {
    extend: {
      colors: {
        academic: {
          50: '#effaf3',
          100: '#d9f2e2',
          200: '#b5e5c9',
          300: '#82d0a8',
          400: '#4db47f',
          500: '#2e965f',
          600: '#1f7a4f',
          700: '#1f6f5f',
          800: '#18584c',
          900: '#123d35',
        },
      },
      boxShadow: {
        soft: '0 18px 45px rgba(18, 61, 53, 0.10)',
        card: '0 12px 28px rgba(31, 54, 45, 0.08)',
        lift: '0 18px 34px rgba(31, 111, 95, 0.18)',
      },
      fontFamily: {
        sans: ['Poppins', 'Inter', 'ui-sans-serif', 'system-ui', 'Segoe UI', 'Arial', 'sans-serif'],
      },
    },
  },
  plugins: [],
};
