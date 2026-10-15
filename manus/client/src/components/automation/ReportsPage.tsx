import { useEffect, useMemo, useRef, useState } from "react";
import {
  Send,
  FileDown,
  Share2,
  Printer,
  Users,
  Plus,
  Trash2,
  ShieldCheck,
  CalendarClock,
  Check,
  Clock3,
  RefreshCw,
  Mail,
  Eye,
} from "lucide-react";
import { toast } from "sonner";
import { request } from "@/lib/api";
import { localDate, dateObject, moods, periods, type Entry } from "@/lib/diary";
import {
  buildReport,
  reportFingerprint,
  downloadReport,
  manualRange,
  roles,
  frequencies,
  weekDays,
  validateReports,
  validDate,
  type Frequency,
  type Recipient,
  type ReportSettings,
  type Delivery,
} from "@/lib/automation";
import type { AutomationController } from "@/hooks/useAutomation";
import Modal from "@/components/diary/Modal";
import {
  AutomationNotice,
  TimezoneField,
  ScheduleBadge,
  SavingLabel,
} from "./Controls";

type Channel = "download" | "print" | "share" | "email";
function formatDate(value: string) {
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? "Data não disponível"
    : date.toLocaleString("pt-BR", { dateStyle: "short", timeStyle: "short" });
}
function ReportPreview({
  report,
  notes,
  details,
}: {
  report: ReturnType<typeof buildReport>;
  notes: boolean;
  details: boolean;
}) {
  const [all, setAll] = useState(false);
  const records = all ? report.selected : report.selected.slice(0, 20);
  return (
    <section className="card report-preview">
      <div className="card-heading">
        <div>
          <h2>
            <Eye size={20} />
            Prévia do que será compartilhado
          </h2>
          <p>{report.range} · somente seus registros reais</p>
        </div>
        <span className="private-label">
          <ShieldCheck size={14} />
          Pessoal
        </span>
      </div>
      <div className="report-summary">
        <div>
          <strong>{report.summary.count}</strong>
          <span>registros</span>
        </div>
        <div>
          <strong>{report.summary.days}</strong>
          <span>dias anotados</span>
        </div>
        <div>
          <strong>{notes ? "Sim" : "Não"}</strong>
          <span>notas pessoais</span>
        </div>
        <div>
          <strong>{details ? "Sim" : "Não"}</strong>
          <span>detalhes de saúde</span>
        </div>
      </div>
      <div className="distribution-row">
        {report.summary.distribution.map(mood => (
          <div
            key={mood.value}
            style={{ "--mood-accent": mood.color } as React.CSSProperties}
          >
            <span>{mood.label}</span>
            <strong>{mood.count}</strong>
            <small>
              Nível {mood.value > 0 ? "+" : ""}
              {mood.value}
            </small>
          </div>
        ))}
      </div>
      {records.length ? (
        <div className="report-table-scroll">
          <table>
            <caption className="sr-only">
              Registros incluídos no relatório do período selecionado
            </caption>
            <thead>
              <tr>
                <th>Data</th>
                <th>Período</th>
                <th>Humor</th>
                {notes || details ? <th>Conteúdo autorizado</th> : null}
              </tr>
            </thead>
            <tbody>
              {records.map(entry => (
                <tr key={entry.id}>
                  <td>{dateObject(entry.date).toLocaleDateString("pt-BR")}</td>
                  <td>
                    {periods.find(period => period.id === entry.period)?.label}
                  </td>
                  <td>
                    <span className="report-mood">
                      <i style={{ background: moods[entry.mood + 3]?.color }} />
                      {moods[entry.mood + 3]?.label} (
                      {entry.mood > 0 ? "+" : ""}
                      {entry.mood})
                    </span>
                  </td>
                  {notes || details ? (
                    <td>
                      {notes && entry.note && (
                        <p className="report-note">{entry.note}</p>
                      )}
                      {details && (
                        <>
                          <p>
                            Sono:{" "}
                            {entry.sleep === null
                              ? "não informado"
                              : `${entry.sleep} h`}
                            . Medicação:{" "}
                            {
                              {
                                "": "não informada",
                                taken: "tomada conforme prescrição",
                                "not-taken": "não tomada",
                                "not-applicable": "não se aplica",
                              }[entry.medication]
                            }
                            .
                          </p>
                          <p>
                            Sintomas mistos:{" "}
                            {entry.mixed ? "relatados" : "não relatados"}.
                            Sintomas:{" "}
                            {entry.symptoms.join(", ") || "não informados"}.
                            Contexto:{" "}
                            {entry.factors.join(", ") || "não informado"}.
                          </p>
                        </>
                      )}
                      {!entry.note && !details && (
                        <span className="muted">Sem nota</span>
                      )}
                    </td>
                  ) : null}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <div className="report-empty">
          <FileDown size={25} />
          <p>
            Não há registros neste intervalo. Escolha outro período ou faça sua
            primeira anotação.
          </p>
        </div>
      )}
      {!all && report.selected.length > 20 && (
        <button className="text-button" onClick={() => setAll(true)}>
          Mostrar os {report.selected.length} registros da prévia
        </button>
      )}
      <p className="auto-hint">
        Lacunas são ausência de registro, não estabilidade. Não calculamos
        médias de humor nem identificamos episódios. Áudio e vídeo nunca entram
        neste relatório.
      </p>
    </section>
  );
}
export default function ReportsPage({
  entries,
  controller,
  onDirtyChange,
  dataError = false,
}: {
  entries: Entry[];
  controller: AutomationController;
  dataError?: boolean;
  onDirtyChange?: (dirty: boolean) => void;
}) {
  const {
    settings,
    capabilities,
    localMode,
    accountId,
    saving,
    loading,
    error,
  } = controller;
  const [tab, setTab] = useState<"manual" | "automatic">("manual");
  const [frequency, setFrequency] = useState<Frequency>("weekly");
  const [from, setFrom] = useState(localDate());
  const [to, setTo] = useState(localDate());
  const [notes, setNotes] = useState(false);
  const [details, setDetails] = useState(false);
  const [recipients, setRecipients] = useState<Recipient[]>(
    settings.reports.recipients
  );
  const [selectedIds, setSelectedIds] = useState<string[]>([]);
  const [draft, setDraft] = useState<ReportSettings>(settings.reports);
  const [confirm, setConfirm] = useState<Channel | null>(null);
  const [consent, setConsent] = useState(false);
  const [sendKey, setSendKey] = useState(crypto.randomUUID);
  const [sending, setSending] = useState(false);
  const [deliveries, setDeliveries] = useState<Delivery[]>([]);
  const [historyError, setHistoryError] = useState("");
  const [historyBusy, setHistoryBusy] = useState(false);
  const remote = !localMode && !!accountId;
  const operation = useRef<"contacts" | "schedule" | null>(null);
  const contactsDirty =
    JSON.stringify(recipients) !== JSON.stringify(settings.reports.recipients);
  const scheduleDirty =
    JSON.stringify({ ...draft, recipients: settings.reports.recipients }) !==
    JSON.stringify(settings.reports);
  const otherDraftDirty = useRef(scheduleDirty);
  otherDraftDirty.current = scheduleDirty;
  useEffect(() => {
    onDirtyChange?.(contactsDirty || scheduleDirty);
    return () => onDirtyChange?.(false);
  }, [contactsDirty, scheduleDirty, onDirtyChange]);
  useEffect(() => {
    if (operation.current === "contacts") {
      setRecipients(settings.reports.recipients);
      setDraft(previous =>
        otherDraftDirty.current
          ? {
              ...previous,
              recipients: settings.reports.recipients,
              enabled: false,
              consent: false,
            }
          : settings.reports
      );
    } else {
      setRecipients(settings.reports.recipients);
      setDraft(settings.reports);
    }
    operation.current = null;
    setSelectedIds(previous =>
      previous.filter(id =>
        settings.reports.recipients.some(recipient => recipient.id === id)
      )
    );
  }, [settings.reports]);
  const range = manualRange(frequency, from, to);
  const rangeValid =
    validDate(range.from) &&
    validDate(range.to) &&
    range.from <= range.to &&
    range.to <= localDate() &&
    (dateObject(range.to).getTime() - dateObject(range.from).getTime()) /
      86400000 <=
      365;
  const report = useMemo(
    () => buildReport(entries, range.from, range.to, notes, details),
    [entries, range.from, range.to, notes, details]
  );
  const chosen = settings.reports.recipients.filter(recipient =>
    selectedIds.includes(recipient.id)
  );
  const disabled = loading || saving || !!error || sending || dataError;
  const canShare = typeof navigator.share === "function";
  async function refreshHistory() {
    if (!remote) return;
    setHistoryBusy(true);
    setHistoryError("");
    try {
      const result = await request<{ deliveries: Delivery[] }>(
        "delivery_history"
      );
      setDeliveries(result.deliveries);
    } catch (err) {
      setHistoryError(
        err instanceof Error
          ? err.message
          : "Não foi possível consultar as entregas."
      );
    } finally {
      setHistoryBusy(false);
    }
  }
  useEffect(() => {
    if (remote) void refreshHistory();
    else setDeliveries([]);
  }, [remote, accountId]);
  function setRecipient(id: string, patch: Partial<Recipient>) {
    setRecipients(previous =>
      previous.map(recipient =>
        recipient.id === id ? { ...recipient, ...patch } : recipient
      )
    );
  }
  async function saveRecipients(event: React.FormEvent) {
    event.preventDefault();
    const next = {
      ...settings.reports,
      enabled: false,
      consent: false,
      recipients: recipients.map(recipient => ({
        ...recipient,
        name: recipient.name.trim(),
        email: recipient.email.trim(),
      })),
    };
    const invalid = validateReports(next);
    if (invalid) return toast.error(invalid);
    operation.current = "contacts";
    try {
      await controller.save({ ...settings, reports: next });
      toast.success(
        settings.reports.enabled
          ? "Rede de cuidado atualizada. A programação foi pausada; revise e autorize novamente."
          : "Destinatários salvos. Você escolhe quando e o que compartilhar."
      );
    } catch (err) {
      operation.current = null;
      toast.error(
        err instanceof Error
          ? err.message
          : "Não foi possível salvar os destinatários."
      );
    }
  }
  async function saveSchedule(event: React.FormEvent) {
    event.preventDefault();
    if (contactsDirty)
      return toast.info(
        "Salve as alterações dos destinatários antes de salvar a programação. Sua cadência editada será preservada."
      );
    const next = { ...draft, recipients: settings.reports.recipients };
    const invalid = validateReports(next);
    if (invalid) return toast.error(invalid);
    if (next.enabled && (!remote || !capabilities.smtp))
      return toast.error(
        "A programação de e-mail exige sua conta e o SMTP configurado no servidor."
      );
    operation.current = "schedule";
    try {
      await controller.save({ ...settings, reports: next });
      toast.success(
        next.enabled
          ? "Programação salva. Consulte o estado do cron e o histórico de entregas."
          : "Envios automáticos pausados."
      );
    } catch (err) {
      operation.current = null;
      toast.error(
        err instanceof Error
          ? err.message
          : "Não foi possível salvar a programação."
      );
    }
  }
  function prepare(channel: Channel) {
    if (!rangeValid)
      return toast.error(
        "Escolha um intervalo válido de até 366 dias, sem datas futuras."
      );
    if (!report.selected.length)
      return toast.info("Não há registros no período selecionado.");
    if (
      channel === "email" &&
      (!remote || !capabilities.smtp || !chosen.length)
    )
      return toast.error(
        "Escolha os destinatários salvos e confirme a configuração do e-mail no servidor."
      );
    setConsent(false);
    setSendKey(crypto.randomUUID());
    setConfirm(channel);
  }
  async function shareReport() {
    if (!confirm || !consent) return;
    setSending(true);
    try {
      if (confirm === "download") {
        downloadReport(report.html, range.from, range.to);
        toast.success(
          "Relatório HTML preparado para download. Mantenha o arquivo em um lugar protegido."
        );
      } else if (confirm === "print") {
        const url = URL.createObjectURL(
          new Blob([report.html], { type: "text/html;charset=utf-8" })
        );
        const popup = window.open(url, "_blank");
        if (!popup) {
          URL.revokeObjectURL(url);
          throw new Error(
            "O navegador bloqueou a janela. Permita a abertura ou baixe o relatório HTML."
          );
        }
        popup.opener = null;
        popup.addEventListener(
          "load",
          () => {
            popup.focus();
            popup.print();
          },
          { once: true }
        );
        setTimeout(() => URL.revokeObjectURL(url), 60000);
      } else if (confirm === "share") {
        const file = new File(
          [report.html],
          `diario-${range.from}-a-${range.to}.html`,
          { type: "text/html" }
        );
        if (navigator.canShare?.({ files: [file] }))
          await navigator.share({
            files: [file],
            title: "Relatório do diário",
          });
        else
          await navigator.share({
            title: "Relatório do diário",
            text: report.text,
          });
        toast.success("Compartilhamento entregue ao menu do dispositivo.");
      } else {
        const result = await request<{
          status: string;
          sent?: number;
          queued?: number;
        }>("send_report", {
          from: range.from,
          to: range.to,
          recipientIds: chosen.map(recipient => recipient.id),
          includeNotes: notes,
          includeDetails: details,
          consent: true,
          idempotencyKey: sendKey,
          expectedFingerprint: await reportFingerprint(
            report.selected,
            notes,
            details
          ),
          expectedRecipients: chosen.map(recipient => ({
            id: recipient.id,
            email: recipient.email,
          })),
        });
        toast.success(
          result.status === "sent"
            ? "O servidor de e-mail aceitou o envio. Isso não confirma leitura nem chegada à caixa de entrada."
            : "Relatório colocado na fila do servidor. O cron fará a entrega; consulte o histórico."
        );
        void refreshHistory();
      }
      setConfirm(null);
    } catch (err) {
      if (!(err instanceof DOMException && err.name === "AbortError"))
        toast.error(
          err instanceof Error ? err.message : "Não foi possível compartilhar."
        );
    } finally {
      setSending(false);
    }
  }
  const channels: Record<Channel, string> = {
    download: "Baixar relatório HTML",
    print: "Imprimir ou salvar em PDF",
    share: "Compartilhar pelo dispositivo",
    email: "Enviar por e-mail",
  };
  return (
    <div className="reports-layout">
      <div className="reports-intro">
        <span className="soft-icon">
          <Send size={26} />
        </span>
        <div>
          <h2>Seu cuidado pode ser compartilhado.</h2>
          <p>
            Escolha as pessoas, o período e o conteúdo. Você mantém o controle,
            sempre.
          </p>
        </div>
        <span className="private-label">
          <ShieldCheck size={15} />
          Sem links públicos
        </span>
      </div>
      {error && (
        <div className="error-banner" role="alert">
          {error}
          <button className="text-button" onClick={controller.refresh}>
            Tentar novamente
          </button>
        </div>
      )}
      <div
        className="reports-tabs"
        role="group"
        aria-label="Forma de compartilhamento"
      >
        <button
          aria-pressed={tab === "manual"}
          onClick={() => setTab("manual")}
          className={tab === "manual" ? "active" : ""}
        >
          <FileDown size={18} />
          Preparar e enviar agora
        </button>
        <button
          aria-pressed={tab === "automatic"}
          onClick={() => setTab("automatic")}
          className={tab === "automatic" ? "active" : ""}
        >
          <CalendarClock size={18} />
          Programar relatórios
        </button>
      </div>
      <div className="reports-columns">
        <div className="reports-primary">
          {tab === "manual" ? (
            <>
              <section className="card automation-card">
                <div className="card-heading">
                  <div>
                    <h2>O que você quer levar ao cuidado?</h2>
                    <p>
                      Veja a prévia antes de compartilhar qualquer informação.
                    </p>
                  </div>
                </div>
                <fieldset className="auto-fieldset" disabled={disabled}>
                  <label className="auto-field">
                    <span>Período do relatório</span>
                    <select
                      value={frequency}
                      onChange={event =>
                        setFrequency(event.target.value as Frequency)
                      }
                    >
                      <option value="daily">Diário — hoje</option>
                      <option value="weekly">Semanal — últimos 7 dias</option>
                      <option value="monthly">
                        Mensal — este mês até hoje
                      </option>
                      <option value="custom">
                        Personalizado — escolher datas
                      </option>
                    </select>
                  </label>
                  {frequency === "custom" && (
                    <div className="auto-fields">
                      <label className="auto-field">
                        <span>De</span>
                        <input
                          type="date"
                          value={from}
                          max={to <= localDate() ? to : localDate()}
                          onChange={event => setFrom(event.target.value)}
                          required
                        />
                      </label>
                      <label className="auto-field">
                        <span>Até</span>
                        <input
                          type="date"
                          value={to}
                          min={from}
                          max={localDate()}
                          onChange={event => setTo(event.target.value)}
                          required
                        />
                      </label>
                    </div>
                  )}
                  <div className="report-content-options">
                    <span className="auto-field-title">
                      Sempre incluídos: data, período e humor registrado
                    </span>
                    <label className="auto-check">
                      <input
                        type="checkbox"
                        checked={notes}
                        onChange={event => setNotes(event.target.checked)}
                      />
                      <span>
                        Incluir minhas notas pessoais
                        <small>
                          Por padrão, suas anotações de texto ficam de fora.
                        </small>
                      </span>
                    </label>
                    <label className="auto-check">
                      <input
                        type="checkbox"
                        checked={details}
                        onChange={event => setDetails(event.target.checked)}
                      />
                      <span>
                        Incluir sono, medicação, sintomas e contexto
                        <small>
                          São informações sensíveis. Ative somente se quiser
                          compartilhá-las.
                        </small>
                      </span>
                    </label>
                  </div>
                  <div className="manual-actions">
                    <button
                      className="primary-button"
                      onClick={() => prepare("download")}
                      disabled={!rangeValid || !report.selected.length}
                    >
                      <FileDown size={17} />
                      Baixar relatório
                    </button>
                    <button
                      className="outline-button"
                      onClick={() => prepare("print")}
                      disabled={!rangeValid || !report.selected.length}
                    >
                      <Printer size={17} />
                      Imprimir / PDF
                    </button>
                    <button
                      className="outline-button"
                      onClick={() => prepare("share")}
                      disabled={
                        !canShare || !rangeValid || !report.selected.length
                      }
                    >
                      <Share2 size={17} />
                      Compartilhar
                    </button>
                  </div>
                  {!canShare && (
                    <p className="auto-hint">
                      Este navegador não oferece o menu de compartilhamento. Use
                      o download ou o e-mail.
                    </p>
                  )}
                  <div className="report-email-picker">
                    <h3>
                      <Mail size={18} />
                      Enviar para sua rede de cuidado
                    </h3>
                    {settings.reports.recipients.length ? (
                      settings.reports.recipients.map(recipient => (
                        <label className="recipient-choice" key={recipient.id}>
                          <input
                            type="checkbox"
                            checked={selectedIds.includes(recipient.id)}
                            onChange={event =>
                              setSelectedIds(
                                event.target.checked
                                  ? [...selectedIds, recipient.id]
                                  : selectedIds.filter(
                                      id => id !== recipient.id
                                    )
                              )
                            }
                          />
                          <span>
                            <strong>{recipient.name}</strong>
                            <small>
                              {roles[recipient.role]} · {recipient.email}
                            </small>
                          </span>
                        </label>
                      ))
                    ) : (
                      <p className="auto-hint">
                        Cadastre os destinatários na área “Sua rede de cuidado”.
                      </p>
                    )}
                    <button
                      className="outline-button"
                      onClick={() => prepare("email")}
                      disabled={
                        !remote ||
                        !capabilities.smtp ||
                        !chosen.length ||
                        !report.selected.length ||
                        !rangeValid
                      }
                    >
                      <Send size={16} />
                      Revisar envio por e-mail
                    </button>
                    <AutomationNotice
                      localMode={localMode}
                      capabilities={capabilities}
                    />
                  </div>
                </fieldset>
              </section>
              {rangeValid && (
                <ReportPreview
                  report={report}
                  notes={notes}
                  details={details}
                />
              )}
              {!rangeValid && (
                <div className="error-banner" role="alert">
                  Escolha datas válidas, sem datas futuras, em um intervalo de
                  até 366 dias.
                </div>
              )}
            </>
          ) : (
            <section className="card automation-card">
              <div className="card-heading">
                <div>
                  <h2>
                    <CalendarClock size={21} />
                    Uma rotina de compartilhamento
                  </h2>
                  <p>
                    Apenas e-mail pode ser enviado automaticamente.
                    Compartilhamento pelo dispositivo é sempre manual.
                  </p>
                </div>
                <ScheduleBadge
                  enabled={settings.reports.enabled}
                  local={localMode}
                  capabilities={capabilities}
                />
              </div>
              <AutomationNotice
                localMode={localMode}
                capabilities={capabilities}
              />
              <form onSubmit={saveSchedule}>
                <fieldset
                  className="auto-fieldset"
                  disabled={disabled || (!localMode && !accountId)}
                >
                  <label className="setting-row auto-toggle">
                    <span>
                      <strong>Enviar relatórios automaticamente</strong>
                      <p>
                        Somente para os destinatários salvos e com o conteúdo
                        autorizado abaixo.
                      </p>
                    </span>
                    <input
                      type="checkbox"
                      className="switch-control"
                      checked={draft.enabled}
                      disabled={!remote || !capabilities.smtp}
                      onChange={event =>
                        setDraft(previous => ({
                          ...previous,
                          enabled: event.target.checked,
                        }))
                      }
                    />
                  </label>
                  <div className="auto-fields">
                    <label className="auto-field">
                      <span>Frequência de envio</span>
                      <select
                        value={draft.frequency}
                        onChange={event =>
                          setDraft(previous => ({
                            ...previous,
                            frequency: event.target.value as Frequency,
                          }))
                        }
                      >
                        {Object.entries(frequencies).map(([value, label]) => (
                          <option key={value} value={value}>
                            {label}
                          </option>
                        ))}
                      </select>
                    </label>
                    <label className="auto-field">
                      <span>Horário de envio</span>
                      <input
                        type="time"
                        value={draft.time}
                        onChange={event =>
                          setDraft(previous => ({
                            ...previous,
                            time: event.target.value,
                          }))
                        }
                        required
                      />
                    </label>
                  </div>
                  {draft.frequency === "weekly" && (
                    <label className="auto-field">
                      <span>Dia da semana</span>
                      <select
                        value={draft.weekday}
                        onChange={event =>
                          setDraft(previous => ({
                            ...previous,
                            weekday: Number(event.target.value),
                          }))
                        }
                      >
                        {weekDays.map((day, index) => (
                          <option key={day} value={index}>
                            {
                              [
                                "Domingo",
                                "Segunda-feira",
                                "Terça-feira",
                                "Quarta-feira",
                                "Quinta-feira",
                                "Sexta-feira",
                                "Sábado",
                              ][index]
                            }
                          </option>
                        ))}
                      </select>
                    </label>
                  )}
                  {draft.frequency === "monthly" && (
                    <label className="auto-field">
                      <span>Dia do mês</span>
                      <input
                        type="number"
                        min={1}
                        max={31}
                        value={draft.monthDay}
                        onChange={event =>
                          setDraft(previous => ({
                            ...previous,
                            monthDay: Number(event.target.value),
                          }))
                        }
                        required
                      />
                      <small>
                        Se o mês não tiver esse dia, o envio acontece no último
                        dia do mês.
                      </small>
                    </label>
                  )}
                  {draft.frequency === "custom" && (
                    <div className="auto-fields">
                      <label className="auto-field">
                        <span>Enviar a cada quantos dias?</span>
                        <input
                          type="number"
                          min={1}
                          max={90}
                          value={draft.intervalDays}
                          onChange={event =>
                            setDraft(previous => ({
                              ...previous,
                              intervalDays: Number(event.target.value),
                            }))
                          }
                          required
                        />
                      </label>
                      <label className="auto-field">
                        <span>Data do primeiro envio</span>
                        <input
                          type="date"
                          value={draft.startDate}
                          onChange={event =>
                            setDraft(previous => ({
                              ...previous,
                              startDate: event.target.value,
                            }))
                          }
                          required
                        />
                      </label>
                    </div>
                  )}
                  <TimezoneField
                    id="report"
                    value={draft.timezone}
                    onChange={value =>
                      setDraft(previous => ({ ...previous, timezone: value }))
                    }
                  />
                  <div className="schedule-explainer">
                    <Clock3 size={18} />
                    <p>
                      {draft.frequency === "daily"
                        ? "Cada relatório inclui o dia anterior, já encerrado."
                        : draft.frequency === "weekly"
                          ? "Cada relatório inclui os 7 dias anteriores ao envio."
                          : draft.frequency === "monthly"
                            ? "Cada relatório inclui o mês calendário anterior."
                            : `Cada relatório inclui os ${draft.intervalDays || "—"} dias anteriores ao envio, a partir da data escolhida.`}{" "}
                      Dias sem anotação permanecem como lacunas.
                    </p>
                  </div>
                  <label className="auto-check">
                    <input
                      type="checkbox"
                      checked={draft.includeNotes}
                      onChange={event =>
                        setDraft(previous => ({
                          ...previous,
                          includeNotes: event.target.checked,
                        }))
                      }
                    />
                    <span>Incluir notas pessoais nos envios automáticos</span>
                  </label>
                  <label className="auto-check">
                    <input
                      type="checkbox"
                      checked={draft.includeDetails}
                      onChange={event =>
                        setDraft(previous => ({
                          ...previous,
                          includeDetails: event.target.checked,
                        }))
                      }
                    />
                    <span>Incluir sono, medicação, sintomas e contexto</span>
                  </label>
                  <div className="schedule-recipients">
                    <strong>Destinatários desta programação</strong>
                    {settings.reports.recipients.length ? (
                      settings.reports.recipients.map(recipient => (
                        <p key={recipient.id}>
                          <Check size={14} />
                          <span>
                            {recipient.name} · {recipient.email}
                          </span>
                        </p>
                      ))
                    ) : (
                      <p>
                        Nenhum destinatário cadastrado. Salve sua rede de
                        cuidado primeiro.
                      </p>
                    )}
                  </div>
                  <label className="auto-check consent-box">
                    <input
                      type="checkbox"
                      checked={draft.consent}
                      onChange={event =>
                        setDraft(previous => ({
                          ...previous,
                          consent: event.target.checked,
                          enabled: event.target.checked
                            ? previous.enabled
                            : false,
                        }))
                      }
                    />
                    <span>
                      Autorizo o envio automático das informações selecionadas
                      para os destinatários listados.
                      <small>
                        E-mail não é um canal com criptografia de ponta a ponta.
                        Posso pausar novos envios, mas cópias já recebidas não
                        serão apagadas.
                      </small>
                    </span>
                  </label>
                  <div className="auto-footer">
                    <p>
                      Alterar destinatários pausa a programação até você
                      autorizar novamente.
                    </p>
                    <button className="primary-button" type="submit">
                      <SavingLabel saving={saving}>
                        <CalendarClock size={16} />
                        Salvar programação
                      </SavingLabel>
                    </button>
                  </div>
                </fieldset>
              </form>
            </section>
          )}
        </div>
        <aside className="reports-aside">
          <section className="card automation-card recipients-card">
            <div className="card-heading">
              <div>
                <h2>
                  <Users size={21} />
                  Sua rede de cuidado
                </h2>
                <p>Até 10 pessoas. Nenhum envio acontece ao cadastrar.</p>
              </div>
            </div>
            <form onSubmit={saveRecipients}>
              <fieldset
                className="auto-fieldset"
                disabled={disabled || (!localMode && !accountId)}
              >
                {recipients.map((recipient, index) => (
                  <div className="recipient-editor" key={recipient.id}>
                    <div className="recipient-editor-heading">
                      <span>Pessoa {index + 1}</span>
                      <button
                        type="button"
                        className="icon-button"
                        aria-label={`Remover destinatário ${index + 1}`}
                        onClick={() =>
                          setRecipients(previous =>
                            previous.filter(value => value.id !== recipient.id)
                          )
                        }
                      >
                        <Trash2 size={16} />
                      </button>
                    </div>
                    <label className="auto-field">
                      <span>Nome</span>
                      <input
                        value={recipient.name}
                        onChange={event =>
                          setRecipient(recipient.id, {
                            name: event.target.value,
                          })
                        }
                        maxLength={100}
                        required
                        placeholder="Como você chama essa pessoa?"
                      />
                    </label>
                    <label className="auto-field">
                      <span>E-mail</span>
                      <input
                        type="email"
                        value={recipient.email}
                        onChange={event =>
                          setRecipient(recipient.id, {
                            email: event.target.value,
                          })
                        }
                        maxLength={254}
                        required
                        placeholder="pessoa@exemplo.com"
                      />
                    </label>
                    <label className="auto-field">
                      <span>Relação com seu cuidado</span>
                      <select
                        value={recipient.role}
                        onChange={event =>
                          setRecipient(recipient.id, {
                            role: event.target.value as Recipient["role"],
                          })
                        }
                      >
                        {Object.entries(roles).map(([value, label]) => (
                          <option key={value} value={value}>
                            {label}
                          </option>
                        ))}
                      </select>
                    </label>
                  </div>
                ))}
                {!recipients.length && (
                  <div className="recipient-empty">
                    <Users size={30} />
                    <p>Quem você quer por perto?</p>
                    <small>
                      Adicionar uma pessoa não dá acesso à sua conta.
                    </small>
                  </div>
                )}
                <button
                  type="button"
                  className="outline-button add-recipient"
                  disabled={recipients.length >= 10}
                  onClick={() =>
                    setRecipients(previous => [
                      ...previous,
                      {
                        id: crypto.randomUUID(),
                        name: "",
                        email: "",
                        role: "psychologist",
                      },
                    ])
                  }
                >
                  <Plus size={16} />
                  Adicionar pessoa
                </button>
                <button className="primary-button" type="submit">
                  <SavingLabel saving={saving}>
                    Salvar destinatários
                  </SavingLabel>
                </button>
                <p className="auto-hint">
                  No modo local, nomes e e-mails ficam neste navegador, sem
                  criptografia. Mudanças na rede pausam os envios automáticos.
                </p>
              </fieldset>
            </form>
          </section>
          <section className="sharing-care">
            <ShieldCheck size={23} />
            <h3>Compartilhar também é uma escolha.</h3>
            <p>
              Nem toda anotação precisa sair do seu diário. Você escolhe o que
              faz sentido levar à consulta ou à sua família.
            </p>
            <p>Este recurso não é monitorado e não envia alertas de crise.</p>
          </section>
          {remote && (
            <section className="card automation-card delivery-card">
              <div className="card-heading">
                <h2>Histórico de entregas</h2>
                <button
                  className="icon-button"
                  aria-label="Atualizar histórico de entregas"
                  disabled={historyBusy}
                  onClick={refreshHistory}
                >
                  <RefreshCw size={17} className={historyBusy ? "spin" : ""} />
                </button>
              </div>
              {historyError && (
                <p className="auto-warning" role="alert">
                  {historyError}
                </p>
              )}
              {!deliveries.length && !historyError && (
                <p className="auto-hint">
                  Ainda não há entregas registradas pelo servidor.
                </p>
              )}
              {deliveries.slice(0, 10).map(delivery => (
                <div className="delivery-row" key={delivery.id}>
                  <span className={`delivery-status ${delivery.status}`}>
                    {
                      {
                        queued: "Na fila",
                        sent: "Aceito pelo provedor",
                        failed: "Falhou",
                        unknown: "Resultado inconclusivo",
                      }[delivery.status]
                    }
                  </span>
                  <strong>
                    {delivery.type === "report" ? "Relatório" : "Lembrete"} ·{" "}
                    {delivery.channel === "email" ? "E-mail" : "Push"}
                  </strong>
                  <small>{delivery.recipientLabel}</small>
                  <small>{formatDate(delivery.createdAt)}</small>
                  {delivery.message && <p>{delivery.message}</p>}
                </div>
              ))}
              <p className="auto-hint">
                “Aceito” não confirma leitura ou entrega na caixa de entrada.
                Não repetimos automaticamente envios de resultado inconclusivo.
              </p>
            </section>
          )}
        </aside>
      </div>
      {confirm && (
        <Modal
          open
          title={channels[confirm]}
          onClose={() => {
            if (!sending) setConfirm(null);
          }}
          wide
        >
          <div className="report-confirmation">
            <div className="auto-notice">
              <ShieldCheck size={20} />
              <div>
                <strong>Confira antes de compartilhar.</strong>
                <p>
                  {report.range} · {report.summary.count} registros.{" "}
                  {notes
                    ? "Notas pessoais incluídas."
                    : "Notas pessoais não incluídas."}{" "}
                  {details
                    ? "Sono, medicação, sintomas e contexto incluídos."
                    : "Detalhes de saúde não incluídos."}{" "}
                  Mídias não incluídas.
                </p>
              </div>
            </div>
            {confirm === "email" ? (
              <div className="confirmation-recipients">
                <strong>Será enviado individualmente para:</strong>
                {chosen.map(recipient => (
                  <p key={recipient.id}>
                    {recipient.name} — {recipient.email}
                  </p>
                ))}
                <p>
                  E-mail não tem criptografia de ponta a ponta. Confirme os
                  endereços.
                </p>
              </div>
            ) : (
              <p>
                {confirm === "share"
                  ? "Você escolherá o destinatário no menu do dispositivo. O conteúdo abaixo será compartilhado como arquivo ou texto, conforme a compatibilidade."
                  : "O relatório ficará fora do aplicativo como arquivo ou impressão. Proteja esse documento e escolha com cuidado quem poderá acessá-lo."}
              </p>
            )}
            <details className="confirmation-content">
              <summary>Ver todo o conteúdo autorizado</summary>
              <pre>{report.text}</pre>
            </details>
            <label className="auto-check consent-box">
              <input
                type="checkbox"
                checked={consent}
                disabled={sending}
                onChange={event => setConsent(event.target.checked)}
              />
              <span>
                Revisei o período, o conteúdo e{" "}
                {confirm === "email"
                  ? "os destinatários"
                  : "a forma de compartilhamento"}
                . Autorizo esta ação.
              </span>
            </label>
            <div className="modal-actions">
              <button
                className="outline-button"
                disabled={sending}
                onClick={() => setConfirm(null)}
              >
                Cancelar
              </button>
              <button
                className="primary-button"
                disabled={!consent || sending || dataError}
                onClick={shareReport}
              >
                {sending ? "Processando…" : channels[confirm]}
              </button>
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
}
