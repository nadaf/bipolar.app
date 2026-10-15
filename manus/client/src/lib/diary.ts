export type Period = "morning" | "afternoon" | "night";
export type Attachment = {
  id: string;
  name: string;
  type: "audio" | "video";
  url?: string;
  mime: string;
  blob?: Blob;
};
export type Entry = {
  id: string;
  date: string;
  period: Period;
  mood: number;
  note: string;
  sleep: number | null;
  medication: "taken" | "not-taken" | "not-applicable" | "";
  symptoms: string[];
  factors: string[];
  mixed: boolean;
  createdAt: string;
  attachments: Attachment[];
};
export const periods: { id: Period; label: string; hours: string }[] = [
  { id: "morning", label: "Manhã", hours: "06h – 12h" },
  { id: "afternoon", label: "Tarde", hours: "12h – 18h" },
  { id: "night", label: "Noite", hours: "18h – 06h" },
];
export const moods = [
  {
    value: -3,
    label: "Depressão",
    short: "Depressão",
    color: "#777aa5",
    bg: "#eeedf6",
    description: "Humor muito baixo, com grande dificuldade para a rotina.",
  },
  {
    value: -2,
    label: "Muito baixo",
    short: "Muito baixo",
    color: "#819eb7",
    bg: "#edf3f8",
    description: "Tristeza e pouca energia, com impacto no seu dia.",
  },
  {
    value: -1,
    label: "Baixo",
    short: "Baixo",
    color: "#8aaeb1",
    bg: "#edf5f4",
    description: "Mais desânimo ou menos disposição do que o habitual.",
  },
  {
    value: 0,
    label: "Estável",
    short: "Estável",
    color: "#6a9273",
    bg: "#edf4e9",
    description:
      "Humor e energia próximos do seu habitual. Referência: eutimia.",
  },
  {
    value: 1,
    label: "Elevado",
    short: "Elevado",
    color: "#b6a369",
    bg: "#f8f4e5",
    description: "Mais energia e entusiasmo do que o habitual.",
  },
  {
    value: 2,
    label: "Muito elevado",
    short: "Muito elevado",
    color: "#c59263",
    bg: "#fcf0e5",
    description: "Energia muito aumentada, pensamentos ou fala acelerados.",
  },
  {
    value: 3,
    label: "Euforia",
    short: "Euforia",
    color: "#bd7e79",
    bg: "#faeae7",
    description:
      "Elevação intensa do humor e energia; pode haver impulsividade.",
  },
];
export const symptoms = [
  "Ansiedade",
  "Irritabilidade",
  "Cansaço",
  "Pensamentos acelerados",
  "Dificuldade de concentração",
  "Agitação",
  "Pouca necessidade de sono",
];
export const factors = [
  "Trabalho",
  "Relacionamentos",
  "Exercício",
  "Alimentação",
  "Estresse",
  "Cafeína",
  "Álcool",
  "Tempo para mim",
];
export function localDate(date = new Date()) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}
export function dateObject(date: string) {
  return new Date(`${date}T12:00:00`);
}
export function dateLabel(
  date: string,
  options: Intl.DateTimeFormatOptions = { day: "numeric", month: "long" }
) {
  return dateObject(date).toLocaleDateString("pt-BR", options);
}
export function offsetDate(date: string, offset: number) {
  const d = dateObject(date);
  d.setDate(d.getDate() + offset);
  return localDate(d);
}
export function currentPeriod(): Period {
  const hour = new Date().getHours();
  return hour >= 6 && hour < 12
    ? "morning"
    : hour >= 12 && hour < 18
      ? "afternoon"
      : "night";
}
export function emptyEntry(
  date = localDate(),
  period: Period = currentPeriod()
): Entry {
  return {
    id: crypto.randomUUID(),
    date,
    period,
    mood: 0,
    note: "",
    sleep: null,
    medication: "",
    symptoms: [],
    factors: [],
    mixed: false,
    createdAt: new Date().toISOString(),
    attachments: [],
  };
}
let dbPromise: Promise<IDBDatabase> | undefined;
function database() {
  return (dbPromise ??= new Promise<IDBDatabase>((resolve, reject) => {
    const request = indexedDB.open("bipolar-private-diary", 1);
    request.onupgradeneeded = () =>
      request.result.createObjectStore("entries", { keyPath: "id" });
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => {
      dbPromise = undefined;
      reject(
        new Error(
          "Não foi possível abrir o armazenamento. Verifique o modo privado e as permissões do navegador."
        )
      );
    };
  }));
}
async function operation<T>(
  mode: IDBTransactionMode,
  action: (store: IDBObjectStore) => IDBRequest<T>
): Promise<T> {
  const db = await database();
  return new Promise((resolve, reject) => {
    const tx = db.transaction("entries", mode);
    const req = action(tx.objectStore("entries"));
    let result: T;
    req.onsuccess = () => {
      result = req.result;
    };
    tx.oncomplete = () => resolve(result);
    tx.onerror = () =>
      reject(
        new Error(
          "Não foi possível guardar seu registro. O armazenamento pode estar cheio."
        )
      );
    tx.onabort = () =>
      reject(new Error("A alteração não foi salva. Tente novamente."));
  });
}
export async function readLocalEntries(): Promise<Entry[]> {
  return (await operation("readonly", store => store.getAll())) as Entry[];
}
export async function saveLocalEntry(entry: Entry) {
  const existing = await readLocalEntries();
  const same = existing.find(
    e => e.date === entry.date && e.period === entry.period
  );
  const canonical = { ...entry, id: same?.id ?? entry.id };
  await operation("readwrite", store => store.put(canonical));
  return canonical;
}
export async function deleteLocalEntry(id: string) {
  await operation("readwrite", store => store.delete(id));
}
export async function clearLocalEntries() {
  await operation("readwrite", store => store.clear());
}
export function sampleEntries(): Entry[] {
  const values = [
    [-1, 0, 0],
    [0, 1, 0],
    [1, 0, -1],
    [0, -1, 0],
    [-1, 0, 1],
    [0, 1, 0],
    [0, 0, 1],
  ];
  return values.flatMap((day, i) =>
    day.map((mood, p) => ({
      ...emptyEntry(offsetDate(localDate(), i - 6), periods[p].id),
      id: `example-${i}-${p}`,
      mood,
      sleep: [7, 8, 6.5, 7.5, 8, 7, 7.5][i],
      note:
        p === 0
          ? [
              "Acordei com menos disposição. Fiz uma caminhada leve.",
              "Reservei um tempo para descansar.",
              "Percebi mais energia pela manhã.",
              "Um dia tranquilo, no meu ritmo.",
              "Conversei sobre como estava me sentindo.",
              "Consegui organizar a rotina sem pressa.",
              "Um momento para observar como me sinto.",
            ][i]
          : "",
    }))
  );
}
export function downloadFile(
  content: BlobPart,
  filename: string,
  type: string
) {
  const url = URL.createObjectURL(new Blob([content], { type }));
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  a.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
export function exportEntries(entries: Entry[], format: "json" | "csv") {
  const clean = entries.map(({ attachments, ...entry }) => ({
    ...entry,
    attachments: attachments.map(({ blob, url, ...metadata }) => metadata),
  }));
  if (format === "json")
    downloadFile(
      JSON.stringify(
        {
          app: "Bipolar",
          exportedAt: new Date().toISOString(),
          scale: "Descritiva -3 a +3, não diagnóstica",
          entries: clean,
        },
        null,
        2
      ),
      `bipolar-diario-${localDate()}.json`,
      "application/json"
    );
  else {
    const escape = (v: unknown) => {
      let text = String(v ?? "");
      if (typeof v === "string" && /^[\t\r ]*[=+\-@]/.test(text))
        text = "'" + text;
      return '"' + text.replace(/"/g, '""') + '"';
    };
    const rows = [
      [
        "Data",
        "Período",
        "Nível",
        "Humor",
        "Sono (h)",
        "Medicação",
        "Sintomas mistos",
        "Sintomas",
        "Contexto",
        "Nota",
      ],
      ...entries.map(e => [
        e.date,
        periods.find(p => p.id === e.period)?.label,
        e.mood,
        moods[e.mood + 3].label,
        e.sleep,
        e.medication === "taken"
          ? "Tomada"
          : e.medication === "not-taken"
            ? "Não tomada"
            : e.medication === "not-applicable"
              ? "Não se aplica"
              : "",
        e.mixed ? "Sim" : "Não",
        e.symptoms.join(", "),
        e.factors.join(", "),
        e.note,
      ]),
    ];
    downloadFile(
      "\uFEFF" + rows.map(row => row.map(escape).join(";")).join("\r\n"),
      `bipolar-diario-${localDate()}.csv`,
      "text/csv;charset=utf-8"
    );
  }
}
