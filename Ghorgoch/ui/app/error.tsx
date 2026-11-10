"use client";

import pageData from "@/data/messages.json";

import { useEffect } from "react";

const content = pageData.error;

export default function ErrorPage({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
  useEffect(() => { console.error(error); }, [error]);

  return <main className="page-container flex min-h-dvh flex-col items-start justify-center gap-4"><h1>{content.h1Text}</h1><p>{content.pText}</p><button className="button button-primary" type="button" onClick={reset}>{content.buttonText}</button></main>;
}
