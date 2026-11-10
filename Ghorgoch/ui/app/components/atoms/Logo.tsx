import pageData from "@/data/header.json";

const content = pageData.logo;

export default function Logo() {
  return <span className="font-heading text-xl font-semibold text-cocoa">{content.spanText}</span>;
}
