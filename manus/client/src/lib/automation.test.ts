import { beforeEach, afterEach, describe, expect, it, vi } from "vitest";
import {
  defaultAutomation,
  validDate,
  validTime,
  validateReports,
  validateReminders,
  isQuiet,
  zonedClock,
  dueReminders,
  manualRange,
  buildReport,
  selectReportEntries,
  writeLocalAutomation,
  cronIsFresh,
} from "./automation";
import { emptyEntry, sampleEntries } from "./diary";
beforeEach(() =>
  vi.stubGlobal("localStorage", { getItem: () => null, setItem: vi.fn() })
);
afterEach(() => vi.unstubAllGlobals());
describe("Consentimento e configuração", () => {
  it("mantém todas as entregas desativadas por padrão", () => {
    const settings = defaultAutomation();
    expect(settings.reports.enabled).toBe(false);
    expect(settings.reports.consent).toBe(false);
    expect(settings.reminders.enabled).toBe(false);
    expect(settings.reports.includeNotes).toBe(false);
    expect(settings.reports.includeDetails).toBe(false);
  });
  it("não ativa relatórios sem destinatário e autorização", () => {
    const report = defaultAutomation().reports;
    report.enabled = true;
    expect(validateReports(report)).not.toBeNull();
    report.recipients = [
      {
        id: "test",
        name: "Pessoa",
        email: "teste@example.invalid",
        role: "family",
      },
    ];
    report.consent = true;
    expect(validateReports(report)).toBeNull();
  });
  it("recusa e-mails duplicados mesmo com capitalização diferente", () => {
    const report = defaultAutomation().reports;
    report.recipients = [
      {
        id: "a",
        name: "Pessoa",
        email: "teste@example.invalid",
        role: "family",
      },
      { id: "b", name: "Outra", email: "TESTE@example.invalid", role: "other" },
    ];
    expect(validateReports(report)).toContain("apenas uma vez");
  });
  it("nunca persiste uma promessa de automação remota no modo local", () => {
    const settings = defaultAutomation();
    settings.reports.enabled = true;
    expect(() => writeLocalAutomation(settings)).toThrow("exigem sua conta");
    settings.reports.enabled = false;
    settings.reminders.enabled = true;
    settings.reminders.channel = "push";
    expect(() => writeLocalAutomation(settings)).toThrow();
  });
});
describe("Datas e horários", () => {
  it("valida horas e datas reais", () => {
    expect(validTime("23:59")).toBe(true);
    expect(validTime("24:00")).toBe(false);
    expect(validTime("9:00")).toBe(false);
    expect(validDate("2026-02-30")).toBe(false);
    expect(validDate("2028-02-29")).toBe(true);
  });
  it("preserva o dia local em fuso diferente do UTC", () => {
    const clock = zonedClock(
      new Date("2026-10-15T01:00:00Z"),
      "America/Sao_Paulo"
    );
    expect(clock.date).toBe("2026-10-14");
    expect(clock.time).toBe("22:00");
    expect(clock.weekday).toBe(3);
  });
  it("silencia cruzando meia-noite e respeita os limites", () => {
    expect(isQuiet("22:00", "22:00", "07:00")).toBe(true);
    expect(isQuiet("03:00", "22:00", "07:00")).toBe(true);
    expect(isQuiet("07:00", "22:00", "07:00")).toBe(false);
    expect(isQuiet("12:00", "12:00", "12:00")).toBe(false);
  });
  it("não duplica horários ativos", () => {
    const settings = defaultAutomation().reminders;
    settings.custom = [{ id: "a", time: "09:00", period: null, enabled: true }];
    expect(validateReminders(settings)).toContain("mesmo horário");
  });
  it("usa intervalos manuais distintos dos períodos fechados do cron", () => {
    expect(manualRange("daily", "", "", "2026-10-14")).toEqual({
      from: "2026-10-14",
      to: "2026-10-14",
    });
    expect(manualRange("weekly", "", "", "2026-10-14")).toEqual({
      from: "2026-10-08",
      to: "2026-10-14",
    });
    expect(manualRange("monthly", "", "", "2026-10-14")).toEqual({
      from: "2026-10-01",
      to: "2026-10-14",
    });
    expect(manualRange("custom", "2026-08-01", "2026-08-20")).toEqual({
      from: "2026-08-01",
      to: "2026-08-20",
    });
  });
});
describe("Lembretes responsáveis", () => {
  it("só lembra com opção habilitada e em canal in-app", () => {
    const settings = defaultAutomation().reminders;
    settings.timezone = "America/Sao_Paulo";
    const now = new Date("2026-10-14T12:00:00Z");
    expect(dueReminders(settings, [], now)).toEqual([]);
    settings.enabled = true;
    expect(dueReminders(settings, [], now)).toHaveLength(1);
    settings.channel = "push";
    expect(dueReminders(settings, [], now)).toEqual([]);
  });
  it("pula períodos já anotados, dias desativados e horários de silêncio", () => {
    const settings = defaultAutomation().reminders;
    settings.enabled = true;
    settings.timezone = "America/Sao_Paulo";
    const now = new Date("2026-10-14T12:00:00Z");
    expect(
      dueReminders(settings, [emptyEntry("2026-10-14", "morning")], now)
    ).toEqual([]);
    settings.skipRecorded = false;
    expect(
      dueReminders(settings, [emptyEntry("2026-10-14", "morning")], now)
    ).toHaveLength(1);
    settings.days = [0];
    expect(dueReminders(settings, [], now)).toEqual([]);
    settings.days = [3];
    settings.quietStart = "08:00";
    settings.quietEnd = "10:00";
    expect(dueReminders(settings, [], now)).toEqual([]);
  });
  it("não trata heartbeat antigo como automação ativa", () => {
    const now = Date.parse("2026-10-14T12:00:00Z");
    expect(cronIsFresh(null, now)).toBe(false);
    expect(cronIsFresh("2026-10-14T11:45:00Z", now)).toBe(false);
    expect(cronIsFresh("2026-10-14T11:59:00Z", now)).toBe(true);
  });
});
describe("Conteúdo do relatório", () => {
  it("nunca exporta exemplos e filtra por datas", () => {
    const entries = [
      ...sampleEntries(),
      emptyEntry("2026-10-14", "morning"),
      emptyEntry("2026-10-13", "night"),
    ];
    expect(
      selectReportEntries(entries, "2026-10-14", "2026-10-14")
    ).toHaveLength(1);
  });
  it("exclui por padrão texto, detalhes e mídia", () => {
    const entry = emptyEntry("2026-10-14", "morning");
    entry.note = "NOTA CONFIDENCIAL";
    entry.sleep = 7.5;
    entry.symptoms = ["sintoma-test"];
    const report = buildReport(
      [entry],
      "2026-10-14",
      "2026-10-14",
      false,
      false
    );
    expect(report.html).not.toContain(entry.note);
    expect(report.text).not.toContain(entry.note);
    expect(report.text).not.toContain("sintoma-test");
    expect(report.summary.count).toBe(1);
    expect(report.summary.distribution).toHaveLength(7);
  });
  it("escapa HTML sem transformar nota autorizada em código", () => {
    const entry = emptyEntry("2026-10-14", "morning");
    entry.note = "<script>alert(1)</script>";
    const report = buildReport([entry], "2026-10-14", "2026-10-14", true, true);
    expect(report.html).toContain("&lt;script&gt;");
    expect(report.html).not.toContain("<script>");
    expect(report.text).toContain(entry.note);
  });
});
