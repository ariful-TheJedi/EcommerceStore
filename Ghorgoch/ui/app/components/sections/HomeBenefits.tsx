import pageData from "@/data/front-page.json";

import { Heart, PackageCheck, Search, ShoppingBag } from "lucide-react";

const content = pageData.benefits;

const benefitIcons = { ShoppingBag, PackageCheck, Heart, Search };



export default function HomeBenefits({ items = content.benefits }: { items?: { icon: string; title: string; text: string }[] }) {
  const benefits = items.map((benefit) => ({ ...benefit, icon: benefitIcons[benefit.icon as keyof typeof benefitIcons] || ShoppingBag }));
  return <div className="reference-benefits" aria-label={content.arialabel}>
    {benefits.map(({ icon: Icon, title, text }) => <div key={title}><Icon size={25} strokeWidth={1.3} aria-hidden="true" /><div><strong>{title}</strong><span>{text}</span></div></div>)}
  </div>;
}
