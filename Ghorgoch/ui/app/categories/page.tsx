import pageData from "@/data/categories.json";

import Link from "next/link";
import { Suspense } from "react";
import CategoryProductPreview from "@/components/sections/CategoryProductPreview";
import { ArrowUpRight, Package } from "lucide-react";
import { getCategories } from "@/services/categories";

const content = pageData.page;

export const dynamic = "force-dynamic";

export const metadata = { title: content.title };

export default async function CategoriesPage() {
  let categories: Awaited<ReturnType<typeof getCategories>> = [];
  let unavailable = false;
  try { categories = await getCategories(); }
  catch { unavailable = true; }
  const byId = new Map(categories.map((category) => [category.id, category]));

  return <main id="main-content" tabIndex={-1} lang="bn" className="page-container pb-16">
    <nav aria-label={content.arialabel} className="flex items-center gap-3 py-6 text-xs text-brown"><Link href="/">{content.LinkText}</Link><span aria-hidden="true">{content.spanText}</span><span aria-current="page">{content.spanText2}</span></nav>
    <h1 className="sr-only">{content.h1Text}</h1>
    {unavailable ? <div role="alert" className="flex min-h-64 flex-col items-center justify-center gap-4 text-center"><p className="text-brown">{content.pText3}</p><a href="/categories" className="inline-flex min-h-touch items-center rounded-card border border-cocoa/20 px-5 py-2 text-sm text-cocoa">{content.aText}</a></div> : categories.length ? <div aria-label={content.arialabel2} className="flex flex-col gap-12">
      {categories.map((category) => <section key={category.id} aria-labelledby={`category-${category.id}-title`} className="border-b border-cocoa/10 pb-10 last:border-b-0">
        <header className="flex items-start gap-4">
          <span className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-rose/40 text-olive">{category.image_url ? <img src={category.image_url} alt="" width={36} height={36} className="size-9 object-contain" /> : <Package size={24} strokeWidth={1.5} aria-hidden="true" />}</span>
          <div className="min-w-0">{category.parent_id !== null && byId.has(category.parent_id) && <p className="mb-1 text-xs text-olive">{byId.get(category.parent_id)?.name}</p>}<h2 id={`category-${category.id}-title`} className="font-heading text-2xl"><Link href={`/categories/${encodeURIComponent(category.slug)}`} className="hover:text-olive focus-visible:outline-2 focus-visible:outline-olive">{category.name}</Link></h2>{category.description && <p className="mt-2 line-clamp-2 max-w-2xl text-sm leading-7 text-brown">{category.description}</p>}</div>
        </header>
        <div className="mt-3"><Suspense fallback={<p role="status" className="flex min-h-64 items-center justify-center rounded-xl bg-rose/10 text-sm text-brown">পণ্য লোড হচ্ছে...</p>}><CategoryProductPreview slug={category.slug} name={category.name} /></Suspense></div>
        <div className="mt-4 flex justify-center"><Link href={`/categories/${encodeURIComponent(category.slug)}`} aria-label={`${category.name} — সব পণ্য দেখুন`} className="inline-flex min-h-12 items-center gap-2 rounded-card border border-olive/30 bg-ivory px-6 py-3 text-sm font-medium text-olive transition-colors hover:bg-olive hover:text-ivory focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-olive motion-reduce:transition-none">সব পণ্য দেখুন<ArrowUpRight size={17} strokeWidth={1.5} aria-hidden="true" /></Link></div>
      </section>)}
    </div> : <p className="py-16 text-center text-sm text-brown">{content.pText4}</p>}
  </main>;
}
