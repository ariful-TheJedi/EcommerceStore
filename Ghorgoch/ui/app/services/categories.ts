import "server-only";
import { serverRequest } from "./request";

export type CatalogCategory = {
  id: number;
  name: string;
  slug: string;
  parent_id: number | null;
  description: string | null;
  image_url: string | null;
};

export async function getCategories(): Promise<CatalogCategory[]> {
  const { items } = await serverRequest<{ items: CatalogCategory[] }>("/catalog/categories");
  return items;
}

export async function getCategory(slug: string): Promise<CatalogCategory> {
  const { item } = await serverRequest<{ item: CatalogCategory }>(`/catalog/categories/${encodeURIComponent(slug)}`);
  return item;
}
