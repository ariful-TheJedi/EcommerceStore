import pageData from "@/data/categories.json";

import Link from "next/link";
import { Package } from "lucide-react";

const content = pageData.card;

type CategoryCardProps = {
  name: string;
  image?: string;
  href: string;
};

export default function CategoryCard({ name, image, href }: CategoryCardProps) {
  return <Link className="reference-category" href={href}>
    <span>{image ? <img src={image} alt={content.alt} width="220" height="220" loading="lazy" /> : <Package size={38} strokeWidth={1.2} className="text-olive" aria-hidden="true" />}</span>
    <h3>{name}</h3>
  </Link>;
}
