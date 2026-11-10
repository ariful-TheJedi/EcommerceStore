import pageData from "@/data/front-page.json";

import Link from "next/link";
import { ArrowRight } from "lucide-react";
import type { CSSProperties } from "react";
import type { CatalogCategory } from "@/services/categories";
import CategoryCard from "@/components/molecules/CategoryCard";

const content = pageData.categories;

export default function HomeCategories({ categories, images = {}, unavailable = false, title = content.h2Text, subtitle = content.pText }: { title?: string; subtitle?: string; categories: CatalogCategory[]; images?: Record<string, string>; unavailable?: boolean }) {
  return <section className="reference-categories" aria-labelledby="categories-title">
    <div className="store-section-heading"><div><h2 id="categories-title">{title}</h2><p>{subtitle}</p></div><Link href="/categories">{content.LinkText}<ArrowRight size={15} aria-hidden="true" /></Link></div>
    {categories.length ? <div className="reference-category-list" style={{ "--category-count": categories.length } as CSSProperties}>
      {categories.map((category) => <CategoryCard key={category.id} name={category.name} image={category.image_url || images[category.slug]} href={`/categories/${encodeURIComponent(category.slug)}`} />)}
    </div> : <p role={unavailable ? "alert" : undefined} className="py-8 text-sm text-brown">{unavailable ? content.label : content.label2}</p>}
  </section>;
}
