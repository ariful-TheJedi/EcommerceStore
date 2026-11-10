"use client";



import { useEffect, useId, useRef, useState } from "react";
import { ChevronLeft, ChevronRight } from "lucide-react";
import ProductCard from "@/components/molecules/ProductCard";
import { lowestVariant, productImage, type Product } from "@/components/catalog";

export default function CategoryProductSlider({ products, categoryName }: { products: Product[]; categoryName: string }) {
  const track = useRef<HTMLUListElement>(null);
  const trackId = useId();
  const [position, setPosition] = useState({ previous: false, next: false });
  useEffect(() => {
    const element = track.current;
    if (!element) { return; }
    function update() {
      if (element) { setPosition({ previous: element.scrollLeft > 2, next: element.scrollLeft + element.clientWidth < element.scrollWidth - 2 }); }
    }
    update();
    element.addEventListener("scroll", update, { passive: true });
    const observer = new ResizeObserver(update);
    observer.observe(element);
    return () => { element.removeEventListener("scroll", update); observer.disconnect(); };
  }, [products]);

  function slide(direction: number) {
    const element = track.current;
    if (!element) { return; }
    const card = element.firstElementChild;
    const distance = card ? card.getBoundingClientRect().width + (Number.parseFloat(window.getComputedStyle(element).columnGap) || 0) : element.clientWidth;
    element.scrollBy({ left: distance * direction, behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "instant" : "smooth" });
  }
  const control = "flex size-11 items-center justify-center rounded-full border border-cocoa/15 bg-ivory text-cocoa hover:border-olive hover:text-olive focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-olive disabled:cursor-not-allowed disabled:opacity-30";

  return <div>
    <div className="mb-3 flex justify-end gap-2" aria-label={`${categoryName} পণ্য স্লাইডার`}>
      <button type="button" className={control} aria-label={`${categoryName}: আগের পণ্য`} aria-controls={trackId} disabled={!position.previous} onClick={() => slide(-1)}><ChevronLeft size={19} aria-hidden="true" /></button>
      <button type="button" className={control} aria-label={`${categoryName}: পরের পণ্য`} aria-controls={trackId} disabled={!position.next} onClick={() => slide(1)}><ChevronRight size={19} aria-hidden="true" /></button>
    </div>
    <ul ref={track} id={trackId} tabIndex={0} aria-label={`${categoryName} পণ্য`} className="grid snap-x snap-mandatory auto-cols-[calc((100%_-_1rem)/2)] grid-flow-col gap-4 md:gap-6 overflow-x-auto overscroll-x-contain [scrollbar-width:none] [&::-webkit-scrollbar]:hidden pt-1 pb-5 focus-visible:outline-2 focus-visible:outline-olive md:auto-cols-[calc((100%_-_3rem)/3)] lg:auto-cols-[calc((100%_-_4.5rem)/4)]">
      {products.slice(0, 6).map((product) => {
        const variant = lowestVariant(product);
        return <li key={product.id} className="min-w-0 snap-start"><ProductCard isNew={product.is_new} productId={product.id} variantId={variant?.id} name={product.name} image={productImage(product) || variant?.images[0]?.url} price={variant ? Number(variant.effective_price) : undefined} originalPrice={variant ? Number(variant.price) : undefined} currency={product.currency} available={variant?.available ?? false} href={`/products/${product.id}`} /></li>;
      })}
    </ul>
  </div>;
}