
import pageData from "@/data/products.json";

import Link from "next/link";
import { type Product, lowestVariant, productImage } from "@/components/catalog";
import { getProducts } from "@/services/products";
import navbarContent from "@/data/navbar.json";
import ProductCard from "@/components/molecules/ProductCard";
import ProductPurchase from "./ProductPurchase";
import ProductInformation from "./ProductInformation";

const content = pageData.details;

export default async function ProductDetails({ product }: { product: Product }) {
  const category = product.categories[0];
  let relatedProducts: Product[] = [];
  if (category) {
    try { relatedProducts = (await getProducts({ category: category.slug, limit: 5 })).products.filter((item) => item.id !== product.id).slice(0, 4); }
    catch { relatedProducts = []; }
  }
  return <main id="main-content" tabIndex={-1} lang="bn" data-product-id={product.id} className="bg-ivory font-body text-cocoa">
    <div className="page-container pt-5 pb-12">
      <nav aria-label={content.arialabel} className="mb-5 flex flex-wrap items-center gap-3 text-xs text-brown">
        <Link href="/">{navbarContent.ghorgochHome}</Link><span aria-hidden="true">{content.spanText}</span>
        {category && <><Link href={`/categories/${encodeURIComponent(category.slug)}`}>{category.name}</Link><span aria-hidden="true">{content.spanText}</span></>}
        <span aria-current="page">{product.name}</span>
      </nav>
      <section aria-labelledby="product-title" className="grid items-start gap-9 lg:grid-cols-[1.11fr_1fr] lg:gap-[8.5%]">
        <ProductPurchase product={product}><h1 id="product-title" className="mt-5 font-heading text-2xl leading-snug font-medium sm:text-3xl">{product.name}</h1>{product.description && <p className="mt-4 line-clamp-3 whitespace-pre-line text-sm leading-7 text-brown">{product.description}</p>}</ProductPurchase>
      </section>
      <ProductInformation product={product} />
      {relatedProducts.length > 0 && <section aria-labelledby="related-products-title" className="mt-16 border-t border-cocoa/10 pt-10">
        <div className="store-section-heading">
          <h2 id="related-products-title">{content.h2Text}</h2>
          <Link href={category ? `/categories/${encodeURIComponent(category.slug)}` : "/products"}>{content.LinkText}</Link>
        </div>
        <div className="product-grid">
          {relatedProducts.map((item) => { const variant = lowestVariant(item); return <ProductCard isNew={item.is_new} key={item.id} productId={item.id} variantId={variant?.id} name={item.name} image={productImage(item)} price={variant ? Number(variant.effective_price) : undefined} originalPrice={variant ? Number(variant.price) : undefined} available={variant?.available ?? false} currency={item.currency} href={`/products/${item.id}`} />; })}
        </div>
      </section>}
    </div>
  </main>;
}
