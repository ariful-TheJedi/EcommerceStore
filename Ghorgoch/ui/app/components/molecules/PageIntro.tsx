type PageIntroProps = {
  eyebrow?: string;
  title: string;
  description: string;
};

export default function PageIntro({ eyebrow, title, description }: PageIntroProps) {
  return (
    <main id="main-content" className="page-container py-10 md:py-16">
      {eyebrow && <p className="store-eyebrow">{eyebrow}</p>}
      <h1 className="mt-2">{title}</h1>
      <p className="mt-4 max-w-2xl">{description}</p>
    </main>
  );
}
