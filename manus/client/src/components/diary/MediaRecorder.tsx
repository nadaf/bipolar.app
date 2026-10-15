import { useEffect, useRef, useState } from "react";
import {
  Mic,
  Video,
  Paperclip,
  Square,
  Trash2,
  Circle,
  AlertCircle,
} from "lucide-react";
import { toast } from "sonner";
import type { Attachment } from "@/lib/diary";
const MAX_SIZE = 25 * 1024 * 1024;
export function MediaPreview({
  attachment,
  remove,
}: {
  attachment: Attachment;
  remove?: () => void;
}) {
  const [url, setUrl] = useState(attachment.url || "");
  useEffect(() => {
    if (!attachment.blob) {
      setUrl(attachment.url || "");
      return;
    }
    const object = URL.createObjectURL(attachment.blob);
    setUrl(object);
    return () => URL.revokeObjectURL(object);
  }, [attachment]);
  return (
    <div className="media-preview">
      <div className="media-name">
        <span>
          {attachment.type === "audio" ? (
            <Mic size={16} />
          ) : (
            <Video size={16} />
          )}
          {attachment.name}
        </span>
        {remove && (
          <button
            type="button"
            className="icon-button"
            onClick={remove}
            aria-label={`Remover ${attachment.name}`}
          >
            <Trash2 size={17} />
          </button>
        )}
      </div>
      {url &&
        (attachment.type === "audio" ? (
          <audio
            controls
            preload="metadata"
            src={url}
            aria-label={attachment.name}
          />
        ) : (
          <video
            controls
            playsInline
            preload="metadata"
            src={url}
            aria-label={attachment.name}
          />
        ))}
    </div>
  );
}
export default function MediaRecorder({
  attachments,
  onChange,
  consent,
  setConsent,
  onBusyChange,
}: {
  attachments: Attachment[];
  onChange: (value: Attachment[]) => void;
  consent: boolean;
  setConsent: (v: boolean) => void;
  onBusyChange?: (busy: boolean) => void;
}) {
  const [recording, setRecording] = useState<"audio" | "video" | null>(null);
  const [seconds, setSeconds] = useState(0);
  const [error, setError] = useState("");
  const [pending, setPending] = useState(false);
  useEffect(() => {
    onBusyChange?.(pending || !!recording);
  }, [pending, recording, onBusyChange]);
  const recorder = useRef<globalThis.MediaRecorder | null>(null);
  const stream = useRef<MediaStream | null>(null);
  const camera = useRef<HTMLVideoElement>(null);
  const input = useRef<HTMLInputElement>(null);
  const timer = useRef<ReturnType<typeof setInterval> | undefined>(undefined);
  const mounted = useRef(true);
  const pendingChunks = useRef<Blob[]>([]);
  const latest = useRef({ attachments, onChange });
  latest.current = { attachments, onChange };
  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
      if (recorder.current?.state === "recording") recorder.current.stop();
      stream.current?.getTracks().forEach(t => t.stop());
      clearInterval(timer.current);
    };
  }, []);
  function stop() {
    if (recorder.current?.state === "recording") recorder.current.stop();
  }
  async function start(type: "audio" | "video") {
    setError("");
    if (!consent) {
      setError("Autorize o uso de mídia abaixo antes de gravar.");
      return;
    }
    if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
      setError(
        "A gravação não está disponível neste navegador. Você pode anexar um arquivo."
      );
      return;
    }
    setPending(true);
    try {
      const media = await navigator.mediaDevices.getUserMedia({
        audio: true,
        video:
          type === "video"
            ? {
                facingMode: "user",
                width: { ideal: 640 },
                height: { ideal: 480 },
              }
            : false,
      });
      if (!mounted.current) {
        media.getTracks().forEach(t => t.stop());
        return;
      }
      stream.current = media;
      const candidates =
        type === "audio"
          ? ["audio/webm;codecs=opus", "audio/mp4", "audio/ogg;codecs=opus"]
          : ["video/webm;codecs=vp8,opus", "video/mp4"];
      const mimeType = candidates.find(m =>
        window.MediaRecorder.isTypeSupported(m)
      );
      const rec = new window.MediaRecorder(
        media,
        mimeType ? { mimeType } : undefined
      );
      recorder.current = rec;
      pendingChunks.current = [];
      let size = 0;
      let overflow = false;
      rec.ondataavailable = e => {
        if (e.data.size) {
          pendingChunks.current.push(e.data);
          size += e.data.size;
          if (size > MAX_SIZE) {
            overflow = true;
            rec.stop();
          }
        }
      };
      rec.onstop = () => {
        clearInterval(timer.current);
        media.getTracks().forEach(t => t.stop());
        if (!mounted.current) return;
        setRecording(null);
        setSeconds(0);
        if (overflow) {
          setError("A gravação passou de 25 MB. Faça um registro mais curto.");
          return;
        }
        const blob = new Blob(pendingChunks.current, { type: rec.mimeType });
        if (!blob.size) {
          setError("A gravação ficou vazia. Tente novamente.");
          return;
        }
        const ext = rec.mimeType.includes("mp4")
          ? "mp4"
          : rec.mimeType.includes("ogg")
            ? "ogg"
            : "webm";
        latest.current.onChange([
          ...latest.current.attachments,
          {
            id: crypto.randomUUID(),
            type,
            name: `${type === "audio" ? "Áudio" : "Vídeo"}-${Date.now()}.${ext}`,
            mime: rec.mimeType,
            blob,
          },
        ]);
        toast.success("Gravação anexada. Salve o registro para guardá-la.");
      };
      rec.onerror = () => {
        setError("Houve um problema ao gravar. Tente anexar um arquivo.");
        stop();
      };
      rec.start(500);
      setSeconds(0);
      setRecording(type);
      timer.current = setInterval(
        () =>
          setSeconds(prev => {
            if (prev >= 119) stop();
            return prev + 1;
          }),
        1000
      );
      setTimeout(() => {
        if (camera.current) camera.current.srcObject = media;
      }, 0);
    } catch (err) {
      stream.current?.getTracks().forEach(t => t.stop());
      setError(
        err instanceof DOMException && err.name === "NotAllowedError"
          ? "Permissão negada. Autorize a câmera ou o microfone nas configurações do navegador, ou anexe um arquivo."
          : "Não foi possível iniciar a gravação. Verifique seu dispositivo."
      );
    } finally {
      if (mounted.current) setPending(false);
    }
  }
  function filesSelected(files: FileList | null) {
    if (!files) return;
    const added: Attachment[] = [];
    for (const file of Array.from(files)) {
      if (file.size > MAX_SIZE) {
        setError(`${file.name}: limite de 25 MB por arquivo.`);
        continue;
      }
      if (!/^(audio|video)\//.test(file.type)) {
        setError("Selecione apenas arquivos de áudio ou vídeo.");
        continue;
      }
      added.push({
        id: crypto.randomUUID(),
        name: file.name,
        type: file.type.startsWith("audio") ? "audio" : "video",
        mime: file.type,
        blob: file,
      });
    }
    onChange([...attachments, ...added]);
    if (input.current) input.current.value = "";
  }
  return (
    <div className="media-recorder">
      <div className="media-actions">
        <button
          type="button"
          className="outline-button"
          disabled={pending || !!recording || !consent}
          onClick={() => start("audio")}
        >
          <Mic size={18} />
          Gravar áudio
        </button>
        <button
          type="button"
          className="outline-button"
          disabled={pending || !!recording || !consent}
          onClick={() => start("video")}
        >
          <Video size={18} />
          Gravar vídeo
        </button>
        <button
          type="button"
          className="outline-button"
          disabled={!!recording || !consent}
          onClick={() => input.current?.click()}
        >
          <Paperclip size={18} />
          Anexar
        </button>
        <input
          ref={input}
          type="file"
          accept="audio/*,video/*"
          multiple
          hidden
          onChange={e => filesSelected(e.target.files)}
        />
      </div>
      <label className="check-label">
        <input
          type="checkbox"
          checked={consent}
          disabled={!!recording || attachments.length > 0}
          onChange={e => setConsent(e.target.checked)}
        />
        <span>
          Autorizo o uso de câmera/microfone e o armazenamento da mídia neste
          diário. Posso remover os anexos antes de salvar.
        </span>
      </label>
      <p className="field-hint">
        Opcional. Até 2 minutos por gravação e 25 MB por arquivo. Nada é enviado
        sem salvar.
      </p>
      {pending && <p role="status">Aguardando permissão do dispositivo…</p>}
      {recording && (
        <div className="recording-box">
          {recording === "video" && (
            <video
              ref={camera}
              autoPlay
              muted
              playsInline
              className="camera-preview"
            />
          )}
          <div>
            <span className="recording-time">
              <Circle size={12} fill="currentColor" />
              Gravando · {String(Math.floor(seconds / 60)).padStart(2, "0")}:
              {String(seconds % 60).padStart(2, "0")}
            </span>
            <button type="button" className="primary-button" onClick={stop}>
              <Square size={15} fill="currentColor" />
              Parar gravação
            </button>
          </div>
        </div>
      )}
      {error && (
        <p className="inline-error" role="alert">
          <AlertCircle size={16} />
          {error}
        </p>
      )}
      {attachments.map(a => (
        <MediaPreview
          key={a.id}
          attachment={a}
          remove={() => onChange(attachments.filter(item => item.id !== a.id))}
        />
      ))}
    </div>
  );
}
