import type { Attachment, Entry } from "./diary";
declare global {
  interface Window {
    BIPOLAR_CONFIG?: { serverEnabled: boolean; apiBase: string };
  }
}
export type User = { id: string; name: string; email: string | null };
export type Session = {
  user: User | null;
  csrf: string;
  providers: Record<"google" | "x" | "microsoft", boolean>;
};
export const serverEnabled = window.BIPOLAR_CONFIG?.serverEnabled === true;
const base = window.BIPOLAR_CONFIG?.apiBase || "/api/index.php";
let csrf = "";
export function apiUrl(action: string, params: Record<string, string> = {}) {
  return `${base}?${new URLSearchParams({ action, ...params })}`;
}
export async function request<T>(
  action: string,
  body?: unknown,
  params: Record<string, string> = {}
): Promise<T> {
  const form = body instanceof FormData;
  const response = await fetch(apiUrl(action, params), {
    method: body === undefined ? "GET" : "POST",
    credentials: "include",
    cache: "no-store",
    headers:
      body === undefined
        ? {}
        : {
            "X-CSRF-Token": csrf,
            ...(!form ? { "Content-Type": "application/json" } : {}),
          },
    body:
      body === undefined
        ? undefined
        : form
          ? (body as FormData)
          : JSON.stringify(body),
  });
  let data;
  try {
    data = await response.json();
  } catch {
    throw new Error(
      "O servidor não respondeu corretamente. Seu registro não foi confirmado."
    );
  }
  if (!response.ok)
    throw new Error(
      errorMessages[data.error] ||
        (response.status === 401
          ? "Sua sessão expirou. Entre novamente."
          : "Não foi possível concluir. Tente novamente.")
    );
  return data;
}
export async function getSession() {
  const session = await request<Session>("session");
  csrf = session.csrf;
  return session;
}
export async function getEntries() {
  return (await request<{ entries: Entry[] }>("entries")).entries;
}
export async function saveEntry(entry: Entry, existing = false) {
  return (
    await request<{ entry: Entry }>("entry", {
      ...entry,
      id: existing ? entry.id : undefined,
      attachments: undefined,
    })
  ).entry;
}
export async function uploadMedia(entryId: string, attachment: Attachment) {
  if (!attachment.blob) return attachment;
  const form = new FormData();
  form.set("entryId", entryId);
  form.set("clientId", attachment.id);
  form.set("mediaKind", attachment.type);
  form.set("consent", "1");
  form.set("file", attachment.blob, attachment.name);
  return (await request<{ attachment: Attachment }>("upload", form)).attachment;
}

const errorMessages: Record<string, string> = {
  report_too_large:
    "O relatório é muito grande para envio. Reduza o período ou desative notas e detalhes.",
  report_changed:
    "Os registros ou destinatários mudaram desde a prévia. Atualize o diário e revise o relatório antes de enviar.",
  invalid_report_period:
    "Escolha um intervalo válido de até 366 dias, sem datas futuras.",
  empty_report: "Não há registros no período selecionado.",
  invalid_recipient: "Confira os nomes e e-mails dos destinatários.",
  recipient_limit: "Escolha de 1 a 10 destinatários para este envio.",
  invalid_push_subscription:
    "A inscrição de notificações não é válida. Remova a inscrição e ative novamente neste dispositivo.",
  subscription_limit: "O limite de dispositivos inscritos foi atingido.",
  consent_required:
    "Revise as informações e autorize o envio antes de continuar.",
  automation_not_configured:
    "O serviço de envio ainda não foi configurado no servidor.",
  invalid_automation:
    "Confira destinatários, consentimento, fuso e horários das preferências.",
  invalid_subscription:
    "A inscrição de notificações não é válida. Ative novamente neste dispositivo.",
  subscription_conflict:
    "Esta inscrição pertence a outra conta. Remova a inscrição neste dispositivo e tente novamente.",
  invalid_report:
    "Confira o intervalo, os destinatários e o consentimento do relatório.",
  auth_required: "Sua sessão expirou. Entre novamente.",
  service_unavailable:
    "O backend ainda não está disponível. Verifique a configuração do servidor.",
  csrf_failed:
    "Sua sessão de segurança expirou. Atualize a página e tente novamente.",
  invalid_entry: "Confira a data, o humor e os campos preenchidos.",
  entry_conflict: "Já existe um registro nessa data e período.",
  not_found: "O registro não foi encontrado.",
  quota_exceeded: "O limite de mídia da sua conta foi atingido.",
  file_too_large: "O arquivo ultrapassa o limite de 25 MB.",
  file_type_not_allowed:
    "Formato não aceito. Use WebM, MP4, OGG, WAV, MP3 ou M4A.",
  rate_limited: "Muitas tentativas em pouco tempo. Aguarde e tente novamente.",
};
