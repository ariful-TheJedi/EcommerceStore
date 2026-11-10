"use client";

import pageData from "@/data/products.json";
import { useState, type ReactNode } from "react";
import { useRouter } from "next/navigation";
import { useCartStore } from "@/components/CartProvider";
import { ShoppingBag } from "lucide-react";
import Button from "@/components/atoms/Button";
import { money, type Product } from "@/components/catalog";
import { chooseProductVariant, initialProductVariant, productGalleryImages } from "@/services/product-variants";
import ProductGallery from "./ProductGallery";

const content = pageData.purchase;

export default function ProductPurchase({ product, children }: { product: Product; children: ReactNode }) {
  const { addToCart } = useCartStore();
  const router = useRouter();
  const [variantId, setVariantId] = useState(initialProductVariant(product)?.id);
  const [added, setAdded] = useState(false);
  const variants = product.variants.filter((item) => item.is_active);
  const variant = variants.find((item) => item.id === variantId) || initialProductVariant(product);
  const images = productGalleryImages(product, variant);
  const attributeNames = Array.from(new Set(variants.flatMap((item) => Object.keys(item.attributes))));
  const focus = "focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-olive";
  const purchasable = Boolean(variant?.available && Number.isFinite(Number(variant.effective_price)) && Number(variant.effective_price) >= 0);
  function addSelectedVariant(): boolean {
    if (!variant || !purchasable) { return false; }
    addToCart({ id: "variant-" + variant.id, variantId: variant.id, name: product.name, image: images[0]?.src, href: "/products/" + product.id, price: Number(variant.effective_price), currency: product.currency, attributes: Object.entries(variant.attributes).map(([key, value]) => key + ": " + value).join(" / "), maxQuantity: variant.inventory_enabled !== false && variant.available_stock > 0 ? variant.available_stock : 99 });
    setAdded(true);
    return true;
  }
  function chooseAttribute(name: string, value: string) {
    if (!variant) { return; }
    const next = chooseProductVariant(variants, variant, name, value);
    if (next) { setVariantId(next.id); setAdded(false); }
  }

  return <>
    <ProductGallery key={variant?.id ?? "product"} images={images} />
    <div className="flex min-w-0 flex-col pt-0.5">
      {variant && <p className="text-xs text-brown">{content.pText}<span lang="en">{variant.sku}</span></p>}
      {children}
      <div className="mt-8 flex flex-wrap items-baseline gap-3" aria-live="polite"><p className="text-4xl leading-tight font-semibold text-olive">{variant ? money(variant.effective_price, product.currency) : pageData.card.label}</p>{variant && Number(variant.price) > Number(variant.effective_price) && <del className="text-lg text-brown">{money(variant.price, product.currency)}</del>}</div>
      {variant && <div className="mt-8 flex flex-col gap-6">
        {attributeNames.map((name) => <fieldset key={name} className="min-w-0">
          <legend className="text-sm font-medium">{name}<span className="mx-2 text-brown/40">{content.spanText}</span><span className="font-normal text-brown">{variant.attributes[name]}</span></legend>
          <div className="mt-3 flex flex-wrap gap-2">
            {Array.from(new Set(variants.map((item) => item.attributes[name]).filter(Boolean))).map((value) => <button key={value} type="button" onClick={() => chooseAttribute(name, value)} aria-pressed={variant.attributes[name] === value} className={"min-h-touch min-w-16 rounded-card border px-4 py-2 text-sm " + focus + " " + (variant.attributes[name] === value ? "border-olive bg-olive text-ivory" : "border-cocoa/15 bg-ivory hover:border-olive")}>{value}</button>)}
          </div>
        </fieldset>)}
        {variants.length > 1 && <label className="flex flex-col gap-2 text-sm">{content.pText}<select value={variant.id} onChange={(event) => { setVariantId(Number(event.target.value)); setAdded(false); }} className="min-h-touch rounded-card border border-cocoa/20 bg-ivory px-3 text-sm">{variants.map((item) => <option key={item.id} value={item.id}>{item.sku}{item.available ? "" : " - " + content.label2}</option>)}</select></label>}
      </div>}
      {variant?.inventory_enabled !== false && <p role="status" className="mt-6 text-sm text-brown">{variant?.available ? variant.available_stock > 0 ? content.label6.replace("{value1}", variant.available_stock.toLocaleString("bn-BD")) : content.label : content.label2}</p>}
      <div className="mt-8 grid grid-cols-1 gap-3 sm:grid-cols-2">
        <Button size="lg" fullWidth disabled={!purchasable} onClick={() => { if (addSelectedVariant()) { router.push("/checkout"); } }}>{content.ButtonText}</Button>
        <Button size="lg" variant="outline" fullWidth disabled={!purchasable} onClick={addSelectedVariant} startIcon={<ShoppingBag size={17} strokeWidth={1.7} />}>{added ? content.label3 : content.label4}</Button>
      </div>
      <p role="status" className="mt-2 text-sm text-olive">{added ? content.label5 : ""}</p>
    </div>
  </>;
}
