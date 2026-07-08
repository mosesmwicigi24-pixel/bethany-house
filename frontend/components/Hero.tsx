export default function Hero() {
  return (
    <section id="top" className="relative overflow-hidden bg-surface-50">
      {/* Soft brand glow */}
      <div
        aria-hidden
        className="pointer-events-none absolute -right-32 -top-32 h-96 w-96 rounded-full bg-brand-200/50 blur-3xl"
      />
      <div
        aria-hidden
        className="pointer-events-none absolute -bottom-40 -left-24 h-96 w-96 rounded-full bg-brand-100/60 blur-3xl"
      />

      <div className="relative mx-auto grid max-w-6xl items-center gap-12 px-4 py-20 sm:px-6 lg:grid-cols-2 lg:py-28">
        <div className="flex flex-col items-start gap-6">
          <span className="inline-flex items-center gap-2 rounded-full border border-brand-200 bg-brand-50 px-3 py-1 text-xs font-medium text-brand-700">
            <span className="h-1.5 w-1.5 rounded-full bg-brand-500" />
            Crafted in Kenya · Delivered nationwide
          </span>

          <h1 className="font-display text-4xl font-bold leading-[1.1] tracking-tight text-surface-900 sm:text-5xl lg:text-6xl">
            Beautiful things for{" "}
            <span className="text-brand-500">everyday living</span>.
          </h1>

          <p className="max-w-md text-lg leading-relaxed text-surface-600">
            Thoughtfully curated homeware, fabric and lifestyle pieces — made
            with care and priced for real homes. Shop online or drop by one of
            our outlets.
          </p>

          <div className="flex flex-wrap items-center gap-3 pt-2">
            <a
              href="#featured"
              className="inline-flex items-center gap-2 rounded-xl bg-brand-500 px-6 py-3 text-base font-medium text-white shadow-card transition-colors hover:bg-brand-600"
            >
              Explore the collection
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <path d="M5 12h14M12 5l7 7-7 7" />
              </svg>
            </a>
            <a
              href="#categories"
              className="inline-flex items-center rounded-xl border border-surface-300 bg-white px-6 py-3 text-base font-medium text-surface-700 transition-colors hover:border-surface-400 hover:bg-surface-50"
            >
              Browse categories
            </a>
          </div>

          <dl className="mt-6 flex gap-8 border-t border-surface-200 pt-6">
            {[
              { value: "1,200+", label: "Products" },
              { value: "6", label: "Outlets" },
              { value: "48hr", label: "Delivery" },
            ].map((stat) => (
              <div key={stat.label}>
                <dt className="font-display text-2xl font-bold text-surface-900">
                  {stat.value}
                </dt>
                <dd className="text-sm text-surface-500">{stat.label}</dd>
              </div>
            ))}
          </dl>
        </div>

        {/* Visual collage — pure CSS, no external images required */}
        <div className="relative hidden lg:block">
          <div className="grid grid-cols-2 gap-4">
            <div className="flex aspect-[3/4] items-end rounded-3xl bg-gradient-to-br from-brand-300 to-brand-500 p-5 shadow-card-lg">
              <span className="font-display text-lg font-semibold text-white">
                Living
              </span>
            </div>
            <div className="mt-10 flex aspect-[3/4] items-end rounded-3xl bg-gradient-to-br from-surface-700 to-surface-900 p-5 shadow-card-lg">
              <span className="font-display text-lg font-semibold text-white">
                Fabric
              </span>
            </div>
            <div className="-mt-6 flex aspect-[3/4] items-end rounded-3xl bg-gradient-to-br from-brand-100 to-brand-300 p-5 shadow-card-lg">
              <span className="font-display text-lg font-semibold text-brand-900">
                Kitchen
              </span>
            </div>
            <div className="flex aspect-[3/4] items-end rounded-3xl bg-gradient-to-br from-surface-200 to-surface-400 p-5 shadow-card-lg">
              <span className="font-display text-lg font-semibold text-surface-800">
                Décor
              </span>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
