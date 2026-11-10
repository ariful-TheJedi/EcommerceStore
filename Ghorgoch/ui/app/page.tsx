import { defaultFrontPage } from "@/config/front-page";
import { getFrontPage } from "@/services/front-page";

import HomeProductCollection from "@/components/sections/HomeProductCollection";
import Faq from "@/components/sections/Faq";
import HomeBenefits from "@/components/sections/HomeBenefits";
import HomeCategories from "@/components/sections/HomeCategories";
import HomeHero from "@/components/sections/HomeHero";
import HomePromotions from "@/components/sections/HomePromotions";
import { getCategories } from "@/services/categories";
import { getProducts } from "@/services/products";
import { productImage } from "@/components/catalog";


export const dynamic = "force-dynamic";

export default async function Home() {
  const saved = await getFrontPage();
  const content = saved.settings.content || defaultFrontPage();
  const configured = saved.settings.content !== null;
  const [categoryResult, featuredResult, newestResult] = await Promise.allSettled([
    configured ? Promise.resolve(saved.categories) : getCategories(),
    configured ? Promise.resolve({ products: saved.featured }) : getProducts({ limit: 4, sort: "id" }),
    configured ? Promise.resolve({ products: saved.new_arrivals }) : getProducts({ limit: 4, sort: "-created_at" }),
  ]);
  const categories = categoryResult.status === "fulfilled" ? categoryResult.value : [];
  const featured = featuredResult.status === "fulfilled" ? featuredResult.value.products : [];
  const newest = newestResult.status === "fulfilled" ? newestResult.value.products : [];
  const categoryImages: Record<string, string> = {};
  for (const product of [...featured, ...newest]) {
    const image = productImage(product);
    if (image) { for (const category of product.categories) { categoryImages[category.slug] ??= image; } }
  }

  return <main id="main-content" tabIndex={-1} className="reference-home" data-hero-hidden={!content.hero.visible || !content.hero.slides.length || undefined} lang="bn">
    {(!content.hero.visible || !content.hero.slides.length) && <h1 className="sr-only">{content.hero.heading}</h1>}
    {content.hero.visible && <HomeHero slides={content.hero.slides} heading={content.hero.heading} />}
    <div className="page-container">
      {content.categories.visible && <HomeCategories title={content.categories.title} subtitle={content.categories.subtitle} categories={categories} images={categoryImages} unavailable={categoryResult.status === "rejected"} />}
      {content.featured.visible && <HomeProductCollection sectionId="collection" title={content.featured.title} products={featured} unavailable={featuredResult.status === "rejected"} />}
      {content.promotions.visible && <HomePromotions cards={content.promotions.cards} />}
      {content.new_arrivals.visible && <HomeProductCollection sectionId="new-arrivals" title={content.new_arrivals.title} products={newest} unavailable={newestResult.status === "rejected"} />}
      {content.benefits.visible && <HomeBenefits items={content.benefits.items} />}
      {content.faq.visible && <Faq title={content.faq.title} description={content.faq.description} items={content.faq.items} />}
    </div>
  </main>;
}
