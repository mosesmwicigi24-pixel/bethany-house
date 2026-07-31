/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,ts,jsx,tsx}'],
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        // Brand palette - warm slate with amber accent
        // Reference orange (owner-chosen 2026-07-31, replacing the amber ramp).
        // 500 is the brand hue used on primary CTAs with WHITE text — an exact
        // match to the reference app, which accepts ~3.4:1 on the button label.
        // 700+ exist for text/icon use ON white, where AA actually applies:
        // 700 = 4.9:1, so links, active tabs and small orange text use 700, not 500.
        brand: {
          50:  '#fff4ef',
          100: '#ffe4d8',
          200: '#ffc7b0',
          300: '#ffa17d',
          400: '#fb7a4c',
          500: '#f0562a',   // primary — CTA fill
          600: '#d8431a',
          700: '#b23514',   // 4.9:1 on white — safe for text and icons
          800: '#8f2c13',
          900: '#742714',
          950: '#3f1106',
        },
        // UI neutrals - cool slate base
        surface: {
          // Page background only — never a card fill. surface-50 is ~1.6% off
          // white, too close for a white card to read as floating; this is ~4%,
          // which separates softly without looking grey. Kept separate because
          // surface-50 is also the table row-hover tint.
          canvas: '#f1f2f3',
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
        // Sampled from the reference screens: a flat grey canvas the white
        // cards sit on, a hairline that is visible but never heavy, and the
        // pale fill used inside select/inputs.
        line:   '#e6e7e9',
        field:  '#fafafa',

        // Semantic
        success: { light: '#dcfce7', DEFAULT: '#16a34a', dark: '#14532d' },
        warning: { light: '#fef9c3', DEFAULT: '#ca8a04', dark: '#713f12' },
        danger:  { light: '#fee2e2', DEFAULT: '#dc2626', dark: '#7f1d1d' },
        info:    { light: '#dbeafe', DEFAULT: '#2563eb', dark: '#1e3a8a' },
      },
      fontFamily: {
        // One typeface across the hub AND the storefront. `display` stays as a
        // named role (headings) so existing font-display usages keep working —
        // it just resolves to the same family at a heavier weight now.
        sans:    ['Plus Jakarta Sans', 'system-ui', 'sans-serif'],
        display: ['Plus Jakarta Sans', 'system-ui', 'sans-serif'],
        mono:    ['JetBrains Mono', 'monospace'],
      },
      // Type ramp: bigger body, tighter headings. 2xs stays defined (1000+
      // refs) but rises 10px → 11px — 10px is below the legible floor on a
      // phone, and 11px is metadata-only: anything read as a value is >= 13px.
      fontSize: {
        '2xs':  ['0.6875rem', { lineHeight: '1rem', letterSpacing: '0.01em' }],
        'sm':   ['0.875rem',  { lineHeight: '1.375rem' }],
        'base': ['0.9375rem', { lineHeight: '1.5rem' }],
        'lg':   ['1.0625rem', { lineHeight: '1.5rem',   letterSpacing: '-0.01em' }],
        'xl':   ['1.1875rem', { lineHeight: '1.625rem', letterSpacing: '-0.015em' }],
        '2xl':  ['1.5rem',    { lineHeight: '1.875rem', letterSpacing: '-0.02em' }],
        '3xl':  ['1.75rem',   { lineHeight: '2.125rem', letterSpacing: '-0.025em' }],
      },
      // 8–12px is dense-admin geometry; the reference is 20–24px with pill
      // buttons. Named by role so intent survives future edits.
      borderRadius: {
        'field':   '0.75rem',  // 12px — inputs and selects (reference geometry)
        'control': '0.875rem', // 14px — icon buttons, chips
        'card':    '1.5rem',   // 24px — standard card (reference)
        'card-lg': '1.5rem',   // 24px — hero / summary card
        'sheet':   '1.75rem',  // 28px — modals and bottom sheets
        '4xl': '2rem',
      },
      // Soft lift instead of a hairline edge, tinted with the surface-900 slate
      // rather than pure black (black dirties a cool-neutral palette).
      boxShadow: {
        'card':    '0 1px 2px 0 rgb(16 24 40 / 0.04), 0 8px 24px -12px rgb(16 24 40 / 0.10)',
        'card-md': '0 2px 4px -1px rgb(16 24 40 / 0.04), 0 12px 32px -12px rgb(16 24 40 / 0.12)',
        'card-lg': '0 4px 8px -2px rgb(16 24 40 / 0.05), 0 24px 48px -16px rgb(16 24 40 / 0.14)',
        'pop':     '0 12px 32px -8px rgb(16 24 40 / 0.18)',
        'tabbar':  '0 -1px 0 0 rgb(16 24 40 / 0.06)',
        'btn-brand':'0 6px 16px -6px rgb(240 86 42 / 0.40)',
        'inner-sm':'inset 0 1px 2px 0 rgb(16 24 40 / 0.05)',
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
