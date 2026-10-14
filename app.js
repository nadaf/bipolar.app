"use strict";

const STORAGE_KEY = "bipolar.app.entries.v1";
const SETTINGS_KEY = "bipolar.app.settings.v1";
const MEDIA_DB_NAME = "bipolar.app.media.v1";
const REMINDER_PERIODS = {
  morning: { label: "manhã", time: "08:00" },
  afternoon: { label: "tarde", time: "14:00" },
  evening: { label: "noite", time: "20:00" }
};
const moods = [
  { score: 1, label: "Depressão intensa", short: "Depressão intensa", color: "#9589ad", bg: "#f1eef5", emoji: "◌" },
  { score: 2, label: "Depressão", short: "Depressão", color: "#858cad", bg: "#eef0f7", emoji: "◒" },
  { score: 3, label: "Humor baixo", short: "Humor baixo", color: "#829d9a", bg: "#edf3f1", emoji: "◡" },
  { score: 4, label: "Estável", short: "Estável", color: "#789679", bg: "#edf3ec", emoji: "○" },
  { score: 5, label: "Humor elevado", short: "Humor elevado", color: "#b1a36e", bg: "#f5f2e8", emoji: "◠" },
  { score: 6, label: "Muito elevado", short: "Muito elevado", color: "#bf9673", bg: "#f7f0e9", emoji: "◉" },
  { score: 7, label: "Euforia", short: "Euforia", color: "#bd8179", bg: "#f7eeec", emoji: "✳" }
];

const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
const entryDialog = $("#entry-dialog");
const reportDialog = $("#report-dialog");
const entryForm = $("#entry-form");
const toast = $("#toast");
let toastTimer;
let installPrompt = null;
let activeMediaUrl = "";

function localDate(date = new Date()) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

function readEntries() {
  try {
    const stored = JSON.parse(localStorage.getItem(STORAGE_KEY) || "[]");
    return Array.isArray(stored) ? stored.filter((item) => item && Number.isInteger(item.score) && item.date) : [];
  } catch (error) {
    console.error("Não foi possível ler os registros locais.", error);
    notify("Não foi possível carregar seus registros. Evite limpar os dados do navegador.");
    return [];
  }
}

function saveEntries(entries) {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(entries));
    return true;
  } catch (error) {
    console.error("Não foi possível salvar os registros locais.", error);
    notify("Não foi possível salvar. O armazenamento do navegador pode estar cheio.");
    return false;
  }
}

function readSettings() {
  try {
    const settings = JSON.parse(localStorage.getItem(SETTINGS_KEY) || "{}");
    return settings && typeof settings === "object" && !Array.isArray(settings) ? settings : {};
  } catch (error) {
    console.error("Não foi possível ler as preferências locais.", error);
    return {};
  }
}

function saveSettings(settings) {
  try {
    localStorage.setItem(SETTINGS_KEY, JSON.stringify(settings));
    return true;
  } catch (error) {
    console.error("Não foi possível salvar as preferências locais.", error);
    notify("Não foi possível salvar sua preferência neste navegador.");
    return false;
  }
}

function openMediaDatabase() {
  return new Promise((resolve, reject) => {
    if (!("indexedDB" in window)) {
      reject(new Error("Este navegador não oferece armazenamento local para anexos."));
      return;
    }
    const request = indexedDB.open(MEDIA_DB_NAME, 1);
    request.onupgradeneeded = () => {
      if (!request.result.objectStoreNames.contains("media")) {
        request.result.createObjectStore("media", { keyPath: "id" });
      }
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error || new Error("Não foi possível abrir o armazenamento de anexos."));
  });
}

async function storeMedia(id, file) {
  const database = await openMediaDatabase();
  return new Promise((resolve, reject) => {
    const transaction = database.transaction("media", "readwrite");
    transaction.objectStore("media").put({ id, blob: file, type: file.type, name: file.name });
    transaction.oncomplete = () => { database.close(); resolve(); };
    transaction.onerror = () => { database.close(); reject(transaction.error || new Error("Não foi possível salvar o anexo.")); };
    transaction.onabort = () => { database.close(); reject(transaction.error || new Error("O armazenamento do anexo foi cancelado.")); };
  });
}

