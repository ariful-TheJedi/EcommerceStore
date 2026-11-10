import type { adminRequest } from "./request";

export type CatalogKind = "products" | "categories" | "tags";
export type Pagination = { current_page: number; per_page: number; total: number; last_page: number };
export type TaxonomyItem = { id: number; name: string; slug: string; image_url?: string | null; is_taxonomy?: boolean; parent_id?: number | null; is_active?: boolean };
export type DashboardProduct = {
  id: number; name: string; slug: string; status: "draft" | "published" | "archived";
  categories: TaxonomyItem[]; tags: TaxonomyItem[]; variants: { id: number }[];
};
export type CatalogListing = { products?: DashboardProduct[]; items?: TaxonomyItem[]; pagination: Pagination };
export type Total = { value: number | null; error: string };
export type CatalogTotals = Record<CatalogKind, Total>;
export type ProductFilters = { search: string; status: string; taxonomy?: { kind: "category" | "tag"; slug: string; name: string } };

export function listingPath(kind: CatalogKind, page: number, filters?: ProductFilters, perPage = 10): string {
  const params = new URLSearchParams({ page: String(page), per_page: String(perPage) });
  if (kind === "products") {
    params.set("sort", "-id");
    if (filters?.search.trim()) { params.set("search", filters.search.trim()); }
    if (filters?.status) { params.set("status", filters.status); }
    if (filters?.taxonomy) { params.set(filters.taxonomy.kind, filters.taxonomy.slug); }
  }
  return `/${kind}?${params}`;
}

export function validateListing(data: CatalogListing, kind: CatalogKind): CatalogListing {
  const page = data?.pagination;
  if (!page || !Number.isSafeInteger(page.total) || page.total < 0
    || !Number.isSafeInteger(page.current_page) || page.current_page < 1
    || !Number.isSafeInteger(page.last_page) || page.last_page < 1
    || !Number.isSafeInteger(page.per_page) || page.per_page < 1
    || !Array.isArray(kind === "products" ? data.products : data.items)) {
    throw new Error("The catalog returned an unexpected response. Please retry.");
  }
  return data;
}

export async function loadCatalogTotals(request: typeof adminRequest, signal: AbortSignal): Promise<CatalogTotals> {
  const kinds = ["products", "categories", "tags"] as const;
  const results = await Promise.allSettled(kinds.map(async (kind) => {
    const data = validateListing(await request<CatalogListing>(listingPath(kind, 1, undefined, 1), { signal }), kind);
    return data.pagination.total;
  }));
  return Object.fromEntries(kinds.map((kind, index) => {
    const result = results[index];
    return [kind, result.status === "fulfilled"
      ? { value: result.value, error: "" }
      : { value: null, error: result.reason instanceof Error ? result.reason.message : "Unable to load this total." }];
  })) as CatalogTotals;
}

export function taxonomyTotal(totals: CatalogTotals): number | null {
  return totals.categories.value === null || totals.tags.value === null
    ? null : totals.categories.value + totals.tags.value;
}

export type SummaryResult<T> = { data: T | null; error: string };
export type StoreSummary = {
  products: SummaryResult<number>; categories: SummaryResult<number>; tags: SummaryResult<number>;
  orders: SummaryResult<number>; newOrders: SummaryResult<number>; customers: SummaryResult<number>;
  recentCategories: SummaryResult<TaxonomyItem[]>;
};

export async function loadStoreSummary(request: typeof adminRequest, signal: AbortSignal): Promise<StoreSummary> {
  async function result<T>(work: () => Promise<T>): Promise<SummaryResult<T>> {
    try { return { data: await work(), error: "" }; }
    catch (failure) { return { data: null, error: failure instanceof Error ? failure.message : "Unable to load this summary." }; }
  }
  async function total(path: string): Promise<number> {
    const data = await request<{ pagination: Pagination }>(path, { signal });
    if (!Number.isSafeInteger(data?.pagination?.total) || data.pagination.total < 0) { throw new Error("Unable to read this summary."); }
    return data.pagination.total;
  }
  const categoryCount = result(() => total("/categories?per_page=1"));
  const recentCategories = result(async () => {
    const count = await categoryCount;
    if (count.data === null) { throw new Error(count.error); }
    if (count.data === 0) { return []; }
    const lastPage = Math.ceil(count.data / 4);
    const pages = lastPage > 1 ? [lastPage - 1, lastPage] : [1];
    const listings = await Promise.all(pages.map(async (page) => {
      const data = await request<CatalogListing>(`/categories?page=${page}&per_page=4`, { signal });
      return validateListing(data, "categories").items as TaxonomyItem[];
    }));
    return listings.flat().sort((a, b) => b.id - a.id).slice(0, 4);
  });
  const [products, categories, tags, orders, newOrders, customers, latest] = await Promise.all([
    result(() => total("/products?per_page=1")), categoryCount,
    result(() => total("/tags?per_page=1")), result(() => total("/orders?per_page=1")),
    result(() => total("/orders?per_page=1&status=pending")), result(() => total("/customers?per_page=1")), recentCategories,
  ]);
  return { products, categories, tags, orders, newOrders, customers, recentCategories: latest };
}
