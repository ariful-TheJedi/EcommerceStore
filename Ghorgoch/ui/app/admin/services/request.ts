import pageData from "@/data/admin/messages.json";

const content = pageData.request;

export class AdminApiError extends Error {
  constructor(public status: number, message: string, public errors: Record<string, string[]> = {}) { super(message); }
}

type RequestOptions = Omit<RequestInit, "body"> & { body?: object | FormData };

function responseMessage(status: number, path: string): string {
  if (status >= 500) { return content.serviceTemporarilyUnavailablePleaseTryAgain; }
  switch (status) {
    case 401: return path === "/auth/staff/login" ? content.theEmailOrPasswordIsIncorrect : content.yourSessionHasExpiredPleaseSign;
    case 403: return content.youDoNotHavePermissionToPerform;
    case 404: return content.theRequestedResourceWasNotFound;
    case 408: return content.theRequestTimedOutPleaseTry;
    case 409: return content.thisInformationHasChangedRefreshAnd;
    case 419: return content.yourSessionHasExpiredPleaseRefresh;
    case 422: return content.pleaseCheckTheInformationYouEntered;
    case 429: return content.tooManyRequestsPleaseWaitA;
    default: return content.theRequestCouldNotBeCompleted;
  }
}

export async function adminRequest<T>(path: string, options: RequestOptions = {}): Promise<T> {
  if (!path.startsWith("/") || path.startsWith("//")) { throw new Error(content.useARelativeAPIPathBeginning); }
  const method = (options.method || "GET").toUpperCase();
  const mutating = !["GET", "HEAD", "OPTIONS"].includes(method);

  async function send(retry: boolean): Promise<T> {
    const headers = new Headers(options.headers);
    headers.set("Accept", "application/json");
    if (mutating) {
      const { csrf_token } = await adminRequest<{ csrf_token: string }>("/auth/csrf-token", { signal: options.signal });
      headers.set("X-CSRF-TOKEN", csrf_token);
    }
    const isForm = options.body instanceof FormData;
    if (options.body && !isForm) { headers.set("Content-Type", "application/json"); }
    const response = await fetch(`/api${path}`, {
      ...options, method, headers,
      body: isForm ? options.body as FormData : options.body ? JSON.stringify(options.body) : undefined,
      credentials: "same-origin", cache: "no-store",
    });
    if (response.status === 419 && mutating && retry) { return send(false); }
    const payload = await response.json().catch(() => null);
    if (!response.ok) {
      const errors = payload?.errors || {};
      const validationMessage = response.status === 422 ? Object.values(errors).flat().join(" ") : "";
      throw new AdminApiError(response.status, validationMessage || responseMessage(response.status, path), errors);
    }
    return payload?.data as T;
  }
  try { return await send(true); }
  catch (failure) {
    if (failure instanceof AdminApiError) { throw failure; }
    if (failure instanceof Error && [content.timeoutError, content.abortError].includes(failure.name)) {
      throw new AdminApiError(408, content.theRequestTimedOutOrWas);
    }
    throw new AdminApiError(0, content.unableToConnectPleaseCheckYour);
  }
}
