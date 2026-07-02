/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,ts,jsx,tsx}'],
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        // Brand palette - warm slate with amber accent
        brand: {
          50:  '#fdf8f0',
          100: '#faefd9',
          200: '#f4dcb0',
          300: '#ecc47d',
          400: '#e3a44a',
          500: '#d98a2a',   // primary
          600: '#c07020',
          700: '#9f561c',
          800: '#82451d',
          900: '#6b3a1a',
          950: '#3c1c0a',
        },
        // UI neutrals - cool slate base
        surface: {
          0:   '#ffffff',
          50:  '#f8f9fb',
          100: '#f0f2f5',
          200: '#e4e7ec',
          300: '#cdd2da',
          400: '#9aa3b0',
          500: '#697586',
          600: '#4b5565',
          700: '#364152',
          800: '#202939',
          900: '#121926',
          950: '#0d1117',
        },
        // Semantic
        success: { light: '#dcfce7', DEFAULT: '#16a34a', dark: '#14532d' },
        warning: { light: '#fef9c3', DEFAULT: '#ca8a04', dark: '#713f12' },
        danger:  { light: '#fee2e2', DEFAULT: '#dc2626', dark: '#7f1d1d' },
        info:    { light: '#dbeafe', DEFAULT: '#2563eb', dark: '#1e3a8a' },
      },
      fontFamily: {
        sans:    ['DM Sans', 'system-ui', 'sans-serif'],
        display: ['Sora', 'system-ui', 'sans-serif'],
        mono:    ['JetBrains Mono', 'monospace'],
      },
      fontSize: {
        '2xs': ['0.625rem', { lineHeight: '0.875rem' }],
      },
      borderRadius: {
        '4xl': '2rem',
      },
      boxShadow: {
        'card':   '0 1px 3px 0 rgb(0 0 0 / 0.06), 0 1px 2px -1px rgb(0 0 0 / 0.06)',
        'card-md':'0 4px 6px -1px rgb(0 0 0 / 0.07), 0 2px 4px -2px rgb(0 0 0 / 0.07)',
        'card-lg':'0 10px 15px -3px rgb(0 0 0 / 0.07), 0 4px 6px -4px rgb(0 0 0 / 0.05)',
        'inner-sm':'inset 0 1px 2px 0 rgb(0 0 0 / 0.05)',
      },
      animation: {
        'fade-in':     'fadeIn 0.2s ease-out',
        'slide-up':    'slideUp 0.25s ease-out',
        'slide-down':  'slideDown 0.25s ease-out',
        'slide-in-right': 'slideInRight 0.3s ease-out',
        'pulse-soft':  'pulseSoft 2s cubic-bezier(0.4, 0, 0.6, 1) infinite',
      },
      keyframes: {
        fadeIn:       { from: { opacity: '0' }, to: { opacity: '1' } },
        slideUp:      { from: { opacity: '0', transform: 'translateY(8px)' }, to: { opacity: '1', transform: 'translateY(0)' } },
        slideDown:    { from: { opacity: '0', transform: 'translateY(-8px)' }, to: { opacity: '1', transform: 'translateY(0)' } },
        slideInRight: { from: { opacity: '0', transform: 'translateX(16px)' }, to: { opacity: '1', transform: 'translateX(0)' } },
        pulseSoft:    { '0%, 100%': { opacity: '1' }, '50%': { opacity: '0.5' } },
      },
      transitionTimingFunction: {
        'spring': 'cubic-bezier(0.34, 1.56, 0.64, 1)',
      },
    },
  },
  plugins: [],
}
