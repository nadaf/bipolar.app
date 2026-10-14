"use strict";

const STORAGE_KEY = "bipolar.app.entries.v1";
const SETTINGS_KEY = "bipolar.app.settings.v1";
const MEDIA_DB_NAME = "bipolar.app.media.v1";
const CARE_KEY = "bipolar.app.care.v1";
const PRIVATE_DB_NAME = "bipolar.app.private.v1";
const PRIVATE_VAULT_ID = "personal-space-v1";
const PRIVATE_KDF_ITERATIONS = 310000;
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
let privateEncryptionKey = null;
let privateEntries = [];
let privateLockTimer;
let privateAttemptId = 0;
let medicationReminderTimers = [];

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
    if (attempt !== privateAttemptId || !$("#private-dialog").open) return;
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

function openPrivateDatabase() {
  return new Promise((resolve, reject) => {
    if (!("indexedDB" in window)) {
      reject(new Error("Este navegador não oferece armazenamento local."));
      return;
    }
    const request = indexedDB.open(PRIVATE_DB_NAME, 1);
    request.onupgradeneeded = () => {
      if (!request.result.objectStoreNames.contains("vault")) {
        request.result.createObjectStore("vault", { keyPath: "id" });
      }
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error || new Error("Não foi possível abrir o cofre local."));
  });
}

async function readPrivateVault() {
  const database = await openPrivateDatabase();
  return new Promise((resolve, reject) => {
    const request = database.transaction("vault", "readonly").objectStore("vault").get(PRIVATE_VAULT_ID);
    request.onsuccess = () => { database.close(); resolve(request.result || null); };
    request.onerror = () => { database.close(); reject(request.error || new Error("Não foi possível ler o cofre local.")); };
  });
}

async function writePrivateVault(record) {
  const database = await openPrivateDatabase();
  return new Promise((resolve, reject) => {
    const transaction = database.transaction("vault", "readwrite");
    transaction.objectStore("vault").put(record);
    transaction.oncomplete = () => { database.close(); resolve(); };
    transaction.onerror = () => { database.close(); reject(transaction.error || new Error("Não foi possível salvar o cofre local.")); };
    transaction.onabort = () => { database.close(); reject(transaction.error || new Error("O salvamento do cofre foi cancelado.")); };
  });
}

async function derivePrivateKey(passphrase, salt) {
  const material = await crypto.subtle.importKey(
    "raw",
    new TextEncoder().encode(passphrase.normalize("NFC")),
    "PBKDF2",
    false,
    ["deriveKey"]
  );
  return crypto.subtle.deriveKey(
    { name: "PBKDF2", salt, iterations: PRIVATE_KDF_ITERATIONS, hash: "SHA-256" },
    material,
    { name: "AES-GCM", length: 256 },
    false,
    ["encrypt", "decrypt"]
  );
}

async function encryptPrivatePayload(entries, key, salt) {
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const plaintext = new TextEncoder().encode(JSON.stringify({ entries }));
  const ciphertext = await crypto.subtle.encrypt({ name: "AES-GCM", iv }, key, plaintext);
  return {
    id: PRIVATE_VAULT_ID,
    salt,
    iv,
    ciphertext: new Uint8Array(ciphertext),
    iterations: PRIVATE_KDF_ITERATIONS
  };
}

async function decryptPrivatePayload(record, key) {
  if (!record || record.iterations !== PRIVATE_KDF_ITERATIONS) {
    throw new Error("Unsupported private vault version.");
  }
  const plaintext = await crypto.subtle.decrypt(
    { name: "AES-GCM", iv: record.iv },
    key,
    record.ciphertext
  );
  const parsed = JSON.parse(new TextDecoder().decode(plaintext));
  if (!parsed || !Array.isArray(parsed.entries)) throw new Error("Invalid private vault data.");
  return parsed.entries;
}

function setPrivateFormMode(isSetup) {
  $("#private-setup-fields").hidden = !isSetup;
  $("#private-unlock-fields").hidden = isSetup;
  $("#private-unlock-fields [name='passphrase']").required = !isSetup;
  $("#private-setup-fields [name='newPassphrase']").required = isSetup;
  $("#private-setup-fields [name='confirmPassphrase']").required = isSetup;
  $("#private-unlock-button").textContent = isSetup ? "Criar e abrir" : "Abrir";
  $("#private-dialog-title").textContent = isSetup ? "Criar espaço privado" : "Espaço privado";
}

