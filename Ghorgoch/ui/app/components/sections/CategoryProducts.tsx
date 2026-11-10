import pageData from "@/data/categories.json";
import productData from "@/data/products.json";
import Link from "next/link";
import { ArrowUpRight, Package } from "lucide-react";
import CatalogCollection from "./CatalogCollection";
import type { CatalogCategory } from "@/services/categories";
import { categoryAncestors } from "@/services/category-tree";

const content = pageData.products;

export default function CategoryProducts({ category, categories }: { category: CatalogCategory; categories: CatalogCategory[] }) {
  const ancestors = categoryAncestors(category, categories);
  const children = categories.filter((item) => item.parent_id === category.id);
  return <main id="main-content" tabIndex={-1} lang="bn" className="page-container pb-16">
    <nav aria-label={content.arialabel} className="flex flex-wrap items-center gap-3 py-6 text-xs text-brown">
      <Link href="/" className="hover:text-olive">{content.LinkText}</Link><span aria-hidden="true">{content.spanText}</span><Link href="/categories" className="hover:text-olive">{content.LinkText2}</Link>
      {ancestors.map((parent) => <span key={parent.id} className="inline-flex items-center gap-3"><span aria-hidden="true">{content.spanText}</span><Link className="hover:text-olive" href={"/categories/" + encodeURIComponent(parent.slug)}>{parent.name}</Link></span>)}
      <span aria-hidden="true">{content.spanText}</span><span aria-current="page" className="text-cocoa">{category.name}</span>
    </nav>
    <header className="flex flex-wrap items-center gap-5 rounded-card border border-cocoa/10 bg-rose/20 p-6 sm:gap-7 sm:p-9">
      <span className="flex size-20 shrink-0 items-center justify-center rounded-2xl border border-cocoa/10 bg-ivory text-olive sm:size-24">{category.image_url ? <img src={category.image_url} alt="" width={80} height={80} className="size-full object-contain p-3" /> : <Package size={38} strokeWidth={1.3} aria-hidden="true" />}</span>
      <div className="min-w-0 flex-1 basis-48"><h1 id="category-title" className="font-heading text-3xl leading-snug sm:text-4xl">{category.name}</h1>{category.description && <p className="mt-3 max-w-2xl whitespace-pre-line text-sm leading-7 text-brown">{category.description}</p>}</div>
    </header>
    {children.length > 0 && <nav aria-label={category.name + " - " + content.LinkText2} className="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      {children.map((child) => <Link key={child.id} href={"/categories/" + encodeURIComponent(child.slug)} className="group flex min-h-20 items-center gap-4 rounded-card border border-cocoa/10 bg-ivory p-4 transition-colors hover:border-olive/40 hover:bg-rose/15 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-olive motion-reduce:transition-none"><span className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-rose/25 text-olive">{child.image_url ? <img src={child.image_url} alt="" width={40} height={40} className="size-10 object-contain p-1" /> : <Package size={24} strokeWidth={1.4} aria-hidden="true" />}</span><span className="min-w-0 flex-1 text-sm font-medium">{child.name}</span><ArrowUpRight size={17} className="shrink-0 text-brown group-hover:text-olive" aria-hidden="true" /></Link>)}
    </nav>}
    <CatalogCollection key={category.slug} category={category.slug} title={productData.catalog.label} sectionId="category-products" />
  </main>;
}
