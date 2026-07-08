const CATEGORIES = [
  { name: "Homeware", count: 340, gradient: "from-brand-400 to-brand-600" },
  { name: "Fabric & Textiles", count: 210, gradient: "from-surface-600 to-surface-800" },
  { name: "Kitchen & Dining", count: 185, gradient: "from-brand-300 to-brand-500" },
  { name: "Décor & Accents", count: 260, gradient: "from-surface-400 to-surface-600" },
];

export default function CategoryGrid() {
  return (
    <section id="categories" className="mx-auto max-w-6xl px-4 py-20 sm:px-6">
      <div className="mb-10 flex flex-wrap items-end justify-between gap-4">
        <div>
          <p className="text-sm font-semibold uppercase tracking-wide text-brand-600">
            Shop by category
          </p>
          <h2 className="mt-1 font-display text-3xl font-bold tracking-tight text-surface-900">
            Find your corner
          </h2>
        </div>
        <a
          href="#featured"
          className="text-sm font-medium text-surface-600 transition-colors hover:text-brand-600"
        >
          View all products →
        </a>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {CATEGORIES.map((cat) => (
          <a
            key={cat.name}
            href="#featured"
            className={`group relative flex aspect-[4/5] flex-col justify-end overflow-hidden rounded-2xl bg-gradient-to-br ${cat.gradient} p-5 shadow-card transition-transform duration-200 hover:-translate-y-1 hover:shadow-card-lg`}
          >
            <div
              aria-hidden
              className="absolute inset-0 bg-black/0 transition-colors duration-200 group-hover:bg-black/10"
            />
            <div className="relative">
              <h3 className="font-display text-xl font-semibold text-white">
                {cat.name}
              </h3>
              <p className="text-sm text-white/80">{cat.count} products</p>
            </div>
          </a>
        ))}
      </div>
    </section>
  );
}
