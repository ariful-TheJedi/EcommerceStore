import Link from "next/link";
import { ArrowRight } from "lucide-react";
import type { ReactNode } from "react";

type PromoCardProps = {
  variant: "everyday" | "home";
  image: string;
  imageAlt: string;
  imageWidth: number;
  imageHeight: number;
  eyebrow: string;
  title: ReactNode;
  description: string;
  href: string;
  action: string;
};

export default function PromoCard({ variant, image, imageAlt, imageWidth, imageHeight, eyebrow, title, description, href, action }: PromoCardProps) {
  return <div className={`reference-promo reference-promo-${variant}`}>
    <img src={image} alt={imageAlt} width={imageWidth} height={imageHeight} loading="lazy" />
    <div><p className="store-eyebrow">{eyebrow}</p><h2>{title}</h2><p>{description}</p><Link className="button button-primary" href={href}>{action} <ArrowRight size={16} aria-hidden="true" /></Link></div>
  </div>;
}
