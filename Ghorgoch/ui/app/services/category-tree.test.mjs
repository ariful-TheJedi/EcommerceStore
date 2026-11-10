import assert from "node:assert/strict";
import test from "node:test";
import { categoryAncestors } from "./category-tree.ts";

test("breadcrumbs follow actual parents from root to the selected category", () => {
  const root = { id: 1, parent_id: null, name: "Home" };
  const parent = { id: 2, parent_id: 1, name: "Kitchen" };
  const child = { id: 3, parent_id: 2, name: "Cups" };
  assert.deepEqual(categoryAncestors(child, [child, root, parent]), [root, parent]);
});

test("independent categories have no parent breadcrumbs", () => {
  const category = { id: 1, parent_id: null };
  assert.deepEqual(categoryAncestors(category, [category]), []);
});

test("missing or inactive parents stop the breadcrumb chain without crashing", () => {
  const parent = { id: 2, parent_id: 1 };
  const category = { id: 3, parent_id: 2 };
  assert.deepEqual(categoryAncestors(category, [category, parent]), [parent]);
});

test("malformed parent cycles cannot loop or repeat the current category", () => {
  const category = { id: 1, parent_id: 2 };
  const parent = { id: 2, parent_id: 1 };
  assert.deepEqual(categoryAncestors(category, [category, parent]), [parent]);
});
