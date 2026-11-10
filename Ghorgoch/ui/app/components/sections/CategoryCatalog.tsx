"use client";

import pageData from "@/data/categories.json";

import Link from "next/link";
import { useEffect, useState } from "react";
import CatalogCollection from "@/components/sections/CatalogCollection";
import { api, ApiError, type Category } from "@/components/catalog";

const content = pageData.catalog;

export default function CategoryCatalog({ slug }: { slug?: string }) {
  const [items, setItems] = useState<Category[] | null>(null);
  const [error, setError] = useState("");
  const [revision, setRevision] = useState(0);
  useEffect(() => {
    let active = true;
    setItems(null); setError("");
    const request = slug ? api<{ item: Category }>(`/catalog/categories/${encodeURIComponent(slug)}`).then((data) => [data.item]) : api<{ items: Category[] }>("/catalog/categories").then((data) => data.items);
    request.then((data) => { if (active) { setItems(data); } }).catch((failure) => { if (active) { setError(failure instanceof ApiError && failure.status === 404 ? content.label : failure.message); } });
    return () => { active = false; };
  }, [slug, revision]);
  return <main id="main-content" tabIndex={-1} className="page-container">
    <nav className="catalog-breadcrumb" aria-label={content.breadcrumb}><Link href="/">{content.LinkText}</Link> {content.navText}<Link href="/categories">{content.LinkText2}</Link>{slug && <> {content.textText}{items?.[0]?.name || slug}</>}</nav>
    {error ? <div className="catalog-state" role="alert"><p>{error}</p><button className="button" onClick={() => setRevision((value) => value + 1)}>{content.buttonText}</button></div> : !items ? <p className="catalog-state" role="status">{content.pText}</p> : slug ? <><p>{items[0].description}</p><CatalogCollection category={slug} title={items[0].name} /></> : <section className="section"><p className="store-eyebrow">{content.pText2}</p><h1>{content.h1Text}</h1><div className="category-grid">{items.map((category) => <Link className="category-tile" href={`/categories/${category.slug}`} key={category.id}><h2>{category.name} {content.h2Text}</h2><p>{category.description}</p></Link>)}</div>{!items.length && <p className="catalog-state">{content.pText3}</p>}</section>}
  </main>;
}