async function readMedia(id) {
  const database = await openMediaDatabase();
  return new Promise((resolve, reject) => {
    const transaction = database.transaction("media", "readonly");
    const request = transaction.objectStore("media").get(id);
    request.onsuccess = () => { database.close(); resolve(request.result || null); };
    request.onerror = () => { database.close(); reject(request.error || new Error("Não foi possível abrir o anexo.")); };
  });
}

async function removeMedia(id) {
  const database = await openMediaDatabase();
  return new Promise((resolve, reject) => {
    const transaction = database.transaction("media", "readwrite");
    transaction.objectStore("media").delete(id);
    transaction.oncomplete = () => { database.close(); resolve(); };
    transaction.onerror = () => { database.close(); reject(transaction.error || new Error("Não foi possível excluir o anexo.")); };
    transaction.onabort = () => { database.close(); reject(transaction.error || new Error("A exclusão do anexo foi cancelada.")); };
  });
}

function notify(message) {
  toast.textContent = message;
  toast.classList.add("show");
  window.clearTimeout(toastTimer);
  toastTimer = window.setTimeout(() => toast.classList.remove("show"), 3600);
}

function formatDate(dateString, options = { day: "numeric", month: "short" }) {
  return new Intl.DateTimeFormat("pt-BR", options).format(new Date(`${dateString}T12:00:00`));
}

function moodFor(score) {
  return moods.find((mood) => mood.score === score) || moods[3];
}

function renderMoodOptions() {
  $("#mood-options").innerHTML = moods.map((mood) => `
    <div class="mood-option">
      <input id="mood-${mood.score}" name="score" type="radio" value="${mood.score}" required>
      <label for="mood-${mood.score}">
        <span class="mood-swatch mood-${mood.score}" aria-hidden="true"></span>
        <span>${mood.label}</span>
      </label>
    </div>`).join("");
}

function renderToday(entries) {
  const today = localDate();
  const todaysEntries = entries.filter((entry) => entry.date === today).sort((a, b) => a.period.localeCompare(b.period));
  $("#today-entries").innerHTML = todaysEntries.length
    ? todaysEntries.map((entry) => {
      const mood = moodFor(entry.score);
      return `<span class="entry-chip"><i class="mood-swatch mood-${mood.score}"></i>${escapeHtml(entry.period)} · ${escapeHtml(mood.short)}</span>`;
    }).join("")
    : '<p class="empty-today">Nenhum período anotado hoje. Você pode começar quando quiser.</p>';
}

function renderWeek(entries) {
  const chart = $("#week-chart");
  const days = Array.from({ length: 7 }, (_, index) => {
    const date = new Date();
    date.setDate(date.getDate() - (6 - index));
    return localDate(date);
  });
  chart.innerHTML = days.map((date) => {
    const dayEntries = entries.filter((entry) => entry.date === date);
    const dayLabel = new Intl.DateTimeFormat("pt-BR", { weekday: "short" }).format(new Date(`${date}T12:00:00`)).replace(".", "");
    const bars = dayEntries.slice(0, 5).map((entry) => {
      const range = entry.score <= 2 ? "low" : entry.score >= 7 ? "very-high" : entry.score >= 5 ? "high" : "";
      return `<span class="chart-bar ${range}" title="${escapeHtml(moodFor(entry.score).label)} · ${escapeHtml(entry.period)}"></span>`;
    }).join("");
    const content = bars || '<span class="chart-placeholder" aria-hidden="true"></span>';
    return `<div class="chart-day" aria-label="${escapeHtml(dayLabel)}: ${dayEntries.length} registro(s)"><div class="chart-column">${content}</div><span>${escapeHtml(dayLabel)}</span></div>`;
  }).join("");
}

