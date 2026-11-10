import CategoryProducts from "@/components/sections/CategoryProducts";
import { notFound } from "next/navigation";
import { getCategories, getCategory } from "@/services/categories";
import { ServerApiError } from "@/services/request";

export const dynamic = "force-dynamic";

export default async function CategoryPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  let category;
  try { category = await getCategory(slug); }
  catch (failure) {
    if (failure instanceof ServerApiError && failure.status === 404) { notFound(); }
    throw failure;
  }
  let categories: Awaited<ReturnType<typeof getCategories>> = [];
  try { categories = await getCategories(); } catch { categories = []; }
  return <CategoryProducts key={slug} category={category} categories={categories} />;
}
