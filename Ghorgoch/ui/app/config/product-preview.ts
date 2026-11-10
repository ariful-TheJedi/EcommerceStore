import pageData from "@/data/products.json";

const content = pageData.preview;

export const productPreview = content.productPreview as Omit<typeof content.productPreview, "attributeLabels" | "variants"> & { attributeLabels: Record<string, string>; variants: { id: number; sku: string; attributes: Record<string, string>; effective_price: number; available: boolean; is_active: boolean; available_stock: number }[] };
export const relatedProductPreviews = content.relatedProductPreviews;
