import assert from "node:assert/strict";
import test from "node:test";
import { productBody, variantBody, imageBody, imageUploadBody, loadTaxonomy, localDate, priceLabel, taxonomyBody, uploadProductImages } from "./products.ts";

function form(fields) {
  const data = new FormData();
  for (const [key, value] of Object.entries(fields)) {
    for (const item of Array.isArray(value) ? value : [value]) { data.append(key, String(item)); }
  }
  return data;
}

test("category CMS updates use hierarchy, visibility, description, and numeric display order", () => {
  const body = taxonomyBody(form({ name: " Cups ", slug: "cups", parent_id: "8", description: "Everyday cups", sort_order: "3", is_active: "on" }), "categories");

  assert.deepEqual(body, { name: "Cups", slug: "cups", description: "Everyday cups", parent_id: 8, is_active: true, sort_order: 3 });
});

test("category CMS can move a term to root, clear its description, and deactivate it", () => {
  const body = taxonomyBody(form({ name: "Cups", slug: "cups", parent_id: "", description: "", sort_order: "0" }), "categories");

  assert.deepEqual(body, { name: "Cups", slug: "cups", description: null, parent_id: null, is_active: false, sort_order: 0 });
});

test("tag writes exclude category-only fields rejected by the API", () => {
  const body = taxonomyBody(form({ name: " New ", slug: "new", parent_id: "8", is_active: "on", description: "ignored", sort_order: "3" }), "tags");

  assert.deepEqual(body, { name: "New", slug: "new" });
});

test("product updates use numeric taxonomy IDs and support clearing assignments", () => {
  const selected = productBody(form({ name: " Cup ", slug: "cup", status: "draft", category_ids: [2, 7], tag_ids: [4] }), true);
  const cleared = productBody(form({ name: "Cup", slug: "cup", status: "draft" }), true);

  assert.deepEqual(selected, { name: "Cup", slug: "cup", description: null, status: "draft", is_new: false, category_ids: [2, 7], tag_ids: [4] });
  assert.deepEqual(cleared.category_ids, []);
  assert.deepEqual(cleared.tag_ids, []);
});

test("failed taxonomy loading does not clear existing memberships on product save", () => {
  const body = productBody(form({ name: "Cup", slug: "cup", status: "published" }), false);

  assert.equal("category_ids" in body, false);
  assert.equal("tag_ids" in body, false);
});

test("the new pill checkbox sends both enabling and removal", () => {
  assert.equal(productBody(form({ is_new: "on" }), false).is_new, true);
  assert.equal(productBody(form({}), false).is_new, false);
});

test("variant payload keeps monetary precision, converts checkboxes, and excludes inventory writes", () => {
  const body = variantBody(form({ sku: " cup-green ", price: "499.99", sale_price: "450.00", is_active: "on", weight_grams: "250", low_stock_threshold: "5", stock_quantity: "999" }), [{ name: "Color", value: "Green" }]);

  assert.equal(body.sku, "CUP-GREEN");
  assert.equal(body.price, "499.99");
  assert.equal(body.sale_price, "450.00");
  assert.equal(body.weight_grams, 250);
  assert.equal(body.is_active, true);
  assert.equal(body.allow_backorders, false);
  assert.equal(body.stock_quantity, undefined);
  assert.deepEqual({ ...body.attributes }, { Color: "Green" });
});

test("variant updates explicitly clear optional fields and use an empty object for simple products", () => {
  const body = variantBody(form({ sku: "CUP", price: "100" }), []);

  assert.equal(body.sale_price, null);
  assert.equal(body.cost_price, null);
  assert.equal(body.weight_grams, null);
  assert.equal(body.sale_starts_at, null);
  assert.equal(body.sale_ends_at, null);
  assert.equal(JSON.stringify(body.attributes), "{}");
});

test("variant options reject duplicate names and incomplete values", () => {
  const data = form({ sku: "CUP", price: "100" });

  assert.throws(() => variantBody(data, [{ name: "Color", value: "Red" }, { name: " color ", value: "Blue" }]), /unique/);
  assert.throws(() => variantBody(data, [{ name: "Size", value: "" }]), /both/);
});

test("sale dates round trip through browser local time without shifting the instant", () => {
  const instant = "2026-11-01T10:30:25.000Z";
  const date = localDate(instant);
  const body = variantBody(form({ sku: "CUP", price: "100", sale_starts_at: date }), []);

  assert.equal(body.sale_starts_at, instant);
  assert.equal(localDate(null), "");
});

test("image metadata can clear variant assignment and unset primary status", () => {
  const cleared = imageBody(form({ alt_text: "", variant_id: "", sort_order: "2" }));
  const assigned = imageBody(form({ alt_text: "Green cup", variant_id: "12", sort_order: "0", is_primary: "on" }));

  assert.deepEqual(cleared, { alt_text: null, variant_id: null, sort_order: 2, is_primary: false });
  assert.deepEqual(assigned, { alt_text: "Green cup", variant_id: 12, sort_order: 0, is_primary: true });
});

