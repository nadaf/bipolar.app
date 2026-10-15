import { Clock3, Info, Loader2 } from "lucide-react";
import { cronIsFresh, type Capabilities } from "@/lib/automation";
export function TimezoneField({
  value,
  onChange,
  id,
}: {
  value: string;
  onChange: (value: string) => void;
  id: string;
}) {
  const zones = [
    ...new Set([
      value,
      "America/Sao_Paulo",
      "America/Manaus",
      "America/Belem",
      "America/Noronha",
      "America/Rio_Branco",
      "Europe/Lisbon",
      "UTC",
    ]),
  ];
  return (
    <label className="auto-field">
      <span>Fuso dos horários</span>
      <input
        value={value}
        onChange={e => onChange(e.target.value)}
        list={`${id}-zones`}
        placeholder="America/Sao_Paulo"
        maxLength={100}
        required
      />
      <datalist id={`${id}-zones`}>
        {zones.map(zone => (
          <option key={zone} value={zone} />
        ))}
      </datalist>
      <small>
        Use um fuso IANA, como America/Sao_Paulo. Os horários respeitam esse
        fuso.
      </small>
    </label>
  );
}
export function AutomationNotice({
  localMode,
  capabilities,
  kind = "email",
}: {
  localMode: boolean;
  capabilities: Capabilities;
  kind?: "email" | "push";
}) {
  const configured = kind === "email" ? capabilities.smtp : capabilities.push;
  return (
    <div className="auto-notice">
      <Info size={18} />
      <div>
        {localMode ? (
          <>
            <strong>Você está no modo local.</strong>
            <p>
              Compartilhamento manual funciona neste dispositivo. E-mails
              automáticos e notificações com o app fechado precisam da conta, do
              servidor e do cron configurados.
            </p>
          </>
        ) : !configured ? (
          <>
            <strong>
              {kind === "email"
                ? "O envio de e-mail ainda não foi configurado."
                : "Web Push ainda não foi configurado."}
            </strong>
            <p>
              O administrador deve configurar{" "}
              {kind === "email" ? "SMTP com TLS" : "as chaves VAPID"} e o cron
              no cPanel. Não há entrega simulada.
            </p>
          </>
        ) : !cronIsFresh(capabilities.cronLastRun) ? (
          <>
            <strong>Aguardando a execução do cron.</strong>
            <p>
              O serviço não informou uma execução recente. Salvar preferências
              não confirma que envios automáticos estejam ocorrendo.
            </p>
          </>
        ) : (
          <>
            <strong>O servidor informou uma execução recente.</strong>
            <p>
              Envios dependem de conectividade e do provedor. Notificações não
              são alarmes de emergência.
            </p>
          </>
        )}
      </div>
    </div>
  );
}
export function SavingLabel({
  saving,
  children,
}: {
  saving: boolean;
  children: React.ReactNode;
}) {
  return (
    <>
      {saving ? (
        <>
          <Loader2 size={16} className="spin" />
          Salvando…
        </>
      ) : (
        children
      )}
    </>
  );
}
export function ScheduleBadge({
  enabled,
  local,
  capabilities,
  inApp = false,
}: {
  enabled: boolean;
  local: boolean;
  capabilities: Capabilities;
  inApp?: boolean;
}) {
  return (
    <span className={`automation-badge ${enabled ? "enabled" : ""}`}>
      <Clock3 size={13} />
      {!enabled
        ? "Desativado"
        : inApp
          ? "Com o app aberto"
          : local
            ? "Requer servidor"
            : cronIsFresh(capabilities.cronLastRun)
              ? "Programação habilitada"
              : "Aguardando cron"}
    </span>
  );
}
