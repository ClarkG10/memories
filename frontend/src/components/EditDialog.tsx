import { useCallback, useEffect, useMemo, useState } from "react";
import { FieldCount } from "./FieldCount";
import { MediaEditor, type EditorItem } from "./MediaEditor";
import { ApiError } from "../api/client";
import {
  useAlbums,
  useArchive,
  useMemory,
  useReviseMedia,
  useUpdateMemory,
} from "../api/queries";
import { useOverlay } from "../hooks/useOverlay";
import { useUploadQueue } from "../hooks/useUploadQueue";
import { todayAsInputValue } from "../lib/dates";
import { DEFAULT_TEXT_LIMITS } from "../api/types";
/**
 * Loose on purpose: editing is reached both from a timeline card and from the
 * open viewer, and those carry different shapes of the same memory.
 */
export interface EditableMemory {
  id: string;
  title: string;
  memory_date: string;
  location: string | null;
  album: string | null;
}

interface Props {
  memory: EditableMemory;
  onClose: () => void;
  onSaved: () => void;
}

/**
 * Changing a memory: what it says, and which photographs it holds.
 *
 * Both in one place and both applied by one Save, because they are one act —
 * "this is wrong, let me fix it" — and making someone visit two screens to
 * correct one mistake is how a correction stops being worth making.
 *
 * Nothing destructive happens while the dialog is open. A removed photograph
 * is a mark on a tile until Save; closing without saving leaves the memory
 * exactly as it was found.
 */
