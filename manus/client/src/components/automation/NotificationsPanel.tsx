import { useEffect, useState } from "react";
import {
  Bell,
  BellOff,
  Plus,
  Trash2,
  Sun,
  Sunset,
  Moon,
  ShieldCheck,
} from "lucide-react";
import { toast } from "sonner";
import { request } from "@/lib/api";
import { periods } from "@/lib/diary";
import {
  weekDays,
  validateReminders,
  isQuiet,
  type ReminderSettings,
  type ReminderSlot,
} from "@/lib/automation";
import type { AutomationController } from "@/hooks/useAutomation";
import {
  TimezoneField,
  AutomationNotice,
  ScheduleBadge,
  SavingLabel,
} from "./Controls";

function vapidBytes(value: string) {
  const base64 = value.replace(/-/g, "+").replace(/_/g, "/");
  return Uint8Array.from(
    atob(base64 + "=".repeat((4 - (base64.length % 4)) % 4)),
    c => c.charCodeAt(0)
  );
}
export default function NotificationsPanel({
  controller,
  onDirtyChange,
}: {
  controller: AutomationController;
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
  const [draft, setDraft] = useState<ReminderSettings>(settings.reminders);
  const [subscribed, setSubscribed] = useState(false);
  const [browserSubscribed, setBrowserSubscribed] = useState(false);
  const [permission, setPermission] = useState<
    NotificationPermission | "unsupported"
  >(() =>
    typeof Notification === "undefined"
      ? "unsupported"
      : Notification.permission
  );
  const [pushBusy, setPushBusy] = useState(false);
  const [emailConsent, setEmailConsent] = useState(false);
  const supported =
    typeof window !== "undefined" &&
    "Notification" in window &&
    "serviceWorker" in navigator &&
    "PushManager" in window &&
    window.isSecureContext;
  const remote = !localMode && !!accountId;
  useEffect(() => {
    setDraft(settings.reminders);
    setEmailConsent(
      settings.reminders.enabled && settings.reminders.channel === "email"
    );
  }, [settings.reminders]);
  useEffect(() => {
    let active = true;
    setSubscribed(false);
    setBrowserSubscribed(false);
    if ("serviceWorker" in navigator)
      navigator.serviceWorker
        .getRegistration("/")
        .then(registration => registration?.pushManager?.getSubscription())
        .then(async subscription => {
          if (!active) return;
          setBrowserSubscribed(!!subscription);
          if (!subscription) return;
          const bytes = await crypto.subtle.digest(
            "SHA-256",
            new TextEncoder().encode(subscription.endpoint)
          );
          const hash = Array.from(new Uint8Array(bytes), byte =>
            byte.toString(16).padStart(2, "0")
          ).join("");
          if (active)
            setSubscribed(
              remote && !!capabilities.pushEndpointHashes?.includes(hash)
            );
        })
        .catch(() => {
          if (active) setSubscribed(false);
        });
    return () => {
      active = false;
    };
  }, [accountId, localMode, capabilities.pushEndpointHashes]);
  const dirty = JSON.stringify(draft) !== JSON.stringify(settings.reminders);
  useEffect(() => {
    onDirtyChange?.(dirty);
    return () => onDirtyChange?.(false);
  }, [dirty, onDirtyChange]);

  function update<K extends keyof ReminderSettings>(
    key: K,
    value: ReminderSettings[K]
  ) {
    setDraft(previous => ({ ...previous, [key]: value }));
  }
  function updateSlot(
    collection: "slots" | "custom",
    id: string,
    value: Partial<ReminderSlot>
  ) {
    setDraft(previous => ({
      ...previous,
      [collection]: previous[collection].map(slot =>
        slot.id === id ? { ...slot, ...value } : slot
      ),
    }));
  }
  async function save(event: React.FormEvent) {
    event.preventDefault();
    const invalid = validateReminders(draft);
    if (invalid) return toast.error(invalid);
    if (
      draft.enabled &&
      draft.channel === "push" &&
      (!subscribed || !remote || !capabilities.push)
    )
      return toast.error(
        "Ative as notificações neste dispositivo antes de habilitar os lembretes push."
      );
    if (
      draft.enabled &&
      draft.channel === "email" &&
      (!emailConsent || !remote || !capabilities.smtp)
    )
      return toast.error(
        "Autorize o uso do seu e-mail e confirme a configuração SMTP do servidor."
      );
    try {
      await controller.save({
        ...settings,
        reminders: { ...draft, email: draft.email.trim() },
      });
      toast.success(
        draft.enabled
          ? draft.channel === "in-app"
            ? "Lembretes salvos. Eles aparecem enquanto este aplicativo estiver aberto."
            : "Preferências salvas. A entrega depende do cron e do serviço configurados."
          : "Lembretes desativados."
      );
    } catch (err) {
      toast.error(
        err instanceof Error
          ? err.message
          : "Não foi possível salvar seus lembretes."
      );
    }
  }
  async function enablePush() {
    if (!supported || !remote || !capabilities.push)
      return toast.error(
        "Web Push exige navegador compatível, conta e servidor configurados. No iPhone, instale o app na Tela de Início."
      );
    setPushBusy(true);
    try {
      // A solicitação deve nascer diretamente do clique, antes de qualquer chamada de rede.
      const granted = await Notification.requestPermission();
      setPermission(granted);
      if (granted !== "granted") {
        toast.info(
          granted === "denied"
            ? "A permissão foi negada. Você pode alterá-la nas configurações do navegador."
            : "Você não autorizou as notificações. Nada foi ativado."
        );
        return;
      }
      let registration = await navigator.serviceWorker.getRegistration("/");
      if (!registration)
        registration = await navigator.serviceWorker.register("/sw.js", {
          scope: "/",
        });
      await navigator.serviceWorker.ready;
      let subscription = await registration.pushManager.getSubscription();
      const created = !subscription;
      if (!subscription)
        subscription = await registration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: vapidBytes(capabilities.vapidPublicKey),
        });
      try {
        await request("push_subscribe", {
          subscription: subscription.toJSON(),
        });
      } catch (err) {
        if (created) await subscription.unsubscribe();
        throw err;
      }
      setSubscribed(true);
      setBrowserSubscribed(true);
      toast.success(
        "Dispositivo inscrito. Escolha os horários e salve seus lembretes."
      );
    } catch (err) {
      toast.error(
        err instanceof Error
          ? err.message
          : "Não foi possível inscrever o dispositivo."
      );
    } finally {
      setPushBusy(false);
    }
  }
  async function disablePush() {
    setPushBusy(true);
    try {
      const registration = await navigator.serviceWorker.getRegistration("/");
      const subscription = await registration?.pushManager.getSubscription();
      if (subscription) {
        if (remote)
          await request("push_unsubscribe", {
            endpoint: subscription.endpoint,
          });
        await subscription.unsubscribe();
      }
      setSubscribed(false);
      setBrowserSubscribed(false);
      toast.success("Inscrição deste dispositivo removida.");
    } catch (err) {
      toast.error(
        err instanceof Error
          ? err.message
          : "Não foi possível remover a inscrição."
      );
    } finally {
      setPushBusy(false);
    }
  }
  const disabled =
    loading || saving || !!error || pushBusy || (!localMode && !accountId);
  const silenced = [...draft.slots, ...draft.custom].filter(
    slot => slot.enabled && isQuiet(slot.time, draft.quietStart, draft.quietEnd)
  );
  return (
    <section className="card automation-card notification-card">
      <div className="card-heading">
        <div>
          <h2>
            <Bell size={21} />
            Lembretes no seu ritmo
          </h2>
          <p>
            Um convite para se perceber. Sem cobrança e sem obrigatoriedade.
          </p>
        </div>
        <ScheduleBadge
          enabled={settings.reminders.enabled}
          local={localMode}
          capabilities={capabilities}
          inApp={settings.reminders.channel === "in-app"}
        />
      </div>
      {error && (
        <div className="error-banner" role="alert">
          {error}
          <button
            type="button"
            className="text-button"
            onClick={controller.refresh}
          >
            Tentar novamente
          </button>
        </div>
      )}
      <form onSubmit={save}>
        <fieldset disabled={disabled} className="auto-fieldset">
          <label className="setting-row auto-toggle">
            <span>
              <strong>Receber lembretes</strong>
              <p>
                Você pode pausar a qualquer momento. Nenhum alerta é enviado
                quando esta opção está desativada.
              </p>
            </span>
            <input
              type="checkbox"
              className="switch-control"
              checked={draft.enabled}
              onChange={event => update("enabled", event.target.checked)}
            />
          </label>
          <div className="auto-fields">
            <label className="auto-field">
              <span>Como você quer ser lembrado?</span>
              <select
                value={draft.channel}
                onChange={event =>
                  update(
                    "channel",
                    event.target.value as ReminderSettings["channel"]
                  )
                }
              >
                <option value="in-app">Aviso dentro do aplicativo</option>
                <option value="push" disabled={!remote || !capabilities.push}>
                  Notificação do dispositivo (push)
                </option>
                <option value="email" disabled={!remote || !capabilities.smtp}>
                  E-mail para mim
                </option>
              </select>
              <small>
                {draft.channel === "in-app"
                  ? "Funciona somente com o app aberto e visível. Não usa alertas do sistema."
                  : draft.channel === "push"
                    ? "Pode aparecer com o app fechado, se o sistema permitir. Requer Web Push e cron."
                    : "Enviado para seu próprio endereço. Não é enviado aos contatos dos relatórios."}
              </small>
            </label>
            <TimezoneField
              id="reminder"
              value={draft.timezone}
              onChange={value => update("timezone", value)}
            />
          </div>
          <div className="auto-section-heading">
            <h3>Seus momentos do dia</h3>
            <p>
              Os horários abaixo são sugestões. Ajuste cada um à sua rotina.
            </p>
          </div>
          <div className="reminder-slots">
            {draft.slots.map((slot, index) => {
              const Icon = [Sun, Sunset, Moon][index] || Bell;
              return (
                <div
                  key={slot.id}
                  className={`reminder-slot ${slot.enabled ? "selected" : ""}`}
                >
                  <label>
                    <Icon size={21} />
                    <span>
                      {periods.find(period => period.id === slot.period)
                        ?.label || "Lembrete"}
                    </span>
                    <input
                      type="checkbox"
                      checked={slot.enabled}
                      onChange={event =>
                        updateSlot("slots", slot.id, {
                          enabled: event.target.checked,
                        })
                      }
                      aria-label={`Ativar lembrete de ${periods.find(period => period.id === slot.period)?.label}`}
                    />
                  </label>
                  <input
                    type="time"
                    value={slot.time}
                    onChange={event =>
                      updateSlot("slots", slot.id, { time: event.target.value })
                    }
                    aria-label={`Horário do lembrete de ${periods.find(period => period.id === slot.period)?.label}`}
                    required
                  />
                </div>
              );
            })}
          </div>
          <div className="auto-section-heading with-action">
            <div>
              <h3>Horários personalizados</h3>
              <p>
                Até 6 horários adicionais, vinculados a um período ou ao diário
                em geral.
              </p>
            </div>
            <button
              type="button"
              className="outline-button"
              disabled={draft.custom.length >= 6}
              onClick={() =>
                update("custom", [
                  ...draft.custom,
                  {
                    id: crypto.randomUUID(),
                    time: "12:00",
                    period: null,
                    enabled: true,
                  },
                ])
              }
            >
              <Plus size={16} />
              Adicionar
            </button>
          </div>
          {draft.custom.map((slot, index) => (
            <div className="custom-reminder-row" key={slot.id}>
              <input
                type="checkbox"
                checked={slot.enabled}
                onChange={event =>
                  updateSlot("custom", slot.id, {
                    enabled: event.target.checked,
                  })
                }
                aria-label={`Ativar lembrete personalizado ${index + 1}`}
              />
              <label className="auto-field">
                <span>Horário {index + 1}</span>
                <input
                  type="time"
                  value={slot.time}
                  onChange={event =>
                    updateSlot("custom", slot.id, { time: event.target.value })
                  }
                  required
                />
              </label>
              <label className="auto-field">
                <span>Para qual registro?</span>
                <select
                  value={slot.period || ""}
                  onChange={event =>
                    updateSlot("custom", slot.id, {
                      period: event.target.value
                        ? (event.target.value as ReminderSlot["period"])
                        : null,
                    })
                  }
                >
                  <option value="">Diário em geral</option>
                  {periods.map(period => (
                    <option key={period.id} value={period.id}>
                      {period.label}
                    </option>
                  ))}
                </select>
              </label>
              <button
                type="button"
                className="icon-button"
                aria-label={`Remover horário personalizado ${index + 1}`}
                onClick={() =>
                  update(
                    "custom",
                    draft.custom.filter(item => item.id !== slot.id)
                  )
                }
              >
                <Trash2 size={18} />
              </button>
            </div>
          ))}
          <div className="auto-section-heading">
            <h3>Em quais dias?</h3>
          </div>
          <div
            className="weekday-picker"
            role="group"
            aria-label="Dias dos lembretes"
          >
            {weekDays.map((day, index) => (
              <button
                key={day}
                type="button"
                aria-pressed={draft.days.includes(index)}
                className={draft.days.includes(index) ? "selected" : ""}
                onClick={() =>
                  update(
                    "days",
                    draft.days.includes(index)
                      ? draft.days.filter(value => value !== index)
                      : [...draft.days, index].sort()
                  )
                }
              >
                {day}
              </button>
            ))}
          </div>
          <div className="auto-fields quiet-fields">
            <label className="auto-field">
              <span>Silêncio a partir de</span>
              <input
                type="time"
                value={draft.quietStart}
                onChange={event => update("quietStart", event.target.value)}
                required
              />
            </label>
            <label className="auto-field">
              <span>Voltar a lembrar às</span>
              <input
                type="time"
                value={draft.quietEnd}
                onChange={event => update("quietEnd", event.target.value)}
                required
              />
            </label>
          </div>
          <p className="auto-hint">
            Nenhum lembrete é enviado dentro do período de silêncio. Use
            horários iguais para desativar essa pausa.
          </p>
          {silenced.length > 0 && (
            <p className="auto-warning" role="status">
              {silenced.length === 1
                ? "Um horário está"
                : `${silenced.length} horários estão`}{" "}
              dentro do silêncio e não{" "}
              {silenced.length === 1 ? "será enviado" : "serão enviados"}.
            </p>
          )}
          <label className="auto-check">
            <input
              type="checkbox"
              checked={draft.skipRecorded}
              onChange={event => update("skipRecorded", event.target.checked)}
            />
            <span>
              Não lembrar períodos já registrados
              <small>
                Para um lembrete do diário em geral, a pausa acontece quando os
                três períodos do dia já estiverem anotados.
              </small>
            </span>
          </label>
          {draft.channel === "email" && (
            <div className="email-reminder">
              <label className="auto-field">
                <span>Seu e-mail para os lembretes</span>
                <input
                  type="email"
                  value={draft.email}
                  onChange={event => update("email", event.target.value)}
                  maxLength={254}
                  placeholder="voce@exemplo.com"
                  autoComplete="email"
                  required={draft.enabled}
                />
              </label>
              <label className="auto-check">
                <input
                  type="checkbox"
                  checked={emailConsent}
                  onChange={event => setEmailConsent(event.target.checked)}
                />
                <span>
                  Autorizo os lembretes neste endereço, que pertence a mim.
                </span>
              </label>
            </div>
          )}
          {draft.channel === "push" && (
            <div className="push-device">
              <div>
                <ShieldCheck size={20} />
                <strong>
                  {subscribed
                    ? "Este dispositivo está inscrito"
                    : "Este dispositivo ainda não está inscrito"}
                </strong>
                <p>
                  Permissão:{" "}
                  {permission === "granted"
                    ? "concedida"
                    : permission === "denied"
                      ? "negada"
                      : permission === "unsupported"
                        ? "indisponível"
                        : "ainda não solicitada"}
                  . No iPhone/iPad, abra o app instalado na Tela de Início (iOS
                  16.4 ou posterior).
                </p>
              </div>
              <button
                type="button"
                className="outline-button"
                onClick={subscribed ? disablePush : enablePush}
                disabled={
                  !supported || pushBusy || !remote || !capabilities.push
                }
              >
                {subscribed ? <BellOff size={16} /> : <Bell size={16} />}
                {subscribed
                  ? "Remover deste dispositivo"
                  : "Ativar neste dispositivo"}
              </button>
            </div>
          )}
          {draft.channel === "push" && browserSubscribed && !subscribed && (
            <button
              type="button"
              className="outline-button"
              onClick={disablePush}
              disabled={pushBusy}
            >
              Remover inscrição anterior deste navegador
            </button>
          )}
          {draft.channel !== "in-app" && (
            <AutomationNotice
              localMode={localMode}
              capabilities={capabilities}
              kind={draft.channel === "push" ? "push" : "email"}
            />
          )}
          <div className="auto-footer">
            <p>
              O texto do lembrete não revela humor ou notas. O sistema pode
              exibir o nome do aplicativo.
            </p>
            <button className="primary-button" type="submit">
              <SavingLabel saving={saving}>
                <Bell size={16} />
                Salvar lembretes
              </SavingLabel>
            </button>
          </div>
        </fieldset>
      </form>
    </section>
  );
}
