"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";
import Button from "@/components/atoms/Button";
import { useAdminAuth } from "@/admin/services/useAdminAuth";

type Field = {
  key: string; label: string; type: string; required?: boolean; createOnly?: boolean;
  defaultValue?: string; maxLength?: number; minLength?: number; pattern?: string; hint?: string;
  options?: { value: string; label: string }[];
};
type Content = {
  title: string; description: string; add: string; edit: string; editTitle: string; save: string;
  saving: string; cancel: string; remove: string; restore: string; confirmRemove: string; confirm: string;
  loading: string; empty: string; search: string; searchAction: string; retry: string;
  previous: string; next: string; page: string; actions: string; saved: string; removed: string;
  restored: string; failed: string; active?: string; disabled?: string;
  columns: { key: string; label: string }[]; fields: Field[];
};
type RecordItem = { id: number; name: string; status?: string; is_active?: boolean } & Record<string, unknown>;
type Listing = { products?: RecordItem[]; staff?: RecordItem[]; pagination: { current_page: number; last_page: number } };
const inputClass = "min-h-11 w-full rounded-card border border-cocoa/20 bg-ivory px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-olive";

export default function AdminManager({ kind, content, initialCreate = false }: { kind: "products" | "users"; content: Content; initialCreate?: boolean }) {
  const { request } = useAdminAuth();
  const [listing, setListing] = useState<Listing | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [query, setQuery] = useState("");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [revision, setRevision] = useState(0);
  const [editor, setEditor] = useState<RecordItem | "new" | null>(initialCreate ? "new" : null);
  const [pending, setPending] = useState<RecordItem | null>(null);
  const [busy, setBusy] = useState(false);
  const locked = useRef(false);
  const editorHeading = useRef<HTMLHeadingElement>(null);
  const endpoint = `/${kind}`;

  useEffect(() => { if (editor) editorHeading.current?.focus(); }, [editor]);
  useEffect(() => {
    const controller = new AbortController();
    setLoading(true); setError("");
    const params = new URLSearchParams({ page: String(page), per_page: "20" });
    if (search) params.set("search", search);
    request<Listing>(`${endpoint}?${params}`, { signal: controller.signal }).then(data => {
      if (!controller.signal.aborted) {
        if (page > data.pagination.last_page) { setPage(Math.max(1, data.pagination.last_page)); return; }
        setListing(data);
      }
    }).catch(failure => {
      if (!controller.signal.aborted) { setError(failure instanceof Error ? failure.message : content.failed); setListing(null); }
    }).finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [request, endpoint, page, search, revision, content.failed]);

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!editor || locked.current) return;
    const form = new FormData(event.currentTarget);
    const body = Object.fromEntries(content.fields.filter(field => !field.createOnly || editor === "new").map(field => [field.key, String(form.get(field.key) ?? "")]));
    locked.current = true; setBusy(true); setError(""); setNotice("");
    try {
      await request(editor === "new" ? endpoint : `${endpoint}/${editor.id}`, { method: editor === "new" ? "POST" : "PATCH", body });
      setEditor(null); setNotice(content.saved); setRevision(value => value + 1);
    } catch (failure) { setError(failure instanceof Error ? failure.message : content.failed); }
    finally { locked.current = false; setBusy(false); }
  }

  async function changeStatus(item: RecordItem, restore: boolean) {
    if (locked.current) return;
    locked.current = true; setBusy(true); setError(""); setNotice("");
    try {
      const action = kind === "products" ? "archive" : restore ? "reactivate" : "disable";
      await request(kind === "products" && restore ? `${endpoint}/${item.id}` : `${endpoint}/${item.id}/${action}`, {
        method: kind === "products" && restore ? "PATCH" : "POST",
        ...(kind === "products" && restore ? { body: { status: "draft" } } : {}),
      });
      setPending(null); setNotice(restore ? content.restored : content.removed); setRevision(value => value + 1);
    } catch (failure) { setError(failure instanceof Error ? failure.message : content.failed); }
    finally { locked.current = false; setBusy(false); }
  }

  const items = listing?.products ?? listing?.staff ?? [];
  return <section aria-labelledby="management-title" className="flex flex-col gap-6">
    <div className="flex flex-wrap items-center justify-between gap-4">
      <div><h1 id="management-title" className="font-heading text-3xl">{content.title}</h1><p className="mt-2 text-sm text-brown">{content.description}</p></div>
      <Button disabled={busy} onClick={() => { setEditor("new"); setPending(null); setError(""); setNotice(""); }}>{content.add}</Button>
    </div>
    {error && <div role="alert" className="flex flex-wrap items-center gap-3 rounded-card border border-cocoa/20 p-4"><p>{error}</p>{!editor && !pending && <Button variant="outline" onClick={() => setRevision(value => value + 1)}>{content.retry}</Button>}</div>}
    {notice && <p role="status">{notice}</p>}
    {editor && <form onSubmit={save} className="flex flex-col gap-5 rounded-card border border-cocoa/20 p-5" aria-busy={busy}>
      <h2 ref={editorHeading} tabIndex={-1} className="font-heading text-xl">{editor === "new" ? content.add : content.editTitle}</h2>
      <fieldset disabled={busy} className="grid min-w-0 gap-4 border-0 p-0 sm:grid-cols-2" key={editor === "new" ? "new" : editor.id}>
        {content.fields.filter(field => !field.createOnly || editor === "new").map(field => {
          const id = `${kind}-${field.key}`;
          const value = editor === "new" ? field.defaultValue ?? "" : String(editor[field.key] ?? "");
          return <div key={field.key} className={`flex min-w-0 flex-col gap-2 ${field.type === "textarea" ? "sm:col-span-2" : ""}`}>
            <label htmlFor={id} className="text-sm font-medium">{field.label}</label>
            {field.type === "select" ? <select id={id} name={field.key} defaultValue={value} className={inputClass}>{field.options?.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}</select>
              : field.type === "textarea" ? <textarea id={id} name={field.key} defaultValue={value} maxLength={field.maxLength} rows={4} className={inputClass} />
              : <input id={id} name={field.key} type={field.type} defaultValue={value} required={field.required} maxLength={field.maxLength} minLength={field.minLength} pattern={field.pattern} autoComplete={field.type === "password" ? "new-password" : undefined} aria-describedby={field.hint ? `${id}-hint` : undefined} className={inputClass} />}
            {field.hint && <p id={`${id}-hint`} className="text-xs text-brown">{field.hint}</p>}
          </div>;
        })}
      </fieldset>
      <div className="flex gap-3"><Button type="submit" loading={busy} loadingLabel={content.saving}>{content.save}</Button><Button variant="outline" disabled={busy} onClick={() => setEditor(null)}>{content.cancel}</Button></div>
    </form>}
    {pending && <div role="alert" className="flex flex-col gap-3 rounded-card border border-cocoa/20 p-5"><p className="font-medium">{pending.name}</p><p>{content.confirmRemove}</p><div className="flex gap-3"><Button loading={busy} onClick={() => void changeStatus(pending, false)}>{content.confirm}</Button><Button variant="outline" disabled={busy} onClick={() => setPending(null)}>{content.cancel}</Button></div></div>}
    <form role="search" onSubmit={event => { event.preventDefault(); setSearch(query.trim()); setPage(1); }} className="flex flex-wrap items-end gap-3">
      <div className="flex min-w-0 flex-1 flex-col gap-2"><label htmlFor={`${kind}-search`} className="text-sm">{content.search}</label><input id={`${kind}-search`} value={query} onChange={event => setQuery(event.target.value)} maxLength={150} type="search" className={inputClass} /></div>
      <Button type="submit" variant="outline" disabled={busy}>{content.searchAction}</Button>
    </form>
    {loading ? <p role="status">{content.loading}</p> : listing && <>
      {!items.length ? <p>{content.empty}</p> : <div className="overflow-x-auto rounded-card border border-cocoa/10"><table className="w-full text-left text-sm"><caption className="sr-only">{content.title}</caption>
        <thead className="bg-rose/20"><tr>{content.columns.map(column => <th key={column.key} scope="col" className="px-4 py-3">{column.label}</th>)}<th scope="col" className="px-4 py-3">{content.actions}</th></tr></thead>
        <tbody>{items.map(item => <tr key={item.id} className="border-t border-cocoa/10">
          {content.columns.map(column => <td key={column.key} className="px-4 py-3">{column.key === "is_active" ? item.is_active ? content.active : content.disabled : String(item[column.key] ?? "")}</td>)}
          <td className="px-4 py-3"><div className="flex gap-2"><Button size="sm" variant="outline" disabled={busy} onClick={() => { setEditor(item); setPending(null); setError(""); }}>{content.edit}</Button>
            {(kind === "products" ? item.status === "archived" : !item.is_active) ? <Button size="sm" variant="ghost" disabled={busy} onClick={() => void changeStatus(item, true)}>{content.restore}</Button> : <Button size="sm" variant="ghost" disabled={busy} onClick={() => { setPending(item); setEditor(null); }}>{content.remove}</Button>}
          </div></td>
        </tr>)}</tbody>
      </table></div>}
      <nav aria-label={content.page} className="flex flex-wrap items-center justify-between gap-3"><Button variant="outline" disabled={busy || page <= 1} onClick={() => setPage(value => value - 1)}>{content.previous}</Button><span className="text-sm">{content.page} {page} / {listing.pagination.last_page}</span><Button variant="outline" disabled={busy || page >= listing.pagination.last_page} onClick={() => setPage(value => value + 1)}>{content.next}</Button></nav>
    </>}
  </section>;
}
