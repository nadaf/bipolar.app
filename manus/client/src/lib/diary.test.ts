import { describe, it, expect } from "vitest";
import {
  moods,
  localDate,
  offsetDate,
  dateObject,
  emptyEntry,
  sampleEntries,
  periods,
} from "./diary";
describe("Escala descritiva e períodos", () => {
  it("preserva sete níveis ordenados e a estabilidade central", () => {
    expect(moods.map(m => m.value)).toEqual([-3, -2, -1, 0, 1, 2, 3]);
    expect(moods[0].label).toBe("Depressão");
    expect(moods[3].label).toBe("Estável");
    expect(moods[6].label).toBe("Euforia");
  });
  it("mantém três períodos sem confundir sintomas mistos com um nível", () => {
    expect(periods.map(p => p.id)).toEqual(["morning", "afternoon", "night"]);
    expect(moods.some(m => m.label.includes("misto"))).toBe(false);
  });
  it("cria registro sem tornar texto, sono ou mídia obrigatórios", () => {
    const entry = emptyEntry("2026-10-14", "morning");
    expect(entry.date).toBe("2026-10-14");
    expect(entry.period).toBe("morning");
    expect(entry.sleep).toBeNull();
    expect(entry.note).toBe("");
    expect(entry.attachments).toEqual([]);
    expect(entry.mixed).toBe(false);
  });
});
describe("Datas sem conversão UTC involuntária", () => {
  it("preserva datas de calendário e passa de mês e ano", () => {
    expect(localDate(dateObject("2026-10-14"))).toBe("2026-10-14");
    expect(offsetDate("2026-01-01", -1)).toBe("2025-12-31");
    expect(offsetDate("2026-02-28", 1)).toBe("2026-03-01");
  });
});
describe("Exemplos explicitamente separados", () => {
  it("identifica todos os registros como exemplos sem mídia e sem persistência", () => {
    const entries = sampleEntries();
    expect(entries).toHaveLength(21);
    expect(
      entries.every(
        e => e.id.startsWith("example-") && e.attachments.length === 0
      )
    ).toBe(true);
    expect(new Set(entries.map(e => `${e.date}/${e.period}`)).size).toBe(21);
  });
});
