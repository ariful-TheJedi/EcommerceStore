import pageData from "@/data/front-page.json";

import { defaultFrontPage, type FrontPagePromo } from "@/config/front-page";
import PromoCard from "@/components/molecules/PromoCard";

const content = pageData.promotions;

export default function HomePromotions({ cards = defaultFrontPage().promotions.cards }: { cards?: FrontPagePromo[] }) {
  return <section className="reference-promos grid gap-5 md:grid-cols-2" aria-label={content.arialabel}>
    {cards.map((card, index) => <PromoCard key={card.id} variant={index % 2 === 0 ? "everyday" : "home"} image={card.image} imageAlt={card.alt} imageWidth={1774} imageHeight={887} eyebrow={card.eyebrow} title={<span className="whitespace-pre-line">{card.title}</span>} description={card.description} href={card.href} action={card.action} />)}
  </section>;
}