async function openPrivateSpace() {
  if (!crypto.subtle || !window.isSecureContext) {
    notify("O espaço cifrado precisa de HTTPS ou localhost e suporte a Web Crypto.");
    return;
  }
  const form = $("#private-unlock-form");
  const attempt = ++privateAttemptId;
  form.reset();
  $("#private-error").textContent = "";
  $("#private-unlock-form").hidden = false;
  $("#private-vault-content").hidden = true;
  $("#private-dialog").showModal();
  try {
    const record = await readPrivateVault();
    if (attempt !== privateAttemptId || !$("#private-dialog").open) return;
    setPrivateFormMode(!record);
    if (record) $("#private-unlock-fields [name='passphrase']").focus();
    else $("#private-setup-fields [name='newPassphrase']").focus();
  } catch (error) {
    console.error("Não foi possível abrir o espaço privado.", error);
    $("#private-dialog").close();
    notify("Não foi possível acessar o armazenamento privado neste dispositivo.");
  }
}

async function unlockOrCreatePrivateSpace(event) {
  event.preventDefault();
  const form = event.currentTarget;
  if (!form.reportValidity()) return;
  const isSetup = !$("#private-setup-fields").hidden;
  const attempt = ++privateAttemptId;
  const passphrase = isSetup
    ? $("#private-setup-fields [name='newPassphrase']").value
    : $("#private-unlock-fields [name='passphrase']").value;
  if (isSetup && passphrase !== $("#private-setup-fields [name='confirmPassphrase']").value) {
    $("#private-error").textContent = "As frases-senha não coincidem.";
    return;
  }
  const button = $("#private-unlock-button");
  button.disabled = true;
  $("#private-error").textContent = "";
  try {
    if (isSetup) {
      const salt = crypto.getRandomValues(new Uint8Array(16));
      const key = await derivePrivateKey(passphrase, salt);
      if (attempt !== privateAttemptId || !$("#private-dialog").open) return;
      await writePrivateVault(await encryptPrivatePayload([], key, salt));
      if (attempt !== privateAttemptId || !$("#private-dialog").open) return;
      privateEncryptionKey = key;
      privateEntries = [];
    } else {
      const record = await readPrivateVault();
      if (!record) throw new Error("Private vault unavailable.");
      const key = await derivePrivateKey(passphrase, record.salt);
      const entries = await decryptPrivatePayload(record, key);
      if (attempt !== privateAttemptId || !$("#private-dialog").open) return;
      privateEntries = entries;
      privateEncryptionKey = key;
    }
    form.reset();
    $("#private-unlock-form").hidden = true;
    $("#private-vault-content").hidden = false;
    $("#private-dialog-title").textContent = "Espaço privado";
    $("#private-entry-form").reset();
    $("#private-entry-form").elements.date.value = localDate();
    renderPrivateEntries();
    touchPrivateLockTimer();
  } catch (error) {
    if (isSetup || error.name !== "OperationError") {
      console.error("Não foi possível desbloquear ou criar o espaço privado.", error);
    }
    $("#private-error").textContent = isSetup
      ? "Não foi possível criar o espaço neste navegador. Verifique o armazenamento disponível."
      : "Frase-senha incorreta ou cofre indisponível. Os dados não foram alterados.";
    form.reset();
  } finally {
    button.disabled = false;
  }
}

function renderPrivateEntries() {
  const target = $("#private-entries");
  const sorted = [...privateEntries].sort((a, b) => b.date.localeCompare(a.date));
  target.innerHTML = sorted.length ? sorted.map((entry) => {
    const details = [
      entry.connection && `Intimidade: ${entry.connection}`,
      entry.desire && `Desejo: ${entry.desire}`,
      entry.consent && `Limites: ${entry.consent}`,
      entry.impulse && `Impulsividade: ${entry.impulse}`,
      entry.note
    ].filter(Boolean);
    return `<article class="private-entry">
      <div class="private-entry-heading"><strong>${escapeHtml(formatDate(entry.date, { day: "numeric", month: "long", year: "numeric" }))}</strong><button class="entry-action entry-delete" type="button" data-private-delete="${escapeHtml(entry.id)}">Excluir</button></div>
      <p>${details.map(escapeHtml).join(" · ") || "Sem observações neste registro."}</p>
    </article>`;
  }).join("") : '<p class="care-empty">Este espaço está vazio. Você pode registrar algo quando quiser.</p>';
}