function renderRecent(entries) {
  const recent = [...entries].sort((a, b) => `${b.date}${b.createdAt || ""}`.localeCompare(`${a.date}${a.createdAt || ""}`)).slice(0, 5);
  $("#recent-entries").innerHTML = recent.length ? recent.map((entry) => {
    const mood = moodFor(entry.score);
    const mediaAction = entry.mediaId
      ? `<button class="entry-action" type="button" data-action="media" data-entry-id="${escapeHtml(entry.id)}" aria-label="Abrir anexo ${escapeHtml(entry.mediaName || "de áudio ou vídeo")}">Ver anexo</button>`
      : "";
    return `<div class="recent-row">
      <span class="recent-mood mood-${mood.score}" aria-hidden="true">${mood.emoji}</span>
      <div class="recent-info"><strong>${escapeHtml(mood.label)}</strong><span>${escapeHtml(entry.period)}${entry.note ? ` · ${escapeHtml(entry.note.slice(0, 72))}${entry.note.length > 72 ? "…" : ""}` : ""}${entry.mediaId ? " · com áudio/vídeo" : ""}</span></div>
      <div class="recent-actions"><span class="recent-time">${formatDate(entry.date)}</span>${mediaAction}<button class="entry-action entry-delete" type="button" data-action="delete" data-entry-id="${escapeHtml(entry.id)}" aria-label="Excluir registro de ${escapeHtml(formatDate(entry.date))} (${escapeHtml(entry.period)})">Excluir</button></div>
    </div>`;
  }).join("") : '<p class="recent-empty">Quando fizer seu primeiro registro, ele aparecerá aqui.</p>';
}

function renderStreak(entries) {
  const dates = new Set(entries.map((entry) => entry.date));
  let count = 0;
  const day = new Date();
  if (!dates.has(localDate(day))) day.setDate(day.getDate() - 1);
  while (dates.has(localDate(day))) {
    count += 1;
    day.setDate(day.getDate() - 1);
  }
  $("#streak-count").textContent = `${count} ${count === 1 ? "dia" : "dias"}`;
}

function render() {
  const entries = readEntries();
  renderToday(entries);
  renderWeek(entries);
  renderRecent(entries);
  renderStreak(entries);
}

function escapeHtml(value) {
  return String(value).replace(/[&<>"']/g, (character) => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
  })[character]);
}

function openEntryDialog() {
  entryForm.reset();
  entryForm.elements.date.value = localDate();
  $("#file-name").textContent = "Nenhum arquivo selecionado";
  entryDialog.showModal();
}

