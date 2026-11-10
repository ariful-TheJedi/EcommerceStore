import type { Product, Variant } from "../components/catalog";

export function lowestPurchasableVariant(product: Product): Variant | undefined {
  const active = product.variants.filter((variant) => variant.is_active);
  const available = active.filter((variant) => variant.available);
  return (available.length ? available : active).sort((a, b) => Number(a.effective_price) - Number(b.effective_price))[0];
}

export function initialProductVariant(product: Product): Variant | undefined {
  return product.variants.find((variant) => variant.is_active && variant.available) || product.variants.find((variant) => variant.is_active);
}

export function chooseProductVariant(variants: Variant[], current: Variant, name: string, value: string): Variant | undefined {
  const candidates = variants.filter((variant) => variant.is_active && variant.attributes[name] === value);
  return candidates.find((variant) => Object.entries(current.attributes).every(([key, selected]) => key === name || variant.attributes[key] === selected))
    || candidates.find((variant) => variant.available) || candidates[0];
}

export function productGalleryImages(product: Product, variant?: Variant) {
  const images = variant?.images.length ? variant.images : product.images.filter((image) => image.variant_id === null);
  return (images.length ? images : product.images).map((image) => ({ src: image.url, alt: image.alt_text || product.name }));
}
