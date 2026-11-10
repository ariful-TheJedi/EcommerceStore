import pageData from "@/data/messages.json";

import NotFoundPage from "@/components/sections/NotFoundPage";

const content = pageData.productNotFound;

export default function ProductNotFound() {
  return <NotFoundPage title={content.title} message="পণ্যটি সরিয়ে নেওয়া হয়েছে অথবা ঠিকানাটি সঠিক নয়। আমাদের সংগ্রহ থেকে অন্য কোনো পছন্দের পণ্য বেছে নিন।" />;
}