export function EditDialog({ memory, onClose, onSaved }: Props) {
  const update = useUpdateMemory();
  const revise = useReviseMedia();
  const albums = useAlbums();
  const archive = useArchive();
  const queue = useUploadQueue(archive.data);

  // Until the archive has answered, the built-in defaults stand in. They match
  // the server's own defaults, so a field is never narrower than the truth.
  const limits = archive.data?.text ?? DEFAULT_TEXT_LIMITS;

  /*
   | The timeline card carries no description — it is deliberately left out of
   | that payload — so the full memory is fetched to edit it. Until it arrives
   | the field simply has nothing in it.
   */
  const full = useMemory(memory.id);
  const containerRef = useOverlay<HTMLFormElement>(true, () => {
    // Never close mid-upload: the files would be left half-sent.
    if (!update.isPending && !revise.isPending && !queue.isUploading) onClose();
  });

  const [title, setTitle] = useState(memory.title);
  const [date, setDate] = useState(memory.memory_date);
  const [location, setLocation] = useState(memory.location ?? "");
  const [album, setAlbum] = useState(memory.album ?? "");
  const [description, setDescription] = useState("");
  const [loadedDescription, setLoadedDescription] = useState(false);
  const [error, setError] = useState<string | null>(null);

  /*
   | The arrangement being worked on. Holds a media id for a photograph already
   | in the memory and a queue key for one just chosen; both are resolved to
   | real ids at the moment of saving.
   */
  const [order, setOrder] = useState<string[] | null>(null);
  const [removing, setRemoving] = useState<ReadonlySet<string>>(new Set());

  // Adopt the memory's own order once, when it arrives.
  useEffect(() => {
    if (full.data && order === null) {
      setOrder(full.data.media.map((item) => item.id));
    }
  }, [full.data, order]);

  /*
   | New files are appended as they are chosen, so the strip always shows every
   | place in the memory — existing and pending — in one sequence that can be
   | rearranged as a whole.
   */
  useEffect(() => {
    setOrder((current) => {
      if (current === null) return current;

      const missing = queue.files
        .map((file) => file.id)
        .filter((id) => !current.includes(id));

      return missing.length > 0 ? [...current, ...missing] : current;
    });
  }, [queue.files]);

  const items = useMemo<EditorItem[]>(() => {
    if (order === null) return [];

    const byId = new Map((full.data?.media ?? []).map((m) => [m.id, m]));
    const byKey = new Map(queue.files.map((f) => [f.id, f]));

    return order
      .map((key): EditorItem | null => {
        const media = byId.get(key);
        if (media) return { kind: "existing", key, media };

        const file = byKey.get(key);
        if (file) return { kind: "new", key, file };

        return null;
      })
      .filter((item): item is EditorItem => item !== null);
  }, [order, full.data, queue.files]);

  const kept = items.filter((item) => !removing.has(item.key));

  const toggleRemove = useCallback((key: string) => {
    setRemoving((current) => {
      const next = new Set(current);

      if (next.has(key)) next.delete(key);
      else next.add(key);

      return next;
    });
  }, []);

  const moveTo = useCallback((key: string, to: number) => {
    setOrder((current) => {
      if (current === null) return current;

      const from = current.indexOf(key);
      if (from < 0) return current;

      const next = [...current];
      next.splice(from, 1);
      next.splice(to, 0, key);

      return next;
    });
  }, []);

  // Filled in once, when the memory arrives; never again, or it would wipe
  // out whatever has been typed since.
  useEffect(() => {
    if (full.data && !loadedDescription) {
      setDescription(full.data.description ?? "");
      setLoadedDescription(true);
    }
  }, [full.data, loadedDescription]);

  const originalOrder = (full.data?.media ?? []).map((item) => item.id);
  const orderChanged =
    order !== null &&
    JSON.stringify(order.filter((key) => !removing.has(key))) !==
      JSON.stringify(originalOrder);

  const mediaChanged =
    removing.size > 0 || queue.files.length > 0 || orderChanged;

  const busy = update.isPending || revise.isPending || queue.isUploading;

  const save = async (event?: React.FormEvent) => {
    event?.preventDefault();
    setError(null);

    if (kept.length === 0) {
      setError("A memory has to keep at least one photo or video.");

      return;
    }

    try {
      /*
       | The photographs first. If this fails there is something to say about
       | it, and the words are still on screen to try again with — whereas
       | saving the words first and then failing here would leave the memory
       | half-changed with no sign of which half.
       */
      if (mediaChanged) {
        // Only now do the files actually go up; choosing them cost nothing.
        const sessions = queue.files.length > 0 ? await queue.uploadAll() : [];

        const sessionFor = new Map(
          queue.files.map((file, index) => [file.id, sessions[index]]),
        );

        await revise.mutateAsync({
          id: memory.id,
          add: queue.files
            .filter((file) => !removing.has(file.id))
            .map((file) => sessionFor.get(file.id))
            .filter((id): id is string => typeof id === "string"),
          remove: [...removing].filter((key) => originalOrder.includes(key)),
          order: (order ?? [])
            .filter((key) => !removing.has(key))
            .map((key) => sessionFor.get(key) ?? key),
        });
      }

      await update.mutateAsync({
        id: memory.id,
        title: title.trim(),
        memory_date: date,
        location: location.trim() || null,
        album: album.trim() || null,
        description: description.trim() || null,
      });

      queue.reset();
      onSaved();
    } catch (caught) {
      setError(
        caught instanceof ApiError
          ? caught.message
          : "We couldn't save those changes just now.",
      );
    }
  };

  return (
    <div className="scrim" role="presentation">
      {/* A form, so Enter saves from any field rather than doing nothing. */}
      <form
        className="dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="edit-title"
        ref={containerRef}
        tabIndex={-1}
        onSubmit={(event) => void save(event)}
      >
        <h2 className="dialog__title" id="edit-title">
          Edit details
        </h2>

        <div className="dialog__scroll">
          {order !== null && (
            <MediaEditor
              items={items}
              removing={removing}
              onToggleRemove={toggleRemove}
              onMove={moveTo}
              onAdd={queue.add}
              disabled={busy}
            />
          )}

          {queue.rejections.length > 0 && (
            <div className="compose__rejections" role="alert">
              {queue.rejections.map((message) => (
                <span key={message}>{message}</span>
              ))}
            </div>
          )}

          <div className="dialog__fields">
            <label className="field">
              <span className="field__label">Title</span>
              <input
                className="field__input"
                value={title}
                onChange={(event) => setTitle(event.target.value)}
                maxLength={limits.title}
                disabled={busy}
                data-autofocus
              />
              <FieldCount value={title} limit={limits.title} />
            </label>

            <label className="field">
              <span className="field__label">When</span>
              <input
                className="field__input"
                type="date"
                value={date}
                max={todayAsInputValue()}
                onChange={(event) => setDate(event.target.value)}
                disabled={busy}
              />
            </label>

            <label className="field">
              <span className="field__label">Where (optional)</span>
              <input
                className="field__input"
                value={location}
                onChange={(event) => setLocation(event.target.value)}
                maxLength={limits.location}
                disabled={busy}
              />
              <FieldCount value={location} limit={limits.location} />
            </label>

            <div className="field">
              <label className="field__label" htmlFor="edit-album">
                Album (optional)
              </label>
              <input
                id="edit-album"
                className="field__input"
                value={album}
                onChange={(event) => setAlbum(event.target.value)}
                maxLength={limits.album}
                list="edit-album-names"
                aria-describedby="edit-album-hint"
                disabled={busy}
              />
              <datalist id="edit-album-names">
                {(albums.data ?? []).map((name) => (
                  <option key={name} value={name} />
                ))}
              </datalist>
              <span className="field__hint" id="edit-album-hint">
                Changing this moves the files in Google Drive too.
              </span>
            </div>

            <label className="field">
              <span className="field__label">A few words (optional)</span>
              <textarea
                className="field__textarea"
                value={description}
                onChange={(event) => setDescription(event.target.value)}
                maxLength={limits.description}
                disabled={busy || !loadedDescription}
              />
              <FieldCount value={description} limit={limits.description} />
            </label>
          </div>

          {error && (
            <div className="dialog__error" role="alert">
              {error}
            </div>
          )}
        </div>

        <div className="dialog__actions">
          <button
            type="button"
            className="button button--quiet"
            onClick={onClose}
            disabled={busy}
          >
            Cancel
          </button>

          <button
            type="submit"
            className="button button--primary"
            disabled={busy || title.trim() === "" || kept.length === 0}
          >
            {saveLabel(update.isPending, revise.isPending, queue.isUploading)}
          </button>
        </div>
      </form>
    </div>
  );
}

/** What the button says, so a long upload does not just read "Saving…". */
function saveLabel(saving: boolean, revising: boolean, uploading: boolean): string {
  if (uploading) return "Uploading…";
  if (revising) return "Arranging…";
  if (saving) return "Saving…";

  return "Save";
}
