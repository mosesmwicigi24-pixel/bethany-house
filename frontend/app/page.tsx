import SiteHeader from "@/components/SiteHeader";
import Hero from "@/components/Hero";
import CategoryGrid from "@/components/CategoryGrid";
import FeaturedProducts from "@/components/FeaturedProducts";
import ValueProps from "@/components/ValueProps";
import Newsletter from "@/components/Newsletter";
import SiteFooter from "@/components/SiteFooter";

export default function Home() {
  return (
    <div className="min-h-screen bg-white">
      <SiteHeader />
      <main>
        <Hero />
        <CategoryGrid />
        <FeaturedProducts />
        <ValueProps />
        <Newsletter />
      </main>
      <SiteFooter />
    </div>
  );
}
