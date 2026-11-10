import assert from "node:assert/strict";
import test from "node:test";
import { listingPath, loadCatalogTotals, taxonomyTotal, validateListing } from "./dashboard.ts";

function listing(kind, total) {
  return { [kind === "products" ? "products" : "items"]: [], pagination: { current_page: 1, last_page: Math.max(1, total), per_page: 1, total } };
}

test("catalog totals use API totals rather than the number of records on a page", async () => {
  const request = async (path) => {
    const kind = path.split("?")[0].slice(1);
    return listing(kind, { products: 147, categories: 24, tags: 39 }[kind]);
  };

  const totals = await loadCatalogTotals(request, new AbortController().signal);

  assert.equal(totals.products.value, 147);
  assert.equal(totals.categories.value, 24);
  assert.equal(totals.tags.value, 39);
  assert.equal(taxonomyTotal(totals), 63);
});

test("a forbidden taxonomy endpoint preserves other totals and leaves combined taxonomy unavailable", async () => {
  const request = async (path) => {
    if (path.startsWith("/tags?")) { throw new Error("You do not have permission."); }
    return listing(path.startsWith("/products?") ? "products" : "categories", 12);
  };

  const totals = await loadCatalogTotals(request, new AbortController().signal);

  assert.equal(totals.products.value, 12);
  assert.equal(totals.categories.value, 12);
  assert.equal(totals.tags.value, null);
  assert.equal(totals.tags.error, "You do not have permission.");
  assert.equal(taxonomyTotal(totals), null);
});

test("an empty catalog displays actual zero totals", async () => {
  const request = async (path) => listing(path.split("?")[0].slice(1), 0);

  const totals = await loadCatalogTotals(request, new AbortController().signal);

  assert.equal(totals.products.value, 0);
  assert.equal(taxonomyTotal(totals), 0);
  assert.equal(totals.tags.error, "");
});

test("malformed successful responses cannot become a false total", async () => {
  const request = async () => ({ items: [], pagination: { total: "12" } });

  const totals = await loadCatalogTotals(request, new AbortController().signal);

  assert.equal(totals.products.value, null);
  assert.equal(totals.categories.value, null);
  assert.match(totals.categories.error, /unexpected response/);
  assert.throws(() => validateListing(listing("tags", -1), "tags"), /unexpected response/);
});

test("product filters and pagination are encoded while taxonomy lists receive only supported parameters", () => {
  const filters = { search: "  tea & cups  ", status: "draft", taxonomy: { kind: "category", slug: "home-ware", name: "Home" } };

  const products = new URL(listingPath("products", 3, filters), "https://example.test");
  const categories = new URL(listingPath("categories", 2, filters), "https://example.test");

  assert.equal(products.searchParams.get("search"), "tea & cups");
  assert.equal(products.searchParams.get("status"), "draft");
  assert.equal(products.searchParams.get("category"), "home-ware");
  assert.equal(products.searchParams.get("page"), "3");
  assert.equal(products.searchParams.get("sort"), "-id");
  assert.deepEqual([...categories.searchParams], [["page", "2"], ["per_page", "10"]]);
});

test("tag selection filters by tag slug and an unfiltered summary never inherits product filters", () => {
  const filters = { search: "", status: "", taxonomy: { kind: "tag", slug: "new-arrivals", name: "New" } };

  const path = new URL(listingPath("products", 1, filters), "https://example.test");
  const summary = new URL(listingPath("products", 1, undefined, 1), "https://example.test");

  assert.equal(path.searchParams.get("tag"), "new-arrivals");
  assert.equal(path.searchParams.has("category"), false);
  assert.equal(summary.searchParams.has("tag"), false);
  assert.equal(summary.searchParams.has("status"), false);
  assert.equal(summary.searchParams.get("per_page"), "1");
});

test("request cancellation is forwarded to each summary request", async () => {
  const controller = new AbortController();
  controller.abort();
  const request = async (_path, options) => {
    assert.equal(options.signal, controller.signal);
    options.signal.throwIfAborted();
  };

  const totals = await loadCatalogTotals(request, controller.signal);

  assert.equal(totals.products.value, null);
  assert.equal(totals.categories.value, null);
  assert.equal(totals.tags.value, null);
});

test("store overview separates pending orders and loads the four newest categories across the last pages", async () => {
  const { loadStoreSummary } = await import("./dashboard.ts");
  const request = async (path) => {
    if (path.includes("/categories?page=")) {
      const page = Number(new URL(path, "https://example.test").searchParams.get("page"));
      const ids = page === 2 ? [5, 6, 7, 8] : [9];
      return { items: ids.map((id) => ({ id, name: "Category " + id, slug: "category-" + id })), pagination: { total: 9, current_page: page, last_page: 3, per_page: 4 } };
    }
    return { pagination: { total: path.includes("status=pending") ? 3 : path.startsWith("/categories?") ? 9 : 42 } };
  };

  const data = await loadStoreSummary(request, new AbortController().signal);

  assert.equal(data.orders.data, 42);
  assert.equal(data.newOrders.data, 3);
  assert.deepEqual(data.recentCategories.data.map((item) => item.id), [9, 8, 7, 6]);
});

test("a failed order summary leaves catalog totals available and an empty category list stays empty", async () => {
  const { loadStoreSummary } = await import("./dashboard.ts");
  const request = async (path) => {
    if (path.startsWith("/orders?")) { throw new Error("Permission denied"); }
    return { pagination: { total: path.startsWith("/categories?") ? 0 : 17 } };
  };

  const data = await loadStoreSummary(request, new AbortController().signal);

  assert.equal(data.orders.data, null);
  assert.equal(data.newOrders.error, "Permission denied");
  assert.equal(data.products.data, 17);
  assert.deepEqual(data.recentCategories.data, []);
});
