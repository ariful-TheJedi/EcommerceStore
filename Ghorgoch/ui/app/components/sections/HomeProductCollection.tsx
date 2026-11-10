
import pageData from "@/data/front-page.json";

import Link from "next/link";
import ProductCard from "@/components/molecules/ProductCard";
import { lowestVariant, productImage, type Product } from "@/components/catalog";

const content = pageData.products;

type HomeProductCollectionProps = {
  sectionId: string;
  title: string;
  products: Product[];
  unavailable?: boolean;
};

export default function HomeProductCollection({ sectionId, title, products, unavailable = false }: HomeProductCollectionProps) {
  return <section id={sectionId} className="section store-collection" aria-labelledby={`${sectionId}-title`}>
    <div className="store-section-heading"><h2 id={`${sectionId}-title`}>{title}</h2><Link href="/products">{content.LinkText}</Link></div>
    {products.length ? <div className="product-grid">{products.map((product) => {
      const variant = lowestVariant(product);
      return <ProductCard isNew={product.is_new} key={product.id} productId={product.id} variantId={variant?.id} name={product.name} image={productImage(product) || variant?.images[0]?.url} price={variant ? Number(variant.effective_price) : undefined} originalPrice={variant ? Number(variant.price) : undefined} available={variant?.available ?? false} currency={product.currency} href={`/products/${product.id}`} />;
    })}</div> : <p role={unavailable ? "alert" : undefined} className="py-8 text-sm text-brown">{unavailable ? content.label : content.label2}</p>}
  </section>;
}
