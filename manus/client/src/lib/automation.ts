import {
  localDate,
  offsetDate,
  dateObject,
  moods,
  periods,
  type Entry,
  type Period,
} from "./diary";

export type Frequency = "daily" | "weekly" | "monthly" | "custom";
export type Recipient = {
  id: string;
  name: string;
  email: string;
  role: "psychologist" | "psychiatrist" | "family" | "other";
};
export type ReportSettings = {
  enabled: boolean;
  frequency: Frequency;
  time: string;
  weekday: number;
  monthDay: number;
  intervalDays: number;
  startDate: string;
  timezone: string;
  includeNotes: boolean;
  includeDetails: boolean;
  recipients: Recipient[];
  consent: boolean;
};
export type ReminderSlot = {
  id: string;
  enabled: boolean;
  time: string;
  period: Period | null;
};
export type ReminderSettings = {
  enabled: boolean;
  channel: "in-app" | "push" | "email";
  slots: ReminderSlot[];
  custom: ReminderSlot[];
  days: number[];
  timezone: string;
  skipRecorded: boolean;
  quietStart: string;
  quietEnd: string;
  email: string;
};
export type AutomationSettings = {
  reports: ReportSettings;
  reminders: ReminderSettings;
};
export type Capabilities = {
  smtp: boolean;
  push: boolean;
  vapidPublicKey: string;
  cronLastRun: string | null;
  pushEndpointHashes?: string[];
};
export type Delivery = {
  id: string;
  type: "report" | "reminder";
  channel: "email" | "push";
  status: "queued" | "sent" | "failed" | "unknown";
  createdAt: string;
  lastAttemptAt: string | null;
  recipientLabel: string;
  message: string;
};
export const roles: Record<Recipient["role"], string> = {
  psychologist: "Psicólogo(a)",
  psychiatrist: "Psiquiatra",
  family: "Familiar",
  other: "Outra pessoa de confiança",
};
export const frequencies: Record<Frequency, string> = {
  daily: "Diário",
  weekly: "Semanal",
  monthly: "Mensal",
  custom: "Personalizado",
};
export const weekDays = ["Dom", "Seg", "Ter", "Qua", "Qui", "Sex", "Sáb"];
export const emptyCapabilities: Capabilities = {
  smtp: false,
  push: false,
  vapidPublicKey: "",
  cronLastRun: null,
};
export function defaultAutomation(): AutomationSettings {
  let timezone = "America/Sao_Paulo";
  try {
    timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || timezone;
  } catch {
    /* Fuso brasileiro padrão. */
  }
  return {
    reports: {
      enabled: false,
      frequency: "weekly",
      time: "18:00",
      weekday: 1,
      monthDay: 1,
      intervalDays: 14,
      startDate: localDate(),
      timezone,
      includeNotes: false,
      includeDetails: false,
      recipients: [],
      consent: false,
    },
    reminders: {
      enabled: false,
      channel: "in-app",
      slots: [
        { id: "morning", enabled: true, time: "09:00", period: "morning" },
        { id: "afternoon", enabled: true, time: "15:00", period: "afternoon" },
        { id: "night", enabled: true, time: "21:00", period: "night" },
      ],
      custom: [],
      days: [0, 1, 2, 3, 4, 5, 6],
      timezone,
      skipRecorded: true,
      quietStart: "22:00",
      quietEnd: "07:00",
      email: "",
    },
  };
}
export function validTime(time: string) {
  return /^([01]\d|2[0-3]):[0-5]\d$/.test(time);
}
export function validDate(date: string) {
  return (
    /^\d{4}-\d{2}-\d{2}$/.test(date) && localDate(dateObject(date)) === date
  );
}
export function validEmail(email: string) {
  return email.length <= 254 && /^[^\s@<>]+@[^\s@<>]+\.[^\s@<>]+$/.test(email);
}
export function validTimezone(timezone: string) {
  try {
    new Intl.DateTimeFormat("pt-BR", { timeZone: timezone }).format();
    return true;
  } catch {
    return false;
  }
}
export function validateReports(settings: ReportSettings): string | null {
  if (!validTime(settings.time) || !validTimezone(settings.timezone))
    return "Confira o horário e o fuso dos relatórios.";
  if (
    !Number.isInteger(settings.weekday) ||
    settings.weekday < 0 ||
    settings.weekday > 6 ||
    !Number.isInteger(settings.monthDay) ||
    settings.monthDay < 1 ||
    settings.monthDay > 31
  )
    return "Confira o dia da semana ou do mês.";
  if (
    !Number.isInteger(settings.intervalDays) ||
    settings.intervalDays < 1 ||
    settings.intervalDays > 90 ||
    !validDate(settings.startDate)
  )
    return "Escolha uma data válida e um intervalo de 1 a 90 dias.";
  if (settings.recipients.length > 10)
    return "Você pode cadastrar até 10 destinatários.";
  if (
    settings.recipients.some(
      r =>
        !r.name.trim() ||
        r.name.trim().length > 100 ||
        !validEmail(r.email.trim()) ||
        !roles[r.role]
    )
  )
    return "Preencha o nome e um e-mail válido para cada destinatário.";
  if (
    new Set(settings.recipients.map(r => r.email.trim().toLowerCase())).size !==
    settings.recipients.length
  )
    return "Cada endereço de e-mail deve aparecer apenas uma vez.";
  if (settings.enabled && (!settings.consent || !settings.recipients.length))
    return "Escolha ao menos um destinatário e autorize o envio automático.";
  return null;
}
export function validateReminders(settings: ReminderSettings): string | null {
  if (
    !validTimezone(settings.timezone) ||
    !validTime(settings.quietStart) ||
    !validTime(settings.quietEnd)
  )
    return "Confira o fuso e os horários do período de silêncio.";
  if (settings.enabled && !settings.days.length)
    return "Escolha ao menos um dia da semana.";
  if (settings.days.some(d => !Number.isInteger(d) || d < 0 || d > 6))
    return "Confira os dias da semana.";
  const slots = [...settings.slots, ...settings.custom];
  if (settings.custom.length > 6 || slots.some(s => !validTime(s.time)))
    return "Use até 6 horários personalizados e preencha cada horário.";
  const active = slots.filter(s => s.enabled);
  if (settings.enabled && !active.length)
    return "Ative ao menos um horário de lembrete.";
  if (new Set(active.map(s => s.time)).size !== active.length)
    return "Evite dois lembretes no mesmo horário.";
  if (
    settings.enabled &&
    settings.channel === "email" &&
    !validEmail(settings.email.trim())
  )
    return "Informe seu próprio e-mail para receber os lembretes.";
  return null;
}
export function readLocalAutomation(): AutomationSettings {
  const value = localStorage.getItem("bipolar-automation-v2");
  if (!value) return defaultAutomation();
  const settings = JSON.parse(value) as AutomationSettings;
  if (
    !settings?.reports ||
    !settings?.reminders ||
    !Array.isArray(settings.reports.recipients) ||
    !Array.isArray(settings.reminders.slots) ||
    !Array.isArray(settings.reminders.custom) ||
    validateReports(settings.reports) ||
    validateReminders(settings.reminders)
  )
    throw new Error(
      "Não foi possível ler as preferências. Elas não foram substituídas."
    );
  return settings;
}
export function writeLocalAutomation(settings: AutomationSettings) {
  if (
    settings.reports.enabled ||
    (settings.reminders.enabled && settings.reminders.channel !== "in-app")
  )
    throw new Error(
      "Envios automáticos e notificações com o aplicativo fechado exigem sua conta e o backend configurado."
    );
  localStorage.setItem("bipolar-automation-v2", JSON.stringify(settings));
}
export function zonedClock(now: Date, timezone: string) {
  const parts = new Intl.DateTimeFormat("en-CA", {
    timeZone: timezone,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23",
  }).formatToParts(now);
  const get = (type: string) => parts.find(p => p.type === type)?.value || "";
  const date = `${get("year")}-${get("month")}-${get("day")}`;
  return {
    date,
    time: `${get("hour")}:${get("minute")}`,
    weekday: dateObject(date).getDay(),
  };
}
export function isQuiet(time: string, start: string, end: string) {
  if (start === end) return false;
  return start < end
    ? time >= start && time < end
    : time >= start || time < end;
}
export function dueReminders(
  settings: ReminderSettings,
  entries: Entry[],
  now: Date
) {
  if (!settings.enabled || settings.channel !== "in-app") return [];
  const clock = zonedClock(now, settings.timezone);
  if (
    !settings.days.includes(clock.weekday) ||
    isQuiet(clock.time, settings.quietStart, settings.quietEnd)
  )
    return [];
  const today = entries.filter(e => e.date === clock.date);
  return [...settings.slots, ...settings.custom]
    .filter(
      s =>
        s.enabled &&
        s.time === clock.time &&
        (!settings.skipRecorded ||
          (s.period
            ? !today.some(e => e.period === s.period)
            : new Set(today.map(e => e.period)).size < 3))
    )
    .map(s => ({
      ...s,
      key: `${settings.timezone}/${clock.date}/${s.id}/${s.time}`,
    }));
}
/** Intervalo manual: inclui hoje. O cron calcula períodos fechados anteriores. */
export function manualRange(
  frequency: Frequency,
  from: string,
  to: string,
  today = localDate()
) {
  if (frequency === "custom") return { from, to };
  if (frequency === "daily") return { from: today, to: today };
  if (frequency === "weekly") return { from: offsetDate(today, -6), to: today };
  const date = dateObject(today);
  date.setDate(1);
  return { from: localDate(date), to: today };
}
export function selectReportEntries(
  entries: Entry[],
  from: string,
  to: string
) {
  return entries
    .filter(e => !e.id.startsWith("example-") && e.date >= from && e.date <= to)
    .sort(
      (a, b) =>
        a.date.localeCompare(b.date) ||
        periods.findIndex(p => p.id === a.period) -
          periods.findIndex(p => p.id === b.period)
    );
}
export function reportSummary(entries: Entry[]) {
  return {
    count: entries.length,
    days: new Set(entries.map(e => e.date)).size,
    distribution: moods.map(m => ({
      ...m,
      count: entries.filter(e => e.mood === m.value).length,
    })),
  };
}
function escapeHtml(value: unknown) {
  return String(value ?? "").replace(
    /[&<>"']/g,
    c =>
      ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[
        c
      ]!
  );
}
export function buildReport(
  entries: Entry[],
  from: string,
  to: string,
  includeNotes: boolean,
  includeDetails: boolean
) {
  const selected = selectReportEntries(entries, from, to);
  const summary = reportSummary(selected);
  const range = `${dateObject(from).toLocaleDateString("pt-BR")} a ${dateObject(to).toLocaleDateString("pt-BR")}`;
  const introduction = `Diário de humor — ${range}\n${summary.count} registros em ${summary.days} dias.\nEscala descritiva: -3 Depressão, -2 Muito baixo, -1 Baixo, 0 Estável, +1 Elevado, +2 Muito elevado, +3 Euforia.\nSem interpretação clínica. Lacunas não significam estabilidade.`;
  const text =
    introduction +
    "\n\n" +
    selected
      .map(
        e =>
          `${dateObject(e.date).toLocaleDateString("pt-BR")} · ${periods.find(p => p.id === e.period)?.label}: ${moods[e.mood + 3]?.label} (${e.mood > 0 ? "+" : ""}${e.mood})${includeDetails ? `\nSono: ${e.sleep ?? "não informado"}; medicação: ${{ "": "não informada", taken: "tomada conforme prescrição", "not-taken": "não tomada", "not-applicable": "não se aplica" }[e.medication]}; sintomas mistos: ${e.mixed ? "relatados" : "não relatados"}; sintomas: ${e.symptoms.join(", ") || "não informados"}; contexto: ${e.factors.join(", ") || "não informado"}.` : ""}${includeNotes && e.note ? `\nNota: ${e.note}` : ""}`
      )
      .join("\n\n");
  const html = `<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Relatório do diário — ${escapeHtml(range)}</title><style>body{font:15px/1.55 system-ui,sans-serif;color:#25372b;max-width:950px;margin:40px auto;padding:0 24px}h1{font-size:28px}h2{font-size:18px}small,footer{color:#526452}table{border-collapse:collapse;width:100%;margin:24px 0}th,td{text-align:left;padding:12px;border-bottom:1px solid #d9e1d6;vertical-align:top}th{background:#eef3e9}td p{margin:6px 0;white-space:pre-wrap;overflow-wrap:anywhere}.private{border-left:4px solid #456b53;background:#f0f4ed;padding:16px}@media(max-width:600px){body{padding:0 14px;margin:24px auto}th,td{padding:8px;font-size:12px}}@media print{body{margin:0;font-size:11pt}.private{background:none}tr{break-inside:avoid}thead{display:table-header-group}}</style></head><body><small>BIPOLAR · DIÁRIO PESSOAL · COMPARTILHAMENTO VOLUNTÁRIO</small><h1>Um olhar para os registros</h1><p>${escapeHtml(range)} · ${summary.count} registros em ${summary.days} dias</p><div class="private">Este documento contém informações pessoais de saúde. Compartilhe apenas com as pessoas escolhidas. Não é um diagnóstico nem um alerta de crise.</div><h2>Distribuição dos níveis registrados</h2><p>${summary.distribution.map(m => `${escapeHtml(m.label)} (${m.value > 0 ? "+" : ""}${m.value}): ${m.count}`).join(" · ")}</p><p><small>Lacunas significam ausência de registro. Não são estabilidade. A escala é descritiva, não validada para classificar episódios.</small></p><table><thead><tr><th>Data</th><th>Período</th><th>Humor</th>${includeNotes || includeDetails ? "<th>Conteúdo autorizado</th>" : ""}</tr></thead><tbody>${selected.map(e => `<tr><td>${escapeHtml(dateObject(e.date).toLocaleDateString("pt-BR"))}</td><td>${escapeHtml(periods.find(p => p.id === e.period)?.label)}</td><td>${escapeHtml(moods[e.mood + 3]?.label)} (${e.mood > 0 ? "+" : ""}${e.mood})</td>${includeNotes || includeDetails ? `<td>${includeNotes && e.note ? `<p><strong>Nota:</strong> ${escapeHtml(e.note)}</p>` : ""}${includeDetails ? `<p>Sono: ${escapeHtml(e.sleep ?? "não informado")} h. Medicação: ${escapeHtml({ "": "não informada", taken: "tomada conforme prescrição", "not-taken": "não tomada", "not-applicable": "não se aplica" }[e.medication])}. Sintomas mistos: ${e.mixed ? "relatados" : "não relatados"}.</p><p>Sintomas: ${escapeHtml(e.symptoms.join(", ") || "não informados")}. Contexto: ${escapeHtml(e.factors.join(", ") || "não informado")}.</p>` : ""}</td>` : ""}</tr>`).join("")}</tbody></table>${selected.length ? "" : "<p>Não há registros no intervalo selecionado.</p>"}<footer>Gerado por escolha do usuário. Mídias não incluídas. Relatório descritivo, sem avaliação automatizada. Revogar um agendamento não apaga cópias já compartilhadas.</footer></body></html>`;
  return { html, text, selected, summary, range };
}
export function downloadReport(html: string, from: string, to: string) {
  const blob = new Blob([html], { type: "text/html;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = `diario-${from}-a-${to}.html`;
  anchor.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
export function cronIsFresh(lastRun: string | null, now = Date.now()) {
  if (!lastRun) return false;
  const timestamp = Date.parse(lastRun);
  return (
    Number.isFinite(timestamp) &&
    now - timestamp >= -60000 &&
    now - timestamp < 10 * 60000
  );
}

/** Congela a escolha do usuário: o backend rejeita conteúdo alterado desde a prévia. */
export async function reportFingerprint(
  entries: Entry[],
  includeNotes: boolean,
  includeDetails: boolean
) {
  const records = entries.map(entry => ({
    id: entry.id,
    date: entry.date,
    period: entry.period,
    mood: entry.mood,
    note: includeNotes ? entry.note : null,
    details: includeDetails
      ? {
          sleep: entry.sleep,
          medication: entry.medication,
          mixed: entry.mixed,
          symptoms: entry.symptoms,
          factors: entry.factors,
        }
      : null,
  }));
  const hash = await crypto.subtle.digest(
    "SHA-256",
    new TextEncoder().encode(JSON.stringify(records))
  );
  return Array.from(new Uint8Array(hash), byte =>
    byte.toString(16).padStart(2, "0")
  ).join("");
}
