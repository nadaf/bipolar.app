import { useCallback, useEffect, useRef, useState } from "react";
import { toast } from "sonner";
import { request } from "@/lib/api";
import {
  defaultAutomation,
  emptyCapabilities,
  readLocalAutomation,
  writeLocalAutomation,
  validateReports,
  validateReminders,
  dueReminders,
  type AutomationSettings,
  type Capabilities,
} from "@/lib/automation";
import type { Entry } from "@/lib/diary";

export function useAutomation(localMode: boolean, accountId: string | null) {
  const [settings, setSettings] = useState(defaultAutomation);
  const [capabilities, setCapabilities] =
    useState<Capabilities>(emptyCapabilities);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const refresh = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      if (localMode) {
        setSettings(readLocalAutomation());
        setCapabilities(emptyCapabilities);
      } else if (accountId) {
        const result = await request<{
          settings: AutomationSettings;
          capabilities: Capabilities;
        }>("automation");
        setSettings(result.settings);
        setCapabilities(result.capabilities);
      } else {
        setSettings(defaultAutomation());
        setCapabilities(emptyCapabilities);
      }
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : "Não foi possível carregar as preferências."
      );
    } finally {
      setLoading(false);
    }
  }, [localMode, accountId]);
  useEffect(() => {
    void refresh();
  }, [refresh]);
  async function save(next: AutomationSettings) {
    if (loading || error)
      throw new Error("Recarregue suas preferências antes de alterá-las.");
    const invalid =
      validateReports(next.reports) || validateReminders(next.reminders);
    if (invalid) throw new Error(invalid);
    setSaving(true);
    try {
      if (localMode) {
        writeLocalAutomation(next);
        setSettings(next);
      } else if (accountId) {
        const result = await request<{
          settings: AutomationSettings;
          capabilities: Capabilities;
        }>("automation", { settings: next });
        setSettings(result.settings);
        setCapabilities(result.capabilities);
      } else
        throw new Error(
          "Entre na sua conta para salvar preferências no servidor."
        );
    } finally {
      setSaving(false);
    }
  }
  return {
    settings,
    capabilities,
    loading,
    saving,
    error,
    refresh,
    save,
    localMode,
    accountId,
  };
}
export type AutomationController = ReturnType<typeof useAutomation>;

export function useReminderEngine(
  controller: AutomationController,
  entries: Entry[],
  ready: boolean
) {
  const seen = useRef(new Set<string>());
  const account = controller.localMode
    ? "local"
    : controller.accountId || "guest";
  useEffect(() => {
    seen.current = new Set();
    try {
      const saved = JSON.parse(
        localStorage.getItem(`bipolar-reminder-seen-${account}`) || "[]"
      );
      if (Array.isArray(saved))
        seen.current = new Set(saved.filter(v => typeof v === "string"));
    } catch {
      /* Nesta sessão, a deduplicação continua em memória. */
    }
  }, [account]);
  useEffect(() => {
    if (
      !ready ||
      controller.loading ||
      controller.error ||
      (!controller.localMode && !controller.accountId)
    )
      return;
    const check = () => {
      if (document.visibilityState !== "visible") return;
      for (const reminder of dueReminders(
        controller.settings.reminders,
        entries,
        new Date()
      )) {
        if (seen.current.has(reminder.key)) continue;
        seen.current.add(reminder.key);
        try {
          localStorage.setItem(
            `bipolar-reminder-seen-${account}`,
            JSON.stringify([...seen.current].slice(-200))
          );
        } catch {
          /* Não afirmar persistência quando indisponível. */
        }
        toast("Um momento para você", {
          description: "Se quiser, faça uma anotação no seu diário.",
          duration: 12000,
        });
      }
    };
    check();
    const timer = window.setInterval(check, 15000);
    document.addEventListener("visibilitychange", check);
    return () => {
      clearInterval(timer);
      document.removeEventListener("visibilitychange", check);
    };
  }, [
    controller.settings.reminders,
    controller.loading,
    controller.error,
    controller.localMode,
    controller.accountId,
    entries,
    ready,
    account,
  ]);
}
