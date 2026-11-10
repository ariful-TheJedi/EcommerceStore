import assert from "node:assert/strict";
import test from "node:test";
import { chooseProductVariant, initialProductVariant, lowestPurchasableVariant, productGalleryImages } from "./product-variants.ts";

const variant = (id, attributes, overrides = {}) => ({ id, attributes, is_active: true, available: true, images: [], ...overrides });

test("cards select the cheapest available variant instead of cheaper unavailable stock", () => {
  const unavailable = variant(1, {}, { effective_price: "10.00", available: false });
  const expensive = variant(2, {}, { effective_price: "50.00" });
  const available = variant(3, {}, { effective_price: "30.00" });
  const inactive = variant(4, {}, { effective_price: "5.00", is_active: false });
  const variants = [unavailable, expensive, available, inactive];
  assert.equal(lowestPurchasableVariant({ variants }), available);
  assert.deepEqual(variants.map((item) => item.id), [1, 2, 3, 4]);
});

test("cards retain a display price for sold-out products without enabling unavailable stock", () => {
  const unavailable = variant(1, {}, { effective_price: "10.00", available: false });
  assert.equal(lowestPurchasableVariant({ variants: [unavailable] }), unavailable);
  assert.equal(lowestPurchasableVariant({ variants: [{ ...unavailable, is_active: false }] }), undefined);
  assert.equal(lowestPurchasableVariant({ variants: [] }), undefined);
});

test("initial selection prefers an available active variant and safely handles products without variants", () => {
  const unavailable = variant(1, {}, { available: false });
  const inactive = variant(2, {}, { is_active: false });
  const available = variant(3, {});
  assert.equal(initialProductVariant({ variants: [unavailable, inactive, available] }), available);
  assert.equal(initialProductVariant({ variants: [unavailable] }), unavailable);
  assert.equal(initialProductVariant({ variants: [inactive] }), undefined);
  assert.equal(initialProductVariant({ variants: [] }), undefined);
});

test("changing an attribute preserves the other selected options when the combination exists", () => {
  const current = variant(1, { color: "Red", size: "Small" });
  const wrongSize = variant(2, { color: "Blue", size: "Large" });
  const matching = variant(3, { color: "Blue", size: "Small" });
  assert.equal(chooseProductVariant([current, wrongSize, matching], current, "color", "Blue"), matching);
});

test("missing combinations fall back to an available candidate without selecting disabled variants", () => {
  const current = variant(1, { color: "Red", size: "Small" });
  const inactive = variant(2, { color: "Blue", size: "Small" }, { is_active: false });
  const unavailable = variant(3, { color: "Blue", size: "Medium" }, { available: false });
  const available = variant(4, { color: "Blue", size: "Large" });
  assert.equal(chooseProductVariant([current, inactive, unavailable, available], current, "color", "Blue"), available);
  assert.equal(chooseProductVariant([current], current, "color", "Unknown"), undefined);
});

test("the gallery uses selected variant images in API order and preserves alternative text", () => {
  const selected = variant(2, {}, { images: [{ url: "/front.png", alt_text: "Front", variant_id: 2 }, { url: "/side.png", alt_text: null, variant_id: 2 }] });
  const product = { name: "Actual product", images: [{ url: "/other.png", alt_text: "Other", variant_id: 3 }] };
  assert.deepEqual(productGalleryImages(product, selected), [{ src: "/front.png", alt: "Front" }, { src: "/side.png", alt: "Actual product" }]);
});

test("the gallery falls back to general product images and handles empty galleries", () => {
  const general = { url: "/general.png", alt_text: null, variant_id: null };
  const specific = { url: "/specific.png", alt_text: null, variant_id: 3 };
  assert.deepEqual(productGalleryImages({ name: "Product", images: [specific, general] }, variant(1, {})), [{ src: "/general.png", alt: "Product" }]);
  assert.deepEqual(productGalleryImages({ name: "Product", images: [] }), []);
});
