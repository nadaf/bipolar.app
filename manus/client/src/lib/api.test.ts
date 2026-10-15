import { beforeEach, afterEach, describe, it, expect, vi } from "vitest";
import { emptyEntry } from "./diary";
beforeEach(() => {
  vi.resetModules();
  vi.stubGlobal("window", {
    BIPOLAR_CONFIG: { serverEnabled: true, apiBase: "/api/index.php" },
  });
});
afterEach(() => vi.unstubAllGlobals());
describe("Contrato PHP", () => {
  it("obtém token de sessão e não envia UUID local como edição de registro novo", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          user: null,
          csrf: "csrf-test",
          providers: { google: false, x: false, microsoft: false },
        }),
      })
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({ entry: emptyEntry() }),
      });
    vi.stubGlobal("fetch", fetchMock);
    const api = await import("./api");
    await api.getSession();
    await api.saveEntry(emptyEntry(), false);
    const init = fetchMock.mock.calls[1][1];
    const body = JSON.parse(init.body);
    expect(body.id).toBeUndefined();
    expect(init.headers["X-CSRF-Token"]).toBe("csrf-test");
    expect(init.credentials).toBe("include");
    expect(init.cache).toBe("no-store");
  });
  it("envia o identificador somente em edição e não confia nas URLs de mídia", async () => {
    const entry = emptyEntry();
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue({ ok: true, json: async () => ({ entry }) })
    );
    const api = await import("./api");
    await api.saveEntry(entry, true);
    const fetchMock = vi.mocked(fetch);
    const init = fetchMock.mock.calls[0][1]!;
    const body = JSON.parse(init.body as string);
    expect(body.id).toBe(entry.id);
    expect(body.attachments).toBeUndefined();
  });
  it("traduz expiração da sessão sem simular autenticação", async () => {
    vi.stubGlobal(
      "fetch",
      vi
        .fn()
        .mockResolvedValue({
          ok: false,
          status: 401,
          json: async () => ({ error: "auth_required" }),
        })
    );
    const api = await import("./api");
    await expect(api.getEntries()).rejects.toThrow(
      "Sua sessão expirou. Entre novamente."
    );
  });
});
