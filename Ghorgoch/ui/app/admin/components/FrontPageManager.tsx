"use client";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState, type FormEvent } from "react";
import { ArrowDown, ArrowUp, ExternalLink, Plus, Save, Trash2 } from "lucide-react";
import Button from "@/components/atoms/Button";
import { useAdminAuth } from "@/admin/services/useAdminAuth";
import { AdminApiError } from "@/admin/services/request";
import { loadTaxonomy, type CmsTerm } from "@/admin/services/products";
import { defaultFrontPage, type FrontPageContent, type FrontPageSettings, type FrontPageSlide, type FrontPagePromo } from "@/config/front-page";
import type { ProductListing } from "@/components/catalog";
import FrontPageProductPicker, { type ProductChoice } from "./FrontPageProductPicker";
import { Field, productInput, productPanel } from "./ProductFields";

const sections = [{ key: "hero", label: "Hero slider" }, { key: "categories", label: "Categories" }, { key: "featured", label: "Selected products" }, { key: "new_arrivals", label: "New arrivals" }, { key: "promotions", label: "Promotions" }, { key: "benefits", label: "Benefits" }, { key: "faq", label: "FAQ" }] as const;
type Section = typeof sections[number]["key"];

function TextField({ label, value, onChange, multiline = false, required = false }: { label: string; value: string; onChange: (value: string) => void; multiline?: boolean; required?: boolean }) {
  return <label className="flex min-w-0 flex-col gap-2 text-sm"><span>{label}</span>{multiline ? <textarea rows={3} maxLength={2000} value={value ?? ""} onChange={(event) => onChange(event.target.value)} className={productInput} required={required} /> : <input value={value ?? ""} maxLength={2000} onChange={(event) => onChange(event.target.value)} className={productInput} required={required} />}</label>;
}

function move<T>(items: T[], index: number, direction: number): T[] { const next = [...items]; const target = index + direction; if (target < 0 || target >= next.length) { return items; } [next[index], next[target]] = [next[target], next[index]]; return next; }

function MediaFields({ item, hero, onChange, onUpload }: { item: FrontPageSlide | FrontPagePromo; hero: boolean; onChange: (patch: Partial<FrontPageSlide>) => void; onUpload: (file: File) => void }) {
  return <div className="grid gap-4 sm:grid-cols-2">
    <div className="sm:col-span-2"><img src={item.image} alt={item.alt || "Slide preview"} width={640} height={320} className="mb-4 aspect-[2/1] max-h-64 w-full rounded-xl border border-cocoa/10 bg-ivory object-cover" /><label className="flex flex-col gap-2 text-sm">Upload image<input type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" className={productInput} onChange={(event) => { const file = event.target.files?.[0]; if (file) { onUpload(file); } event.target.value = ""; }} /></label><p className="mt-2 text-xs text-brown">JPEG, PNG or WebP, up to 5 MB. Images use the backend media storage.</p></div>
    <TextField label="Image URL" value={item.image} required onChange={(image) => onChange({ image, image_path: null })} />
    <TextField label="Image alternative text" value={item.alt} onChange={(alt) => onChange({ alt })} />
    <TextField label="Eyebrow" value={item.eyebrow} onChange={(eyebrow) => onChange({ eyebrow })} />
    <TextField label="Title" value={item.title} required onChange={(title) => onChange({ title })} />
    {hero && <TextField label="Accent text" value={(item as FrontPageSlide).accent} onChange={(accent) => onChange({ accent })} />}
    <TextField label="Description" value={item.description} multiline onChange={(description) => onChange({ description })} />
    <TextField label="Button text" value={item.action} required onChange={(action) => onChange({ action })} />
    <TextField label="Button link" value={item.href} required onChange={(href) => onChange({ href })} />
  </div>;
}