function touchPrivateLockTimer() {
  if (!privateEncryptionKey) return;
  window.clearTimeout(privateLockTimer);
  privateLockTimer = window.setTimeout(() => lockPrivateSpace(true), 5 * 60 * 1000);
}

function lockPrivateSpace(keepDialogOpen = false) {
  window.clearTimeout(privateLockTimer);
  privateAttemptId += 1;
  privateEncryptionKey = null;
  privateEntries = [];
  $("#private-vault-content").hidden = true;
  $("#private-unlock-form").hidden = false;
  $("#private-unlock-form").reset();
  $("#private-error").textContent = "";
  if (keepDialogOpen && $("#private-dialog").open) setPrivateFormMode(false);
  if (!keepDialogOpen && $("#private-dialog").open) $("#private-dialog").close();
  $("#private-entries").replaceChildren();
}

async function savePrivateEntries(entries) {
  if (!privateEncryptionKey) {
    notify("O espaço privado está bloqueado. Desbloqueie-o novamente.");
    return false;
  }
  const key = privateEncryptionKey;
  const attempt = privateAttemptId;
  try {
    const record = await readPrivateVault();
    if (!record) throw new Error("Private vault unavailable.");
    const encrypted = await encryptPrivatePayload(entries, key, record.salt);
    if (attempt !== privateAttemptId || !$("#private-dialog").open) return false;
    await writePrivateVault(encrypted);
    if (attempt !== privateAttemptId || !$("#private-dialog").open) return false;
    privateEntries = entries;
    renderPrivateEntries();
    touchPrivateLockTimer();
    return true;
  } catch (error) {
    console.error("Não foi possível salvar no espaço privado.", error);
    notify("Não foi possível salvar. Seus registros anteriores foram mantidos.");
    return false;
  }
}

