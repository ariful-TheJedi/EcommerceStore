import CheckoutForm from "@/components/sections/CheckoutForm";

export const metadata = { title: "চেকআউট | ঘরগোছ" };

export default function CheckoutPage() {
  return <main id="main-content" tabIndex={-1} lang="bn" className="page-container py-8 pb-16"><CheckoutForm /></main>;
}