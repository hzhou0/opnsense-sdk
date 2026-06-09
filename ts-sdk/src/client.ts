import createClient, { type Client, type ClientOptions } from "openapi-fetch";
import type { paths } from "./generated/schema.js";

export interface OpnsenseClientOptions extends Omit<ClientOptions, "baseUrl"> {
  /** Base URL of the OPNsense host, e.g. "https://192.168.1.1". */
  baseUrl: string;
  /** API key — sent as the HTTP Basic username. */
  apiKey?: string;
  /** API secret — sent as the HTTP Basic password. */
  apiSecret?: string;
}

/**
 * Create a fully typed OPNsense API client.
 *
 * Every path, method, path parameter, request body and response is typed from
 * the introspected OpenAPI spec. OPNsense authenticates API calls with an API
 * key/secret pair sent as HTTP Basic credentials (key = username, secret =
 * password); pass them here and they are attached to every request.
 *
 * @example
 * const api = createOpnsenseClient({
 *   baseUrl: "https://192.168.1.1",
 *   apiKey: process.env.OPNSENSE_KEY,
 *   apiSecret: process.env.OPNSENSE_SECRET,
 * });
 * const { data, error } = await api.GET("/api/auth/group/get/{uuid}", {
 *   params: { path: { uuid: "..." } },
 * });
 */
export function createOpnsenseClient(
  options: OpnsenseClientOptions,
): Client<paths> {
  const { apiKey, apiSecret, headers, ...rest } = options;

  const authHeaders: Record<string, string> = {};
  if (apiKey && apiSecret) {
    const basic = `${apiKey}:${apiSecret}`;
    const encoded =
      typeof btoa === "function"
        ? btoa(basic)
        : Buffer.from(basic, "utf-8").toString("base64");
    authHeaders.Authorization = `Basic ${encoded}`;
  }

  return createClient<paths>({
    ...rest,
    headers: { ...authHeaders, ...headers },
  });
}