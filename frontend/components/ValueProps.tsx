const PROPS = [
  {
    title: "Nationwide delivery",
    body: "Fast, tracked shipping to every county — most orders arrive within 48 hours.",
    icon: (
      <>
        <path d="M1 3h15v13H1z" />
        <path d="M16 8h4l3 3v5h-7V8Z" />
        <circle cx="5.5" cy="18.5" r="2.5" />
        <circle cx="18.5" cy="18.5" r="2.5" />
      </>
    ),
  },
  {
    title: "Pay your way",
    body: "M-Pesa, card and more — checkout is secure and takes under a minute.",
    icon: (
      <>
        <rect x="2" y="5" width="20" height="14" rx="2" />
        <path d="M2 10h20" />
      </>
    ),
  },
  {
    title: "Made with care",
    body: "Locally sourced and crafted pieces, quality-checked before they reach you.",
    icon: (
      <>
        <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 1 0-7.78 7.78L12 21.23l8.84-8.84a5.5 5.5 0 0 0 0-7.78Z" />
      </>
    ),
  },
  {
    title: "Shop in-store too",
    body: "Six outlets across the country with the same stock, prices and service.",
    icon: (
      <>
        <path d="M3 9 4 4h16l1 5" />
        <path d="M4 9v11h16V9" />
        <path d="M9 20v-6h6v6" />
      </>
    ),
  },
];

export default function ValueProps() {
  return (
    <section className="mx-auto max-w-6xl px-4 py-20 sm:px-6">
      <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
        {PROPS.map((prop) => (
          <div
            key={prop.title}
            className="rounded-2xl border border-surface-200 bg-white p-6 transition-shadow duration-200 hover:shadow-card"
          >
            <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50 text-brand-600">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                {prop.icon}
              </svg>
            </div>
            <h3 className="mt-4 font-display text-lg font-semibold text-surface-900">
              {prop.title}
            </h3>
            <p className="mt-1.5 text-sm leading-relaxed text-surface-600">
              {prop.body}
            </p>
          </div>
        ))}
      </div>
    </section>
  );
}
