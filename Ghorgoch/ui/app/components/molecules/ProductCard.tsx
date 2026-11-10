"use client";

import pageData from "@/data/products.json";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import Button from "@/components/atoms/Button";
import ProductImageZoom from "@/components/atoms/ProductImageZoom";
import { useCartStore } from "@/components/CartProvider";
import { ArrowUpRight, ShoppingCart } from "lucide-react";
import { money } from "@/components/catalog";

const content = pageData.card;

type ProductCardProps = {
  productId: string | number;
  variantId?: number;
  available?: boolean;
  name: string;
  image?: string;
  price?: number;
  currency?: string;
  originalPrice?: number;
  isNew?: boolean;
  href?: string;
  onAddToCart?: () => void;
  onBuyNow?: () => void;
};

export default function ProductCard({ productId, variantId, available = true, name, image, price, currency = "BDT", originalPrice, href, onAddToCart, onBuyNow }: ProductCardProps) {
  const { addToCart } = useCartStore();
  const router = useRouter();
  const [added, setAdded] = useState(false);

  function handleAddToCart() {
    if (onAddToCart) { onAddToCart(); } else if (price !== undefined) { addToCart({ id: variantId ? "variant-" + variantId : String(productId), variantId, name, image, price, currency, href: href || "/products" }); }
    setAdded(true);
  }

  function handleBuyNow() {
    if (onBuyNow) { onBuyNow(); return; }
    handleAddToCart();
    router.push("/checkout");
  }
  const onSale = originalPrice !== undefined && price !== undefined && originalPrice > price && price >= 0;
  const discount = onSale ? Math.round((1 - price! / originalPrice!) * 100) : 0;
  const photo = image ? <ProductImageZoom src={image} alt={name} width={600} height={750} loading="lazy" /> : <span className="catalog-no-image">{content.spanText}</span>;

  return (
    <article className="store-product">
      <div className="store-product-visual">
        {href ? <Link href={href} className="store-product-image">{photo}</Link> : photo}
        {onSale && <div className="pointer-events-none absolute inset-x-3 top-3 flex flex-wrap items-start gap-2">
          {onSale && <span lang="bn" className="inline-flex items-center rounded-full border border-[#e8cda2] bg-[#fff4df] px-2.5 py-0.5 text-xs font-semibold leading-5 text-[#71451d] shadow-sm">{content.savingsPill.replace("{percent}", new Intl.NumberFormat("bn-BD").format(discount))}</span>}
        </div>}
      </div>
      <div className="store-product-info">
        <div className="store-product-heading">
          <h3 className="product-name">{href ? <Link href={href}>{name}</Link> : name}</h3>
        </div>
        <p className="store-product-prices"><span className="price">{price === undefined ? content.label : money(price, currency)}</span>{onSale && <del className="store-product-original"><span className="sr-only">{content.spanText5}</span>{money(originalPrice!, currency)}</del>}</p>
        {<Link className="store-product-link" href={href || "/products"} aria-label={`${name} বিস্তারিত দেখুন`}><ArrowUpRight size={17} aria-hidden="true" /></Link>}
      </div>
      <div className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
        <Button variant="outline" size="sm" fullWidth disabled={price === undefined || !available} onClick={handleAddToCart} startIcon={<ShoppingCart size={16} strokeWidth={1.75} />} aria-label={`${name} ব্যাগে যোগ করুন`}>{content.ButtonText}</Button>
        <Button size="sm" fullWidth disabled={price === undefined || !available} onClick={handleBuyNow}>{!available ? "উপলব্ধ নয়" : content.ButtonText2}</Button>
      </div>
      <span className="sr-only" role="status">{added ? content.label2.replace("{value1}", String(name)) : ""}</span>
    </article>
  );
}
