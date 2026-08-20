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
          500: '#f05423',   // primary — CTA fill. EXACT value measured off
                            // mukuru.com (was #f0562a, an eyeballed approximation).
          600: '#d8431a',
          700: '#b23514',   // 6.15:1 on white — safe for text and icons
          800: '#8f2c13',
          900: '#742714',
          950: '#3f1106',
        },
        // UI neutrals — WARM green-grey, hue 105.
        // The reference's neutrals are warm (its body text and footer are
        // #373a36, hsl(105, 3.6%, 22%)); ours were cool blue-slate (#121926,
        // hsl(217, 35%, 11%)). That hue mismatch — not the orange, which was
        // already right — is what made the hub read as a generic admin panel
        // rather than the reference.
        // Every step keeps the LIGHTNESS of the cool ramp it replaces, so the
        // contrast ratios already verified against this palette still hold;
        // only hue and saturation moved. Saturation rises slightly at the light
        // end so pale fills read warm instead of dead grey.
        surface: {
          // Page background only — never a card fill.
          canvas: '#f2f3f1',
          0:   '#ffffff',
          50:  '#f9faf9',
          100: '#f2f3f2',
          200: '#e7eae6',
          300: '#d2d6d1',
          // Darkened from the direct re-hue (#a3a9a0, 2.40:1). This token is
          // used ~1178x, much of it on text, and 2.40:1 fails AA outright.
          // 3.13:1 clears the 3:1 bar for UI and large text.
          400: '#8c9489',
          // 4.57:1 on white. This is the secondary-text token and it must clear
          // AA: at #757d72 it measured 4.26:1, and the palette sweep moved ~31
          // body-text sites here from stock gray-500 (4.83:1), so leaving it
          // would have been a real regression rather than a neutral rename.
          500: '#70786d',
          600: '#565c54',
          700: '#434741',
          800: '#2c2e2b',
          900: '#1b1d1b',
          950: '#121311',
        },
        // Exact values measured off mukuru.com — not approximations. Kept as
        // their own names (rather than forced into the ramp) because #373a36
        // sits at L22%, between our 700 and 800; jamming it in as 900 would
        // have made 900 lighter than 800 and broken the ramp's monotonicity.
        ink:  '#373a36',   // reference body text AND footer fill — 11.53:1 on white
        tint: '#f48364',   // the softer orange used for CTAs sitting ON an orange band

        // Sidebar chrome, taken from the Neema admin portal (owner's request,
        // 2026-08-01). Exact values lifted from that codebase's Sidebar.tsx
        // rather than eyeballed from a screenshot.
        // A deliberately COOL slab against warm content: the navigation is
        // chrome, not content, and the hue break is what separates them — the
        // same job the reference gives its charcoal footer.
        // NOTE the active pill stays on OUR brand orange rather than Neema's
        // gold. Neema's accent is #f59e0b, which is exactly this system's
        // `amber` token, and amber already means "in progress" in the work-state
        // traffic lights; reusing it for navigation would collide with that.
        nav: {
          DEFAULT: '#0e1729',   // slab
          border:  '#1e2a44',   // dividers
          hover:   '#1b2740',   // hover fill
        },
        // NOTE: the reference's card/chip grey is #e9e9ea, but surface-200
        // (#e7eae6) is already that colour to within two units — both compute
        // to 1.21:1 on white. A separate `mist` token was minted here and then
        // removed: two names for one colour is how a palette forks, with half
        // the app landing on each. Use surface-200 for the grey chip.
        // A hairline that is visible but never heavy, and the pale fill used
        // inside select/inputs. Re-hued warm to match the ramp above.
        line:   '#e5e7e3',
        field:  '#fafbfa',

        // Semantic
        // Work-state semantics (owner's rule): in flight = amber, finished = a
        // vivid traffic-light green. `amber` is its own token, NOT the brand —
        // the brand is now orange (#f0562a) and an in-progress chip must not be
        // mistaken for a brand accent. `success.vivid` is the luminous green
        // used for status dots, where saturation reads without a contrast cost.
        // FULL RAMPS, not just light/DEFAULT/dark.
        //
        // These carried three steps each while the UI needed ten, which is
        // precisely why 1,510 raw Tailwind utilities (slate-, red-, emerald-,
        // purple- …) accumulated across 53 files: there was no token to reach
        // for, so people reached past the design system. Those usages then sat
        // outside it — the warm retune moved everything else and left them cool.
        //
        // The named keys below are UNCHANGED and every one of them is an exact
        // Tailwind rung, so adding the numeric siblings is a zero-visual-change
        // edit that simply brings the scale under our control. Retuning a hue
        // now happens here instead of in 53 files.
        //
        // A useful side effect: `amber-500` and friends previously fell through
        // to stock Tailwind because our `amber` object had no numeric keys.
        // They now resolve to these, so 254 amber usages become tokenised
        // without a single edit.
        amber: {
          light: '#fef3c7', DEFAULT: '#f59e0b', dark: '#92400e',
          50: '#fffbeb', 100: '#fef3c7', 200: '#fde68a', 300: '#fcd34d', 400: '#fbbf24',
          500: '#f59e0b', 600: '#d97706', 700: '#b45309', 800: '#92400e', 900: '#78350f',
        },
        // The middle stage of an order's life. Amber = confirmed and waiting,
        // pink = being worked, green = done. Pink is deliberately NOT a
        // warning or a danger hue: a vestment on the workshop floor is good
        // news, and the owner asked for a luminous pink to read that way at a
        // glance down a list of amber and green rows.
        pink: {
          light: '#fce7f3', DEFAULT: '#db2777', dark: '#9d174d', vivid: '#ec4899',
          50: '#fdf2f8', 100: '#fce7f3', 200: '#fbcfe8', 300: '#f9a8d4', 400: '#f472b6',
          500: '#ec4899', 600: '#db2777', 700: '#be185d', 800: '#9d174d', 900: '#831843',
        },
        success: {
          light: '#dcfce7', DEFAULT: '#16a34a', dark: '#15803d', vivid: '#22c55e',
          50: '#f0fdf4', 100: '#dcfce7', 200: '#bbf7d0', 300: '#86efac', 400: '#4ade80',
          500: '#22c55e', 600: '#16a34a', 700: '#15803d', 800: '#166534', 900: '#14532d',
        },
        warning: {
          light: '#fef9c3', DEFAULT: '#ca8a04', dark: '#713f12',
          50: '#fefce8', 100: '#fef9c3', 200: '#fef08a', 300: '#fde047', 400: '#facc15',
          500: '#eab308', 600: '#ca8a04', 700: '#a16207', 800: '#854d0e', 900: '#713f12',
        },
        danger: {
          light: '#fee2e2', DEFAULT: '#dc2626', dark: '#7f1d1d',
          50: '#fef2f2', 100: '#fee2e2', 200: '#fecaca', 300: '#fca5a5', 400: '#f87171',
          500: '#ef4444', 600: '#dc2626', 700: '#b91c1c', 800: '#991b1b', 900: '#7f1d1d',
        },
        info: {
          light: '#dbeafe', DEFAULT: '#2563eb', dark: '#1e3a8a',
          50: '#eff6ff', 100: '#dbeafe', 200: '#bfdbfe', 300: '#93c5fd', 400: '#60a5fa',
          500: '#3b82f6', 600: '#2563eb', 700: '#1d4ed8', 800: '#1e40af', 900: '#1e3a8a',
        },
        // The fourth state family. Shipment progress uses four distinct stages
        // (ready to ship / picked up / in transit / delivered) and collapsing
        // them onto one accent would erase the distinction the floor reads them
        // by — so this is consolidation, not elimination: purple, violet,
        // fuchsia and pink all resolve here.
        accent: {
          light: '#f3e8ff', DEFAULT: '#9333ea', dark: '#581c87',
          50: '#faf5ff', 100: '#f3e8ff', 200: '#e9d5ff', 300: '#d8b4fe', 400: '#c084fc',
          500: '#a855f7', 600: '#9333ea', 700: '#7e22ce', 800: '#6b21a8', 900: '#581c87',
        },
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
        // Desktop display sizes, matching the reference's measured heading ramp
        // (h1 40/lh44, h2 30/lh33). Our old top end was 28px, which is why big
        // screens read as a scaled-up phone rather than a designed desktop.
        // NOTE: the reference sets h1 at weight 900. Plus Jakarta Sans tops out
        // at 800, so `font-extrabold` is as heavy as we can actually render —
        // asking for font-black would silently fall back to 800 anyway.
        '4xl':  ['2.5rem',    { lineHeight: '2.75rem',  letterSpacing: '-0.02em' }],
        // body stays 15px/24px (1.6) — the reference is 15px/24.9px (1.66),
        // already a match, so the body ramp needed no change.
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
