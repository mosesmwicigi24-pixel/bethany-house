export default function Newsletter() {
  return (
    <section className="mx-auto max-w-6xl px-4 pb-20 sm:px-6">
      <div className="relative overflow-hidden rounded-3xl bg-surface-900 px-6 py-14 sm:px-12">
        <div
          aria-hidden
          className="pointer-events-none absolute -right-20 -top-20 h-72 w-72 rounded-full bg-brand-500/20 blur-3xl"
        />
        <div className="relative mx-auto max-w-xl text-center">
          <h2 className="font-display text-3xl font-bold tracking-tight text-white">
            Get 10% off your first order
          </h2>
          <p className="mt-3 text-surface-300">
            Join our list for new arrivals, outlet events and members-only
            offers. No spam — just the good stuff.
          </p>
          <form className="mt-8 flex flex-col gap-3 sm:flex-row">
            <label htmlFor="newsletter-email" className="sr-only">
              Email address
            </label>
            <input
              id="newsletter-email"
              type="email"
              required
              placeholder="you@example.com"
              className="w-full rounded-xl border border-surface-700 bg-surface-800 px-4 py-3 text-white placeholder:text-surface-500 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/40"
            />
            <button
              type="submit"
              className="shrink-0 rounded-xl bg-brand-500 px-6 py-3 font-medium text-white transition-colors hover:bg-brand-600"
            >
              Subscribe
            </button>
          </form>
          <p className="mt-3 text-xs text-surface-500">
            By subscribing you agree to our Privacy Policy.
          </p>
        </div>
      </div>
    </section>
  );
}
