import ProductDetails from "@/components/sections/ProductDetails";
import { notFound } from "next/navigation";
import { getProduct } from "@/services/products";
import { ServerApiError } from "@/services/request";
export const dynamic = "force-dynamic";
export default async function ProductPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  if (!/^[1-9]\d*$/.test(id)) { notFound(); }
  let product;
  try { product = await getProduct(id); }
  catch (failure) {
    if (failure instanceof ServerApiError && failure.status === 404) { notFound(); }
    throw failure;
  }
  return <ProductDetails key={id} product={product} />;
}
