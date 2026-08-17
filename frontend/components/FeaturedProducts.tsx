type Product = {
  name: string;
  category: string;
  price: number;
  wasPrice?: number;
  badge?: string;
  swatch: string;
};

const PRODUCTS: Product[] = [
  {
    name: "Handwoven Throw Blanket",
    category: "Homeware",
    price: 3200,
    wasPrice: 4000,
    badge: "Sale",
    swatch: "from-brand-200 to-brand-400",
  },
  {
    name: "Kitenge Table Runner",
    category: "Fabric",
    price: 1450,
    badge: "New",
    swatch: "from-surface-500 to-surface-700",
  },
  {
    name: "Stoneware Dinner Set",
    category: "Kitchen & Dining",
    price: 6800,
    swatch: "from-brand-300 to-brand-500",
  },
  {
    name: "Woven Storage Basket",
    category: "Décor",
    price: 2100,
    badge: "Bestseller",
    swatch: "from-surface-300 to-surface-500",
  },
];

const KES = new Intl.NumberFormat("en-KE", {
  style: "currency",
  currency: "KES",
  maximumFractionDigits: 0,
});

function ProductCard({ product }: { product: Product }) {
  return (
    <article className="group flex flex-col overflow-hidden rounded-2xl border border-surface-200 bg-white transition-shadow duration-200 hover:shadow-card-lg">
      <div className="relative">
        <div
          className={`flex aspect-square items-center justify-center bg-gradient-to-br ${product.swatch}`}
        >
          <span className="font-display text-4xl font-bold text-white/40">
            BH
          </span>
        </div>
        {product.badge && (
          <span className="absolute left-3 top-3 rounded-full bg-white/95 px-2.5 py-1 text-xs font-semibold text-brand-700 shadow-card">
            {product.badge}
          </span>
        )}
        <button
          aria-label={`Add ${product.name} to cart`}
          className="absolute bottom-3 right-3 flex h-10 w-10 translate-y-2 items-center justify-center rounded-full bg-surface-900 text-white opacity-0 shadow-card-lg transition-all duration-200 hover:bg-brand-500 group-hover:translate-y-0 group-hover:opacity-100"
        >
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
            <path d="M12 5v14M5 12h14" />
          </svg>
        </button>
      </div>
      <div className="flex flex-1 flex-col gap-1 p-4">
        <p className="text-xs font-medium uppercase tracking-wide text-surface-400">
          {product.category}
        </p>
        <h3 className="font-medium text-surface-900">{product.name}</h3>
        <div className="mt-auto flex items-baseline gap-2 pt-2">
          <span className="font-display text-lg font-semibold text-surface-900">
            {KES.format(product.price)}
          </span>
          {product.wasPrice && (
            <span className="text-sm text-surface-400 line-through">
              {KES.format(product.wasPrice)}
            </span>
          )}
        </div>
      </div>
    </article>
  );
}

export default function FeaturedProducts() {
  return (
    <section id="featured" className="bg-surface-50">
      <div className="mx-auto max-w-6xl px-4 py-20 sm:px-6">
        <div className="mb-10 flex flex-wrap items-end justify-between gap-4">
          <div>
            <p className="text-sm font-semibold uppercase tracking-wide text-brand-600">
              Handpicked
            </p>
            <h2 className="mt-1 font-display text-3xl font-bold tracking-tight text-surface-900">
              Featured this week
            </h2>
          </div>
          <a
            href="#top"
            className="text-sm font-medium text-surface-600 transition-colors hover:text-brand-600"
          >
            See everything →
          </a>
        </div>

        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
          {PRODUCTS.map((product) => (
            <ProductCard key={product.name} product={product} />
          ))}
        </div>
      </div>
    </section>
  );
}
