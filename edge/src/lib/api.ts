export async function api<T>(path: string, body?: unknown, method = "POST"): Promise<T> {
  const res = await fetch(`/api${path}`, {
    method: body ? "POST" : method,
    headers: body ? { "Content-Type": "application/json" } : undefined,
    body: body ? JSON.stringify(body) : undefined,
    credentials: "include",
  });
  const data = await res.json();
  if (!res.ok) throw new Error((data as { error?: string }).error ?? `Request failed (${res.status})`);
  return data as T;
}

export async function apiGet<T>(path: string): Promise<T> {
  const res = await fetch(`/api${path}`, { credentials: "include" });
  const data = await res.json();
  if (!res.ok) throw new Error((data as { error?: string }).error ?? `Request failed (${res.status})`);
  return data as T;
}