test("multipart image uploads preserve the file and send API-compatible boolean values", () => {
  const data = form({ alt_text: "Cup", variant_id: "", sort_order: "0" });
  data.set("image", new File(["image content"], "cup.png", { type: "image/png" }));

  const body = imageUploadBody(data);

  assert.equal(body.get("is_primary"), "0");
  assert.equal(body.has("variant_id"), false);
  assert.equal(body.get("image").name, "cup.png");
  assert.equal(imageUploadBody(form({ is_primary: "on", variant_id: "8" })).get("is_primary"), "1");
});

test("taxonomy selection includes options from every page and forwards cancellation", async () => {
  const signal = new AbortController().signal;
  const request = async (path, options) => {
    assert.equal(options.signal, signal);
    const page = Number(new URL(path, "https://example.test").searchParams.get("page"));
    return { items: [{ id: page, name: "Category " + page }], pagination: { last_page: 3 } };
  };

  const items = await loadTaxonomy(request, "categories", signal);

  assert.deepEqual(items.map((item) => item.id), [1, 2, 3]);
});

test("taxonomy failures are surfaced instead of returning incomplete options", async () => {
  const request = async (path) => {
    if (path.endsWith("page=2")) { throw new Error("Permission denied"); }
    return { items: [{ id: 1 }], pagination: { last_page: 2 } };
  };

  await assert.rejects(loadTaxonomy(request, "tags", new AbortController().signal), /Permission denied/);
});

test("list prices use current active variant prices and exclude disabled variants", () => {
  const product = { currency: "BDT", variants: [{ is_active: true, effective_price: "100" }, { is_active: true, effective_price: "200" }, { is_active: false, effective_price: "1" }] };

  const label = priceLabel(product);

  assert.match(label, /100/);
  assert.match(label, /200/);
  assert.equal(priceLabel({ currency: "BDT", variants: [] }), "No active variants");
});


test("multiple image uploads preserve display order, alt text, primary choice, and variant assignment", async () => {
  const calls = [];
  const progress = [];
  const images = [
    { key: "side", file: new File(["side"], "side.png", { type: "image/png" }), altText: "Side view", sortOrder: 4, primary: false, variantId: 12 },
    { key: "front", file: new File(["front"], "front.jpg", { type: "image/jpeg" }), altText: "Front view", sortOrder: 5, primary: true, variantId: null },
  ];
  const request = async (path, options) => {
    calls.push({ path, options });
    return { image: { id: calls.length, sort_order: Number(options.body.get("sort_order")), is_primary: options.body.get("is_primary") === "1" } };
  };

  const result = await uploadProductImages(request, 42, images, (count) => progress.push(count));

  assert.equal(result.failure, null);
  assert.deepEqual(result.uploaded.map((entry) => entry.key), ["side", "front"]);
  assert.deepEqual(progress, [1, 2]);
  assert.deepEqual(calls.map((call) => call.path), ["/products/42/images", "/products/42/images"]);
  assert.equal(calls[0].options.method, "POST");
  assert.equal(calls[0].options.body.get("image").name, "side.png");
  assert.equal(calls[0].options.body.get("sort_order"), "4");
  assert.equal(calls[0].options.body.get("alt_text"), "Side view");
  assert.equal(calls[0].options.body.get("variant_id"), "12");
  assert.equal(calls[0].options.body.get("is_primary"), "0");
  assert.equal(calls[1].options.body.get("sort_order"), "5");
  assert.equal(calls[1].options.body.get("is_primary"), "1");
  assert.equal(calls[1].options.body.has("variant_id"), false);
});

test("a failed batch retains successful uploads and stops so remaining images can be retried", async () => {
  const images = ["one", "two", "three"].map((key, index) => ({ key, file: new File([key], key + ".png"), altText: "", sortOrder: index, primary: false, variantId: null }));
  const calls = [];
  const request = async (_path, options) => {
    const name = options.body.get("image").name;
    calls.push(name);
    if (name === "two.png") { throw new Error("Storage unavailable"); }
    return { image: { id: 1 } };
  };
  const first = await uploadProductImages(request, 9, images, () => {});
  assert.deepEqual(first.uploaded.map((item) => item.key), ["one"]);
  assert.deepEqual(first.failure, { key: "two", message: "Storage unavailable" });
  assert.deepEqual(calls, ["one.png", "two.png"]);

  const remaining = images.filter((item) => !first.uploaded.some((saved) => saved.key === item.key));
  const retries = [];
  const second = await uploadProductImages(async (_path, options) => { retries.push(options.body.get("image").name); return { image: { id: retries.length + 1 } }; }, 9, remaining, () => {});
  assert.equal(second.failure, null);
  assert.deepEqual(retries, ["two.png", "three.png"]);
});
