"use client";

import pageData from "@/data/products.json";

import { useState } from "react";
import { ShoppingBag } from "lucide-react";
import ProductImageZoom from "@/components/atoms/ProductImageZoom";

const content = pageData.gallery;

export default function ProductGallery({ images }: { images: { src: string; alt: string }[] }) {
  const [selectedImage, setSelectedImage] = useState(0);
  const image = images[selectedImage] || images[0];
  const focus = "focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-olive";
  return <div className="min-w-0">
          <div className="flex aspect-[1.13/1] items-center justify-center overflow-hidden rounded-card bg-rose/30">
            {image ? <ProductImageZoom key={image.src} src={image.src} alt={image.alt} width={800} height={800} fetchPriority="high" imageClassName="mix-blend-multiply" /> : <div className="flex flex-col items-center gap-4 text-brown"><ShoppingBag size={48} strokeWidth={1.3} aria-hidden="true" /><p className="text-sm">{pageData.card.spanText}</p></div>}
          </div>
          <div className="mt-4 grid grid-cols-5 gap-2.5" aria-label={content.arialabel}>
            {images.map((image, index) => <button key={`${image.src}-${index}`} type="button" onClick={() => setSelectedImage(index)} aria-label={`${image.alt} (${index + 1})`} aria-pressed={selectedImage === index} className={`aspect-[1.1/1] overflow-hidden rounded-card border bg-rose/30 p-1 ${focus} ${selectedImage === index ? "border-olive" : "border-transparent"}`}><img src={image.src} alt={content.alt} width={110} height={100} className="h-full w-full object-contain mix-blend-multiply" /></button>)}
          </div>
        </div>;
}
