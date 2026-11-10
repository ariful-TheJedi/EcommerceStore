import pageData from "@/data/messages.json";

import "server-only";

const content = pageData.request;

export class ServerApiError extends Error {
  constructor(public status: number, message: string) { super(message); }
}

export async function serverRequest<T>(path: string): Promise<T> {
  const backend = (process.env.LARAVEL_API_URL || process.env.API_URL || "http://localhost:8000")
    .replace(/\/$/, "").replace(/\/api$/, "");
  const response = await fetch(`${backend}/api${path}`, {
    headers: { Accept: "application/json" },
    cache: "no-store",
    signal: AbortSignal.timeout(10000),
  });
  if (!response.ok) { throw new ServerApiError(response.status, content.catalogServiceRequestFailedValue1.replace("{value1}", String(response.status))); }
  const payload: { data: T } = await response.json();
  return payload.data;
}
