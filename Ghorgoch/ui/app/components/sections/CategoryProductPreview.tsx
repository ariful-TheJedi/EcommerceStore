import { getProducts } from "@/services/products";
import CategoryProductSlider from "./CategoryProductSlider";

export default async function CategoryProductPreview({ slug, name }: { slug: string; name: string }) {
  try {
    const { products } = await getProducts({ category: slug, limit: 6, sort: "-created_at" });
    return products.length ? <CategoryProductSlider products={products} categoryName={name} /> : <p className="rounded-xl bg-rose/15 px-5 py-8 text-center text-sm text-brown">এই ক্যাটাগরিতে নতুন পণ্য শীঘ্রই আসছে।</p>;
  } catch {
    return <p role="alert" className="rounded-xl bg-rose/15 px-5 py-8 text-center text-sm text-brown">পণ্য লোড করা যায়নি। সব পণ্য দেখতে নিচের বাটনে চাপুন।</p>;
  }
}