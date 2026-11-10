import pageData from "@/data/front-page.json";

export type FrontPageSlide = { id: string; image: string; image_path?: string | null; alt: string; eyebrow: string; title: string; accent: string; description: string; href: string; action: string };
export type FrontPagePromo = Omit<FrontPageSlide, "accent">;
export type FrontPageContent = {
  hero: { visible: boolean; heading: string; slides: FrontPageSlide[] };
  categories: { visible: boolean; title: string; subtitle: string; ids: number[] };
  featured: { visible: boolean; title: string; ids: number[] };
  new_arrivals: { visible: boolean; title: string; ids: number[]; mode: "latest" | "selected"; limit: number };
  promotions: { visible: boolean; cards: FrontPagePromo[] };
  benefits: { visible: boolean; items: { id: string; icon: "ShoppingBag" | "PackageCheck" | "Heart" | "Search"; title: string; text: string }[] };
  faq: { visible: boolean; title: string; description: string; items: { id: string; question: string; answer: string }[] };
};
export type FrontPageSettings = { content: FrontPageContent | null; version: number };

export function defaultFrontPage(): FrontPageContent {
  const promo = pageData.promotions;
  return {
    hero: { visible: true, heading: pageData.hero.h1Text, slides: pageData.hero.slides.map((slide, index) => ({ ...slide, id: "slide-" + index })) },
    categories: { visible: true, title: pageData.categories.h2Text, subtitle: pageData.categories.pText, ids: [] },
    featured: { visible: true, title: pageData.page.title, ids: [] },
    new_arrivals: { visible: true, title: pageData.page.title2, ids: [], mode: "latest", limit: 4 },
    promotions: { visible: true, cards: [
      { id: "promo-1", image: promo.imagesExpHeroPng, alt: promo.imageAlt, eyebrow: promo.eyebrow, title: promo.textText + "\n" + promo.textText2, description: promo.description, href: "/products", action: promo.action },
      { id: "promo-2", image: promo.imagesExpOfferPng, alt: promo.imageAlt2, eyebrow: promo.eyebrow2, title: promo.textText3 + "\n" + promo.textText4, description: promo.description2, href: "/categories", action: promo.action2 },
    ] },
    benefits: { visible: true, items: pageData.benefits.benefits.map((item, index) => ({ ...item, id: "benefit-" + index, icon: item.icon as FrontPageContent["benefits"]["items"][number]["icon"] })) },
    faq: { visible: true, title: pageData.faq.label, description: pageData.faq.label2, items: pageData.collections.homeFaqItems.map((item) => ({ ...item })) },
  };
}
