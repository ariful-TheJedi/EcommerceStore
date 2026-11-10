import type { CatalogCategory } from "./categories";

export function categoryAncestors(category: CatalogCategory, categories: CatalogCategory[]): CatalogCategory[] {
  const byId = new Map(categories.map((item) => [item.id, item]));
  const ancestors: CatalogCategory[] = [];
  const visited = new Set([category.id]);
  let parentId = category.parent_id;
  while (parentId !== null && !visited.has(parentId)) {
    const parent = byId.get(parentId);
    if (!parent) { break; }
    visited.add(parent.id); ancestors.unshift(parent); parentId = parent.parent_id;
  }
  return ancestors;
}
