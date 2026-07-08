const FOOTER_COLUMNS = [
  {
    title: "Shop",
    links: ["Homeware", "Fabric & Textiles", "Kitchen & Dining", "Décor", "New In"],
  },
  {
    title: "Help",
    links: ["Track your order", "Delivery & returns", "Payment options", "Contact us", "FAQs"],
  },
  {
    title: "Company",
    links: ["Our story", "Outlets", "Careers", "Wholesale", "Sustainability"],
  },
];

export default function SiteFooter() {
  return (
    <footer id="footer" className="border-t border-surface-200 bg-white">
      <div className="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div className="grid gap-10 lg:grid-cols-[1.4fr_1fr_1fr_1fr]">
          <div>
            <div className="flex items-center gap-2.5">
              <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-500 font-display text-lg font-bold text-white">
                B
              </span>
              <span className="font-display text-lg font-semibold tracking-tight text-surface-900">
                Bethany House
              </span>
            </div>
            <p className="mt-4 max-w-xs text-sm leading-relaxed text-surface-600">
              Curated homeware, fabric and lifestyle pieces — crafted in Kenya,
              made for everyday living.
            </p>
            <div className="mt-5 flex gap-2">
              {["EN", "FR", "PT"].map((lang) => (
                <button
                  key={lang}
                  className="rounded-lg border border-surface-200 px-2.5 py-1 text-xs font-medium text-surface-600 transition-colors hover:border-brand-300 hover:text-brand-600"
                >
                  {lang}
                </button>
              ))}
            </div>
          </div>

          {FOOTER_COLUMNS.map((col) => (
            <div key={col.title}>
              <h3 className="text-sm font-semibold text-surface-900">
                {col.title}
              </h3>
              <ul className="mt-4 space-y-2.5">
                {col.links.map((link) => (
                  <li key={link}>
                    <a
                      href="#top"
                      className="text-sm text-surface-600 transition-colors hover:text-brand-600"
                    >
                      {link}
                    </a>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>

        <div className="mt-12 flex flex-col items-center justify-between gap-4 border-t border-surface-200 pt-6 sm:flex-row">
          <p className="text-sm text-surface-500">
            © {new Date().getFullYear()} Bethany House · Nairobi, Kenya 🇰🇪
          </p>
          <div className="flex gap-5 text-sm text-surface-500">
            <a href="#top" className="transition-colors hover:text-surface-900">
              Terms
            </a>
            <a href="#top" className="transition-colors hover:text-surface-900">
              Privacy
            </a>
            <a href="#top" className="transition-colors hover:text-surface-900">
              Cookies
            </a>
          </div>
        </div>
      </div>
    </footer>
  );
}