async function onEntrySubmit(event) {
  event.preventDefault();
  if (!entryForm.reportValidity()) return;
  const formData = new FormData(entryForm);
  const file = formData.get("media");
  const hasFile = file instanceof File && file.size > 0;
  if (hasFile && file.size > 25 * 1024 * 1024) {
    notify("O arquivo excede o limite de 25 MB desta versão.");
    return;
  }
  if (hasFile && !file.type.startsWith("audio/") && !file.type.startsWith("video/")) {
    notify("Escolha um arquivo de áudio ou vídeo compatível.");
    return;
  }
  const entries = readEntries();
  const id = crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`;
  const entry = {
    id,
    date: String(formData.get("date")),
    period: String(formData.get("period")),
    score: Number(formData.get("score")),
    note: String(formData.get("note") || "").trim(),
    sleep: String(formData.get("sleep") || ""),
    energy: String(formData.get("energy") || ""),
    mediaId: hasFile ? id : "",
    mediaName: hasFile ? file.name : "",
    createdAt: new Date().toISOString()
  };
  if (!entry.score || !entry.date || !entry.period) {
    notify("Confira o humor, o dia e o período antes de salvar.");
    return;
  }
  if (hasFile) {
    try {
      await storeMedia(id, file);
    } catch (error) {
      console.error("Não foi possível armazenar o anexo localmente.", error);
      notify("O anexo não pôde ser salvo neste navegador. Tente outro arquivo ou salve sem mídia.");
      return;
    }
  }
  entries.push(entry);
  if (!saveEntries(entries)) {
    if (hasFile) {
      try { await removeMedia(id); }
      catch (error) { console.error("Não foi possível remover o anexo após falha ao salvar o registro.", error); }
    }
    return;
  }
  entryDialog.close();
  render();
  notify("Anotação salva neste navegador.");
}

function reportText(range, recipient, startDate, endDate) {
  const all = readEntries().sort((a, b) => `${a.date}${a.createdAt || ""}`.localeCompare(`${b.date}${b.createdAt || ""}`));
  const cutoff = new Date();
  cutoff.setDate(cutoff.getDate() - Number(range) + 1);
  cutoff.setHours(0, 0, 0, 0);
  const filtered = range === "all" ? all : range === "custom"
    ? all.filter((entry) => entry.date >= startDate && entry.date <= endDate)
    : all.filter((entry) => new Date(`${entry.date}T12:00:00`) >= cutoff);
  const lines = [
    "Resumo pessoal de acompanhamento de humor",
    `Gerado em ${new Intl.DateTimeFormat("pt-BR", { dateStyle: "long" }).format(new Date())}`,
    recipient ? `Para conversar com: ${recipient}` : "",
    "",
    "Este registro é subjetivo e não representa diagnóstico.",
    "Nível | Dia | Período | Sono (h) | Energia | Nota",
    ...filtered.map((entry) => [
      moodFor(entry.score).label, formatDate(entry.date, { day: "2-digit", month: "2-digit", year: "numeric" }),
      entry.period, entry.sleep || "—", entry.energy || "—", entry.note || "—"
    ].join(" | ")),
    "",
    `${filtered.length} registro(s). Compartilhe apenas por um canal em que você confia.`
  ].filter((line) => line !== undefined);
  return lines.join("\n");
}

async function onReportSubmit(event) {
  event.preventDefault();
  const data = new FormData(event.currentTarget);
  const range = String(data.get("range"));
  const startDate = String(data.get("startDate") || "");
  const endDate = String(data.get("endDate") || "");
  if (range === "custom" && (!startDate || !endDate || startDate > endDate)) {
    notify("Escolha um intervalo válido para o resumo.");
    return;
  }
  const text = reportText(range, String(data.get("recipient") || "").trim(), startDate, endDate);
  if (!readEntries().length) {
    notify("Ainda não há registros para incluir no resumo.");
    return;
  }
  reportDialog.close();
  if (navigator.share) {
    try {
      await navigator.share({ title: "Meu resumo de acompanhamento", text });
      return;
    } catch (error) {
      if (error.name === "AbortError") return;
      console.error("Não foi possível abrir o compartilhamento nativo.", error);
    }
  }
  const blob = new Blob([text], { type: "text/plain;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = `resumo-humor-${localDate()}.txt`;
  link.click();
  URL.revokeObjectURL(url);
  notify("Resumo criado. Revise o arquivo antes de compartilhar.");
}

async function openMedia(entryId) {
  const entry = readEntries().find((item) => item.id === entryId);
  if (!entry || !entry.mediaId) return;
  try {
    const media = await readMedia(entry.mediaId);
    if (!media) {
      notify("Este anexo não está mais disponível neste navegador.");
      return;
    }
    activeMediaUrl = URL.createObjectURL(media.blob);
    $("#media-title").textContent = media.name || "Seu registro";
    const player = document.createElement(media.type.startsWith("video/") ? "video" : "audio");
    player.className = "media-player-content";
    player.controls = true;
    player.preload = "metadata";
    player.src = activeMediaUrl;
    if (player instanceof HTMLVideoElement) player.playsInline = true;
    $("#media-player").replaceChildren(player);
    $("#media-dialog").showModal();
  } catch (error) {
    console.error("Não foi possível abrir o anexo local.", error);
    notify("Não foi possível abrir este anexo neste navegador.");
  }
}

async function deleteEntry(entryId) {
  const entries = readEntries();
  const entry = entries.find((item) => item.id === entryId);
  if (!entry || !window.confirm("Excluir este registro deste navegador? Esta ação não pode ser desfeita.")) return;
  const updated = entries.filter((item) => item.id !== entryId);
  if (!saveEntries(updated)) return;
  if (entry.mediaId) {
    try { await removeMedia(entry.mediaId); }
    catch (error) {
      console.error("Não foi possível excluir o anexo local.", error);
      notify("O registro foi removido, mas o anexo não pôde ser excluído do armazenamento.");
      render();
      return;
    }
  }
  render();
  notify("Registro excluído deste navegador.");
}

async function saveReminder() {
  const enabled = $("#reminder-toggle").checked;
  const period = $("#reminder-period").value;
  const time = period === "custom" ? $("#reminder-time").value : REMINDER_PERIODS[period]?.time;
  if (!time) {
    notify("Escolha um período ou horário válido para o lembrete.");
    return;
  }
  if (enabled && !("Notification" in window)) {
    notify("Este navegador não oferece notificações. Você ainda pode usar o app sem elas.");
    return;
  }
  if (enabled && Notification.permission === "default") {
    let permission;
    try {
      permission = await Notification.requestPermission();
    } catch (error) {
      console.error("Não foi possível solicitar permissão de notificações.", error);
      $("#reminder-toggle").checked = false;
      $("#reminder-time").disabled = true;
      $("#reminder-status").textContent = "Não foi possível ativar notificações neste navegador.";
      notify("Não foi possível solicitar permissão de notificações.");
      return;
    }
    if (permission !== "granted") {
      $("#reminder-toggle").checked = false;
      $("#reminder-time").disabled = true;
      $("#reminder-status").textContent = "Sem permissão para notificações. Nenhum lembrete foi ativado.";
      notify("Permissão não concedida. Você pode ativar manualmente nas configurações do navegador.");
      return;
    }
  }
  if (enabled && Notification.permission !== "granted") {
    notify("Notificações bloqueadas nas configurações do navegador.");
    return;
  }
  const settings = { reminderEnabled: enabled, reminderPeriod: period, reminderTime: time };
  if (!saveSettings(settings)) return;
  $("#reminder-status").textContent = enabled
    ? `Preferência salva para ${REMINDER_PERIODS[period]?.label || time} (${time}). Lembretes só funcionam enquanto o app estiver aberto nesta versão.`
    : "Lembretes desativados. Você pode mudar isso quando quiser.";
  if (enabled) scheduleReminder(time);
  else window.clearTimeout(reminderTimer);
  notify(enabled ? "Preferência de lembrete salva." : "Lembretes desativados.");
}

let reminderTimer;
function scheduleReminder(time) {
  window.clearTimeout(reminderTimer);
  if (!time || Notification.permission !== "granted" || !readSettings().reminderEnabled) return;
  const [hours, minutes] = time.split(":").map(Number);
  const next = new Date();
  next.setHours(hours, minutes, 0, 0);
  if (next <= new Date()) next.setDate(next.getDate() + 1);
  reminderTimer = window.setTimeout(() => {
    if (readSettings().reminderEnabled) {
      try {
        new Notification("Um lembrete gentil", { body: "Se fizer sentido para você, anote como está se sentindo.", icon: "./icon.svg" });
      } catch (error) {
        console.error("Não foi possível exibir a notificação.", error);
        notify("Não foi possível exibir o lembrete. Confira as permissões do navegador.");
      }
    }
    scheduleReminder(time);
  }, next.getTime() - Date.now());
}

function loadReminder() {
  const settings = readSettings();
  const enabled = Boolean(settings.reminderEnabled);
  const period = settings.reminderPeriod === "custom" || Object.hasOwn(REMINDER_PERIODS, settings.reminderPeriod)
    ? settings.reminderPeriod : "evening";
  $("#reminder-toggle").checked = enabled;
  $("#reminder-period").value = period;
  $("#reminder-time").disabled = !enabled || period !== "custom";
  $("#reminder-time").value = settings.reminderTime || REMINDER_PERIODS[period]?.time || "20:00";
  $("#reminder-status").textContent = enabled
    ? `Preferência salva para ${REMINDER_PERIODS[period]?.label || settings.reminderTime} (${settings.reminderTime || "20:00"}). Lembretes só funcionam enquanto o app estiver aberto nesta versão.`
    : "Desativados. Você pode mudar isso quando quiser.";
  if (enabled && Notification.permission === "granted") scheduleReminder(settings.reminderTime || "20:00");
}

function activateNavigation() {
  $$(".nav-link, .mobile-nav a").forEach((link) => {
    link.addEventListener("click", () => {
      const target = link.getAttribute("href");
      $$(".nav-link, .mobile-nav a").forEach((item) => {
        const active = item.getAttribute("href") === target;
        item.classList.toggle("active", active);
        if (active) item.setAttribute("aria-current", "page");
        else item.removeAttribute("aria-current");
      });
    });
  });
}

function setupInstallPrompt() {
  window.addEventListener("beforeinstallprompt", (event) => {
    event.preventDefault();
    installPrompt = event;
    $("#install-button").hidden = false;
  });
  $("#install-button").addEventListener("click", async () => {
    if (!installPrompt) return;
    installPrompt.prompt();
    await installPrompt.userChoice;
    installPrompt = null;
    $("#install-button").hidden = true;
  });
  window.addEventListener("appinstalled", () => notify("Aplicativo instalado neste dispositivo."));
}

function init() {
  $("#today-label").textContent = new Intl.DateTimeFormat("pt-BR", { weekday: "long", day: "numeric", month: "long" }).format(new Date());
  renderMoodOptions();
  render();
  loadReminder();
  activateNavigation();
  setupInstallPrompt();
  $("#new-entry-button").addEventListener("click", openEntryDialog);
  entryForm.addEventListener("submit", onEntrySubmit);
  $("#report-button").addEventListener("click", () => reportDialog.showModal());
  $("#report-form").addEventListener("submit", onReportSubmit);
  $("#report-form [name='range']").addEventListener("change", (event) => {
    const custom = event.currentTarget.value === "custom";
    $("#custom-range").hidden = !custom;
    $("#custom-range [name='startDate']").required = custom;
    $("#custom-range [name='endDate']").required = custom;
  });
  $("#recent-entries").addEventListener("click", (event) => {
    const button = event.target.closest("button[data-action]");
    if (!button) return;
    if (button.dataset.action === "media") openMedia(button.dataset.entryId);
    if (button.dataset.action === "delete") deleteEntry(button.dataset.entryId);
  });
  $("#reminder-toggle").addEventListener("change", () => {
    $("#reminder-period").disabled = !$("#reminder-toggle").checked;
    $("#reminder-time").disabled = !$("#reminder-toggle").checked || $("#reminder-period").value !== "custom";
  });
  $("#reminder-period").addEventListener("change", (event) => {
    const period = event.currentTarget.value;
    if (REMINDER_PERIODS[period]) $("#reminder-time").value = REMINDER_PERIODS[period].time;
    $("#reminder-time").disabled = !$("#reminder-toggle").checked || period !== "custom";
  });
  $("#save-reminder").addEventListener("click", saveReminder);
  $$("[data-close]").forEach((button) => button.addEventListener("click", () => button.closest("dialog").close()));
  $("#media-dialog").addEventListener("close", () => {
    const player = $("#media-player").firstElementChild;
    if (player) player.pause();
    $("#media-player").replaceChildren();
    if (activeMediaUrl) URL.revokeObjectURL(activeMediaUrl);
    activeMediaUrl = "";
  });
  $("#entry-form [name='media']").addEventListener("change", (event) => {
    const file = event.currentTarget.files[0];
    $("#file-name").textContent = file ? `${file.name} · ${Math.ceil(file.size / 1024 / 1024)} MB (não será anexado nesta versão)` : "Nenhum arquivo selecionado";
  });
  if ("serviceWorker" in navigator && location.protocol.startsWith("http")) {
    navigator.serviceWorker.register("./sw.js").catch((error) => console.error("Não foi possível registrar o modo instalável.", error));
  }
}

init();
