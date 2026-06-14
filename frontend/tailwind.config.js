/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,ts,jsx,tsx}'],
  theme: {
    extend: {
      colors: {
        accent: '#22c55e',
        'accent-2': '#86efac',
      },
      fontFamily: {
        display: ['"Archivo Black"', 'Inter', 'system-ui', 'sans-serif'],
      },
    },
  },
  plugins: [],
};