async function addPrivateEntry(event) {
  event.preventDefault();
  const form = event.currentTarget;
  if (!form.reportValidity()) return;
  const values = new FormData(form);
  const entry = {
    id: crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`,
    date: String(values.get("date")),
    connection: String(values.get("connection") || ""),
    desire: String(values.get("desire") || ""),
    consent: String(values.get("consent") || ""),
    impulse: String(values.get("impulse") || ""),
    note: String(values.get("note") || "").trim()
  };
  if (await savePrivateEntries([...privateEntries, entry])) {
    form.reset();
    form.elements.date.value = localDate();
    notify("Registro guardado no espaço cifrado.");
  }
}

function onPrivateDelete(event) {
  const button = event.target.closest("[data-private-delete]");
  if (!button) return;
  if (!window.confirm("Excluir este registro do espaço cifrado?")) return;
  savePrivateEntries(privateEntries.filter((entry) => entry.id !== button.dataset.privateDelete));
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
  const spanDays = Number($("#chart-range")?.value || 7);
  const today = new Date();
  const firstDate = new Date(today);
  firstDate.setDate(today.getDate() - spanDays + 1);
  const buckets = Array.from({ length: 7 }, (_, index) => {
    const startOffset = Math.floor(index * spanDays / 7);
    const endOffset = Math.floor((index + 1) * spanDays / 7) - 1;
    const start = new Date(firstDate);
    const end = new Date(firstDate);
    start.setDate(firstDate.getDate() + startOffset);
    end.setDate(firstDate.getDate() + endOffset);
    const startKey = localDate(start);
    const endKey = localDate(end);
    const bucketEntries = entries.filter((entry) => entry.date >= startKey && entry.date <= endKey);
    return { startKey, endKey, entries: bucketEntries };
  });
  const selectedMetrics = buckets.map((bucket) => {
    const moodValues = bucket.entries.map((entry) => Number(entry.score)).filter(Number.isFinite);
    const energyValues = bucket.entries.map((entry) => ({ "Bem baixa": 1, Baixa: 2, Habitual: 3, Alta: 4, "Muito alta": 5 }[entry.energy])).filter(Number.isFinite);
    const cognitionValues = bucket.entries.map((entry) => Number(entry.cognition)).filter((value) => Number.isFinite(value) && value >= 1 && value <= 5);
    return { ...bucket, mood: average(moodValues), energy: average(energyValues), cognition: average(cognitionValues) };
  });
  chart.innerHTML = selectedMetrics.map((bucket) => {
    const label = spanDays === 7
      ? new Intl.DateTimeFormat("pt-BR", { weekday: "short" }).format(new Date(`${bucket.endKey}T12:00:00`)).replace(".", "")
      : formatDate(bucket.endKey);
    const bars = [
      ["mood", bucket.mood, "Humor"],
      ["energy", bucket.energy, "Energia"],
      ["cognition", bucket.cognition, "Cognição"]
    ].map(([metric, value]) => value === null
      ? `<span class="chart-bar chart-${metric} chart-bar-empty" aria-hidden="true"></span>`
      : `<span class="chart-bar chart-${metric} level-${Math.min(metric === "mood" ? 7 : 5, Math.max(1, Math.round(value)))}" title="${metric}: média ${value.toFixed(1)}"></span>`
    ).join("");
    const periodLabel = spanDays === 7
      ? `${label}: ${bucket.entries.length} registro(s)`
      : `${formatDate(bucket.startKey)} a ${formatDate(bucket.endKey)}: ${bucket.entries.length} registro(s)`;
    return `<div class="chart-day" aria-label="${escapeHtml(periodLabel)}"><div class="chart-column">${bars}</div><span>${escapeHtml(label)}</span></div>`;
  }).join("");
}

function average(values) {
  return values.length ? values.reduce((sum, value) => sum + value, 0) / values.length : null;
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

function readCareData() {
  try {
    const data = JSON.parse(localStorage.getItem(CARE_KEY) || "{}");
    return {
      medications: Array.isArray(data.medications) ? data.medications : [],
      appointments: Array.isArray(data.appointments) ? data.appointments : [],
      doses: data.doses && typeof data.doses === "object" ? data.doses : {}
    };
  } catch (error) {
    console.error("Não foi possível ler seus dados de organização do cuidado.", error);
    notify("Não foi possível carregar tratamentos e consultas.");
    return { medications: [], appointments: [], doses: {} };
  }
}

function saveCareData(data) {
  try {
    localStorage.setItem(CARE_KEY, JSON.stringify(data));
    return true;
  } catch (error) {
    console.error("Não foi possível salvar tratamentos e consultas.", error);
    notify("Não foi possível salvar. O armazenamento do navegador pode estar cheio.");
    return false;
  }
}

function renderCareData() {
  const data = readCareData();
  const today = localDate();
  const medicationList = $("#medication-list");
  medicationList.innerHTML = data.medications.length ? data.medications.map((medication) => {
    const taken = Boolean(data.doses[`${medication.id}:${today}`]);
    return `<div class="managed-item">
      <div class="managed-item-copy"><strong>${escapeHtml(medication.name)}</strong><span>${escapeHtml(medication.details || "Sem orientação adicional anotada")}${medication.reminder ? ` · lembrete ${escapeHtml(medication.time || "")}` : ""}</span></div>
      <div class="managed-item-actions"><button class="entry-action" type="button" data-care-action="dose" data-care-id="${escapeHtml(medication.id)}" aria-pressed="${taken}">${taken ? "Anotado hoje" : "Marcar hoje"}</button><button class="entry-action entry-delete" type="button" data-care-action="remove-medication" data-care-id="${escapeHtml(medication.id)}" aria-label="Excluir ${escapeHtml(medication.name)}">Excluir</button></div>
    </div>`;
  }).join("") : '<p class="care-empty">Nenhum medicamento anotado. Só adicione os que sua equipe prescreveu.</p>';

  const now = new Date();
  const upcoming = data.appointments.filter((appointment) => new Date(appointment.date) >= now)
    .sort((a, b) => a.date.localeCompare(b.date)).slice(0, 5);
  $("#appointment-list").innerHTML = upcoming.length ? upcoming.map((appointment) => {
    const date = new Date(appointment.date);
    const dateLabel = new Intl.DateTimeFormat("pt-BR", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }).format(date);
    return `<div class="managed-item">
      <div class="managed-item-copy"><strong>${escapeHtml(appointment.kind || "Consulta")}</strong><span>${escapeHtml(dateLabel)}</span></div>
      <button class="entry-action entry-delete" type="button" data-care-action="remove-appointment" data-care-id="${escapeHtml(appointment.id)}" aria-label="Excluir consulta ${escapeHtml(dateLabel)}">Excluir</button>
    </div>`;
  }).join("") : '<p class="care-empty">Nenhuma consulta futura anotada.</p>';
  scheduleMedicationReminders(data.medications);
}

async function addMedication(event) {
  event.preventDefault();
  const form = event.currentTarget;
  if (!form.reportValidity()) return;
  const values = new FormData(form);
  let reminder = values.has("reminder");
  const time = String(values.get("time") || "");
  if (reminder && !time) {
    notify("Escolha um horário para o lembrete.");
    return;
  }
  if (reminder && !("Notification" in window)) {
    reminder = false;
    form.elements.reminder.checked = false;
  }
  if (reminder && Notification.permission === "default") {
    try {
      const permission = await Notification.requestPermission();
      if (permission !== "granted") {
        reminder = false;
        form.elements.reminder.checked = false;
      }
    } catch (error) {
      console.error("Não foi possível pedir permissão para lembretes.", error);
      reminder = false;
      form.elements.reminder.checked = false;
    }
  }
  if (reminder && Notification.permission !== "granted") {
    reminder = false;
    form.elements.reminder.checked = false;
  }
  const data = readCareData();
  data.medications.push({
    id: crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`,
    name: String(values.get("name") || "").trim(),
    details: String(values.get("details") || "").trim(),
    reminder,
    time
  });
  if (!saveCareData(data)) return;
  form.reset();
  form.elements.time.value = "20:00";
  renderCareData();
  notify(reminder ? "Medicamento anotado e lembrete local ativado." : "Medicamento anotado sem lembrete.");
}