export default function FrontPageManager() {
  const { request } = useAdminAuth();
  const [content, setContent] = useState<FrontPageContent | null>(null);
  const [version, setVersion] = useState(0);
  const [categories, setCategories] = useState<CmsTerm[]>([]);
  const [knownProducts, setKnownProducts] = useState<ProductChoice[]>([]);
  const [section, setSection] = useState<Section>("hero");
  const [editable, setEditable] = useState(false);
  const [loading, setLoading] = useState(true);
  const [revision, setRevision] = useState(0);
  const [saving, setSaving] = useState(false);
  const [uploading, setUploading] = useState<string | null>(null);
  const [dirty, setDirty] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const lock = useRef(false);
  const busy = saving || uploading !== null;
  const rememberProducts = useCallback((items: ProductChoice[]) => setKnownProducts((current) => Array.from(new Map([...current, ...items].map((item) => [item.id, item])).values())), []);

  useEffect(() => {
    const controller = new AbortController(); const signal = AbortSignal.any([controller.signal, AbortSignal.timeout(25000)]);
    setLoading(true); setError("");
    Promise.all([
      request<FrontPageSettings & { products: ProductChoice[] }>("/front-page", { signal }),
      loadTaxonomy(request, "categories", signal),
      request<ProductListing>("/catalog/products?limit=4&sort=id", { signal }),
      request<{ user: { role: string; permissions: string[] } }>("/users/me", { signal }),
    ]).then(([settings, terms, initialProducts, account]) => {
      if (controller.signal.aborted) { return; }
      const initial = settings.content || defaultFrontPage();
      if (!settings.content) { initial.categories.ids = terms.filter((item) => item.is_active).slice(0, 100).map((item) => item.id); initial.featured.ids = initialProducts.products.map((item) => item.id); }
      setContent(initial); setVersion(settings.version); setCategories(terms); setKnownProducts([...settings.products, ...initialProducts.products]); setEditable(account.user.role === "admin" || account.user.permissions.includes("catalog.manage")); setDirty(false);
    }).catch((failure) => { if (!controller.signal.aborted) { setError(failure instanceof Error ? failure.message : "Unable to load front page settings."); } }).finally(() => { if (!controller.signal.aborted) { setLoading(false); } });
    return () => controller.abort();
  }, [request, revision]);
  useEffect(() => {
    if (!dirty) { return; }
    const prevent = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = ""; };
    window.addEventListener("beforeunload", prevent); return () => window.removeEventListener("beforeunload", prevent);
  }, [dirty]);

  function update<K extends Section>(key: K, patch: Partial<FrontPageContent[K]>) { setContent((current) => current ? { ...current, [key]: { ...current[key], ...patch } } : current); setDirty(true); setNotice(""); }
  function updateSlide(group: "hero" | "promotions", id: string, patch: Partial<FrontPageSlide>) {
    setContent((current) => !current ? current : group === "hero" ? { ...current, hero: { ...current.hero, slides: current.hero.slides.map((item) => item.id === id ? { ...item, ...patch } : item) } } : { ...current, promotions: { ...current.promotions, cards: current.promotions.cards.map((item) => item.id === id ? { ...item, ...patch } : item) } }); setDirty(true); setNotice("");
  }
  async function uploadImage(group: "hero" | "promotions", id: string, file: File) {
    if (lock.current || !editable) { return; }
    if (file.size > 5 * 1024 * 1024) { setError("Choose an image no larger than 5 MB."); return; }
    lock.current = true; setUploading(id); setError("");
    try { const body = new FormData(); body.set("image", file); const media = await request<{ image_path: string; url: string }>("/front-page/images", { method: "POST", body }); updateSlide(group, id, { image: media.url, image_path: media.image_path }); }
    catch (failure) { setError(failure instanceof Error ? failure.message : "Unable to upload image."); }
    finally { lock.current = false; setUploading(null); }
  }
  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!content || lock.current || !editable) { return; }
    lock.current = true; setSaving(true); setError(""); setNotice("");
    try { const saved = await request<FrontPageSettings>("/front-page", { method: "PATCH", body: { version, content } }); setContent(saved.content); setVersion(saved.version); setDirty(false); setNotice("Front page saved. Changes are now visible on the storefront."); }
    catch (failure) { setError(failure instanceof AdminApiError && failure.status === 409 ? "Another administrator changed the front page. Reload the saved version before trying again." : failure instanceof Error ? failure.message : "Unable to save front page."); }
    finally { lock.current = false; setSaving(false); }
  }

  if (loading) { return <p role="status" className="p-10 text-sm text-brown">Loading front page settings...</p>; }
  if (!content) { return <div role="alert" className={productPanel}><p>{error || "Unable to load settings."}</p><Button className="mt-4" onClick={() => setRevision((value) => value + 1)}>Retry</Button></div>; }
  const media = section === "hero" ? content.hero.slides : content.promotions.cards;

  return <form onSubmit={save} className="mx-auto flex max-w-6xl flex-col gap-6" style={{ fontFamily: "Arial, sans-serif" }}>
    <div className="flex flex-wrap items-start justify-between gap-4"><div><h1 className="font-[inherit] text-3xl font-semibold">Front page</h1><p className="mt-2 text-sm leading-6 text-brown">Manage homepage content and choose what customers see. Save to publish your changes.</p><p className="mt-2 text-xs text-olive">{dirty ? "Unsaved changes" : "Saved version " + version}{!editable ? " · Read only" : ""}</p></div><div className="flex flex-wrap gap-2"><Link href="/" target="_blank" rel="noopener noreferrer" className="inline-flex min-h-11 items-center gap-2 rounded-card border border-cocoa/20 px-4 py-2 text-sm focus-visible:outline-2 focus-visible:outline-olive">View storefront<ExternalLink size={15} /></Link><Button variant="outline" disabled={busy} onClick={() => { if (!dirty || window.confirm("Discard unsaved front page changes and reload?")) { setNotice(""); setRevision((value) => value + 1); } }}>Reload</Button>{editable && <Button type="submit" loading={saving} disabled={busy || !dirty} startIcon={<Save size={16} />}>Save front page</Button>}</div></div>
    {error && <p role="alert" className="rounded-xl border border-[#9b3528]/20 bg-[#fff0ec] p-4 text-sm text-[#9b3528]">{error}</p>}
    {notice && <p role="status" className="rounded-xl bg-olive/10 p-4 text-sm text-olive">{notice}</p>}
    {uploading && <p role="status" className="text-sm text-olive">Uploading image...</p>}
    <fieldset disabled={busy || !editable} className="flex min-w-0 flex-col gap-6">
      <section className={productPanel}><h2 className="mb-4 font-[inherit] text-lg font-semibold">Section visibility</h2><div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{sections.map((item) => <label key={item.key} className="flex cursor-pointer items-center gap-3 rounded-xl border border-cocoa/10 bg-ivory px-4 py-3 text-sm"><input type="checkbox" checked={content[item.key].visible} onChange={(event) => update(item.key, { visible: event.target.checked })} className="size-4 accent-olive" />{item.label}</label>)}</div></section>
      <div className="flex flex-wrap gap-2" role="group" aria-label="Front page sections">{sections.map((item) => <Button key={item.key} variant={section === item.key ? "primary" : "ghost"} aria-pressed={section === item.key} onClick={() => setSection(item.key)}>{item.label}</Button>)}</div>
      {(section === "hero" || section === "promotions") && <section className="flex flex-col gap-5">
        <div className="flex flex-wrap items-center justify-between gap-3"><h2 className="font-[inherit] text-xl font-semibold">{section === "hero" ? "Hero slides" : "Promotion cards"}</h2><Button variant="outline" disabled={media.length >= (section === "hero" ? 10 : 4)} startIcon={<Plus size={15} />} onClick={() => { const base = defaultFrontPage().hero.slides[0]; const next = { ...base, id: crypto.randomUUID() }; if (section === "hero") { update("hero", { slides: [...content.hero.slides, next] }); } else { const { accent: _accent, ...card } = next; update("promotions", { cards: [...content.promotions.cards, card] }); } }}>Add {section === "hero" ? "slide" : "card"}</Button></div>
        {section === "hero" && <TextField label="Homepage heading (for screen readers)" value={content.hero.heading} required onChange={(heading) => update("hero", { heading })} />}
        {media.map((item, index) => <article key={item.id} className={productPanel}><div className="mb-5 flex items-center justify-between gap-3"><h3 className="font-semibold">{section === "hero" ? "Slide " : "Card "}{index + 1}</h3><div className="flex gap-1"><Button variant="ghost" size="sm" disabled={index === 0} aria-label="Move earlier" onClick={() => section === "hero" ? update("hero", { slides: move(content.hero.slides, index, -1) }) : update("promotions", { cards: move(content.promotions.cards, index, -1) })}><ArrowUp size={16} /></Button><Button variant="ghost" size="sm" disabled={index === media.length - 1} aria-label="Move later" onClick={() => section === "hero" ? update("hero", { slides: move(content.hero.slides, index, 1) }) : update("promotions", { cards: move(content.promotions.cards, index, 1) })}><ArrowDown size={16} /></Button><Button variant="ghost" size="sm" aria-label="Remove item" onClick={() => section === "hero" ? update("hero", { slides: content.hero.slides.filter((slide) => slide.id !== item.id) }) : update("promotions", { cards: content.promotions.cards.filter((card) => card.id !== item.id) })}><Trash2 size={16} /></Button></div></div><MediaFields item={item} hero={section === "hero"} onChange={(patch) => updateSlide(section, item.id, patch)} onUpload={(file) => void uploadImage(section, item.id, file)} /></article>)}
        {!media.length && <p className={productPanel}>No items yet. Add one above, or hide this section.</p>}
      </section>}
      {section === "categories" && <section className={productPanel}><h2 className="mb-5 font-[inherit] text-xl font-semibold">Homepage categories</h2><div className="mb-5 grid gap-4 sm:grid-cols-2"><TextField label="Section title" value={content.categories.title} required onChange={(title) => update("categories", { title })} /><TextField label="Subtitle" value={content.categories.subtitle} onChange={(subtitle) => update("categories", { subtitle })} /></div><p className="mb-4 text-xs leading-6 text-brown">Select categories in the order you want them displayed. Inactive categories stay hidden.</p><ol className="mb-5 divide-y divide-cocoa/10 rounded-xl border border-cocoa/10 px-4">{content.categories.ids.map((id, index) => <li key={id} className="flex items-center justify-between gap-3 py-2"><span className="text-sm">{index + 1}. {categories.find((item) => item.id === id)?.name || "Category #" + id}</span><div className="flex gap-1"><Button size="sm" variant="ghost" disabled={index === 0} aria-label="Move category earlier" onClick={() => update("categories", { ids: move(content.categories.ids, index, -1) })}><ArrowUp size={15} /></Button><Button size="sm" variant="ghost" disabled={index === content.categories.ids.length - 1} aria-label="Move category later" onClick={() => update("categories", { ids: move(content.categories.ids, index, 1) })}><ArrowDown size={15} /></Button><Button size="sm" variant="ghost" aria-label="Remove category" onClick={() => update("categories", { ids: content.categories.ids.filter((value) => value !== id) })}><Trash2 size={15} /></Button></div></li>)}</ol><div className="grid max-h-96 gap-2 overflow-y-auto sm:grid-cols-2">{categories.map((item) => <label key={item.id} className="flex items-center gap-3 rounded-xl border border-cocoa/10 p-3 text-sm"><input type="checkbox" className="size-4 accent-olive" checked={content.categories.ids.includes(item.id)} disabled={!content.categories.ids.includes(item.id) && content.categories.ids.length >= 100} onChange={() => update("categories", { ids: content.categories.ids.includes(item.id) ? content.categories.ids.filter((id) => id !== item.id) : [...content.categories.ids, item.id] })} />{item.image_url && <img src={item.image_url} alt="" width={32} height={32} className="size-8 object-contain" />}{item.name}{item.is_active ? "" : " (inactive)"}</label>)}</div></section>}
      {(section === "featured" || section === "new_arrivals") && <section className={productPanel}><h2 className="mb-5 font-[inherit] text-xl font-semibold">{section === "featured" ? "Selected products" : "New arrivals"}</h2><TextField label="Section title" value={content[section].title} required onChange={(title) => update(section, { title })} />{section === "new_arrivals" && <div className="my-5 grid gap-4 sm:grid-cols-2"><Field label="Product source" name="arrival-mode"><select id="arrival-mode" value={content.new_arrivals.mode} className={productInput} onChange={(event) => update("new_arrivals", { mode: event.target.value as "latest" | "selected" })}><option value="latest">Newest published products automatically</option><option value="selected">Choose products manually</option></select></Field><Field label="Number of products" name="arrival-limit"><input id="arrival-limit" type="number" min={1} max={24} value={content.new_arrivals.limit || ""} className={productInput} onChange={(event) => update("new_arrivals", { limit: Number(event.target.value) })} /></Field></div>}{(section === "featured" || content.new_arrivals.mode === "selected") && <div className="mt-5"><FrontPageProductPicker ids={content[section].ids} products={knownProducts} onKnownProducts={rememberProducts} onChange={(ids) => update(section, { ids })} /></div>}</section>}
      {section === "benefits" && <section className={productPanel}><div className="mb-5 flex items-center justify-between"><h2 className="font-[inherit] text-xl font-semibold">Benefits</h2><Button variant="outline" disabled={content.benefits.items.length >= 8} onClick={() => update("benefits", { items: [...content.benefits.items, { id: crypto.randomUUID(), icon: "Heart", title: "New benefit", text: "" }] })}>Add benefit</Button></div><div className="grid gap-4 sm:grid-cols-2">{content.benefits.items.map((item) => <div key={item.id} className="flex flex-col gap-4 rounded-xl border border-cocoa/10 p-4"><TextField label="Title" value={item.title} required onChange={(title) => update("benefits", { items: content.benefits.items.map((entry) => entry.id === item.id ? { ...entry, title } : entry) })} /><TextField label="Description" value={item.text} onChange={(text) => update("benefits", { items: content.benefits.items.map((entry) => entry.id === item.id ? { ...entry, text } : entry) })} /><label className="text-sm">Icon<select className={productInput + " mt-2"} value={item.icon} onChange={(event) => update("benefits", { items: content.benefits.items.map((entry) => entry.id === item.id ? { ...entry, icon: event.target.value as typeof item.icon } : entry) })}>{["ShoppingBag", "PackageCheck", "Heart", "Search"].map((icon) => <option key={icon}>{icon}</option>)}</select></label><Button variant="ghost" onClick={() => update("benefits", { items: content.benefits.items.filter((entry) => entry.id !== item.id) })}>Remove benefit</Button></div>)}</div></section>}
      {section === "faq" && <section className={productPanel}><div className="mb-5 grid gap-4 sm:grid-cols-2"><TextField label="Section title" value={content.faq.title} required onChange={(title) => update("faq", { title })} /><TextField label="Description" value={content.faq.description} onChange={(description) => update("faq", { description })} /></div><div className="flex flex-col gap-4">{content.faq.items.map((item) => <div key={item.id} className="flex flex-col gap-4 rounded-xl border border-cocoa/10 p-4"><TextField label="Question" value={item.question} required onChange={(question) => update("faq", { items: content.faq.items.map((entry) => entry.id === item.id ? { ...entry, question } : entry) })} /><TextField label="Answer" value={item.answer} multiline required onChange={(answer) => update("faq", { items: content.faq.items.map((entry) => entry.id === item.id ? { ...entry, answer } : entry) })} /><Button variant="ghost" onClick={() => update("faq", { items: content.faq.items.filter((entry) => entry.id !== item.id) })}>Remove question</Button></div>)}</div><Button className="mt-5" variant="outline" disabled={content.faq.items.length >= 30} onClick={() => update("faq", { items: [...content.faq.items, { id: crypto.randomUUID(), question: "New question", answer: "" }] })}>Add question</Button></section>}
    </fieldset>
    {editable && <div className="flex justify-end"><Button type="submit" loading={saving} disabled={busy || !dirty} startIcon={<Save size={16} />}>Save front page</Button></div>}
  </form>;
}