function addAppointment(event) {
  event.preventDefault();
  const form = event.currentTarget;
  if (!form.reportValidity()) return;
  const values = new FormData(form);
  const date = String(values.get("date") || "");
  if (!date || new Date(date) < new Date()) {
    notify("Escolha uma data e horário futuros.");
    return;
  }
  const data = readCareData();
  data.appointments.push({
    id: crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`,
    date: new Date(date).toISOString(),
    kind: String(values.get("kind") || "").trim()
  });
  if (!saveCareData(data)) return;
  form.reset();
  renderCareData();
  notify("Consulta anotada neste dispositivo.");
}

function scheduleMedicationReminders(medications) {
  medicationReminderTimers.forEach((timer) => window.clearTimeout(timer));
  medicationReminderTimers = [];
  if (!("Notification" in window) || Notification.permission !== "granted" || document.hidden) return;
  medications.filter((medication) => medication.reminder && /^\d{2}:\d{2}$/.test(medication.time)).forEach((medication) => {
    const [hours, minutes] = medication.time.split(":").map(Number);
    const next = new Date();
    next.setHours(hours, minutes, 0, 0);
    if (next <= new Date()) next.setDate(next.getDate() + 1);
    const timer = window.setTimeout(() => {
      try {
        new Notification("Lembrete de rotina", { body: "Se fizer sentido, confira suas anotações de cuidado.", icon: "./icon.svg" });
      } catch (error) {
        console.error("Não foi possível exibir o lembrete de rotina.", error);
        notify("Não foi possível exibir um lembrete. Confira as permissões do navegador.");
      }
      scheduleMedicationReminders(readCareData().medications);
    }, next.getTime() - Date.now());
    medicationReminderTimers.push(timer);
  });
}

function onCareAction(event) {
  const button = event.target.closest("button[data-care-action]");
  if (!button) return;
  const data = readCareData();
  const id = button.dataset.careId;
  if (button.dataset.careAction === "dose") {
    const key = `${id}:${localDate()}`;
    if (data.doses[key]) delete data.doses[key];
    else data.doses[key] = true;
  } else if (button.dataset.careAction === "remove-medication") {
    if (!window.confirm("Excluir este medicamento da lista local? Isso não altera nenhuma prescrição.")) return;
    data.medications = data.medications.filter((medication) => medication.id !== id);
    Object.keys(data.doses).filter((key) => key.startsWith(`${id}:`)).forEach((key) => delete data.doses[key]);
  } else if (button.dataset.careAction === "remove-appointment") {
    data.appointments = data.appointments.filter((appointment) => appointment.id !== id);
  }
  if (saveCareData(data)) renderCareData();
}

function render() {
  const entries = readEntries();
  renderToday(entries);
  renderWeek(entries);
  renderRecent(entries);
  renderStreak(entries);
  renderCareData();
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
    cognition: String(formData.get("cognition") || ""),
    thoughtSpeed: String(formData.get("thoughtSpeed") || ""),
    thoughtContent: String(formData.get("thoughtContent") || ""),
    sleepQuality: String(formData.get("sleepQuality") || ""),
    activityMinutes: String(formData.get("activityMinutes") || ""),
    sunMinutes: String(formData.get("sunMinutes") || ""),
    sunPeriod: String(formData.get("sunPeriod") || ""),
    processedMeals: String(formData.get("processedMeals") || ""),
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

function reportText(range, recipient, startDate, endDate, includeCare) {
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
    "Nível | Dia | Período | Sono (h) | Qualidade do sono | Energia | Cognição percebida (1-5) | Velocidade dos pensamentos | Conteúdo dos pensamentos | Atividade (min) | Luz do dia (min) | Horário aproximado da luz do dia | Refeições ultraprocessadas | Nota",
    ...filtered.map((entry) => [
      moodFor(entry.score).label, formatDate(entry.date, { day: "2-digit", month: "2-digit", year: "numeric" }),
      entry.period, entry.sleep || "—", entry.sleepQuality || "—", entry.energy || "—",
      entry.cognition || "—", entry.thoughtSpeed || "—", entry.thoughtContent || "—",
      entry.activityMinutes || "—", entry.sunMinutes || "—", entry.sunPeriod || "—", entry.processedMeals || "—", entry.note || "—"
    ].join(" | ")),
    ...(includeCare ? [
      "",
      "Organização do cuidado (anotações pessoais, não prescrição)",
      ...readCareData().medications.map((medication) => `Medicamento: ${medication.name}${medication.details ? ` — ${medication.details}` : ""}`),
      ...readCareData().appointments.map((appointment) => `Consulta: ${new Intl.DateTimeFormat("pt-BR", { dateStyle: "short", timeStyle: "short" }).format(new Date(appointment.date))}${appointment.kind ? ` — ${appointment.kind}` : ""}`)
    ] : []),
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
  const includeCare = data.has("includeCare");
  const care = readCareData();
  const entries = readEntries();
  const text = reportText(range, String(data.get("recipient") || "").trim(), startDate, endDate, includeCare);
  if (!entries.length && !(includeCare && (care.medications.length || care.appointments.length))) {
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
  if (!time || !("Notification" in window) || Notification.permission !== "granted" || !readSettings().reminderEnabled) return;
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
  if (enabled && "Notification" in window && Notification.permission === "granted") scheduleReminder(settings.reminderTime || "20:00");
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
    $("#file-name").textContent = file ? `${file.name} · ${Math.ceil(file.size / 1024 / 1024)} MB (salvo localmente, sem envio)` : "Nenhum arquivo selecionado";
  });
  $("#chart-range").addEventListener("change", (event) => {
    $("#trend-title").textContent = `Últimos ${event.currentTarget.value} dias`;
    renderWeek(readEntries());
  });
  $("#medication-form").addEventListener("submit", addMedication);
  $("#appointment-form").addEventListener("submit", addAppointment);
  $("#medication-list").addEventListener("click", onCareAction);
  $("#appointment-list").addEventListener("click", onCareAction);
  $("#private-space-button").addEventListener("click", openPrivateSpace);
  $("#private-unlock-form").addEventListener("submit", unlockOrCreatePrivateSpace);
  $("#private-entry-form").addEventListener("submit", addPrivateEntry);
  $("#private-lock-button").addEventListener("click", () => lockPrivateSpace(true));
  $("#private-entries").addEventListener("click", onPrivateDelete);
  $("#private-delete-all").addEventListener("click", async () => {
    if (!window.confirm("Apagar permanentemente todos os registros deste espaço? A frase-senha continuará ativa.")) return;
    if (await savePrivateEntries([])) notify("Todos os registros do espaço privado foram apagados.");
  });
  $("#private-dialog").addEventListener("close", () => lockPrivateSpace(false));
  $("#private-dialog").addEventListener("input", touchPrivateLockTimer);
  $("#private-dialog").addEventListener("pointerdown", touchPrivateLockTimer);
  document.addEventListener("visibilitychange", () => {
    if (document.hidden) {
      if (privateEncryptionKey) lockPrivateSpace(true);
      medicationReminderTimers.forEach((timer) => window.clearTimeout(timer));
      medicationReminderTimers = [];
    } else {
      scheduleMedicationReminders(readCareData().medications);
    }
  });
  window.addEventListener("pagehide", () => lockPrivateSpace(false));
  const now = new Date();
  now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
  $("#appointment-form [name='date']").min = now.toISOString().slice(0, 16);
  if ("serviceWorker" in navigator && location.protocol.startsWith("http")) {
    navigator.serviceWorker.register("./sw.js").catch((error) => console.error("Não foi possível registrar o modo instalável.", error));
  }
}

init();
