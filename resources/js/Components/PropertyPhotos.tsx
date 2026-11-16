import { router, useForm, usePoll } from '@inertiajs/react';
import { useEffect, useId, useRef, useState, type ChangeEvent, type FormEvent } from 'react';
import { Alert, Badge, Button, Card, Modal, Spinner } from 'react-bootstrap';
import { ExclamationTriangle } from 'react-bootstrap-icons';
import ActionButton from '@/Components/ActionButton';
import SubmitButton from '@/Components/Form/SubmitButton';
import TextField from '@/Components/Form/TextField';

export interface Photo {
    id: number;
    status: 'processing' | 'ready' | 'failed';
    /** 1 is the cover. */
    position: number;
    /** What the owner wrote; null means the default in `alt_text`. */
    alt: string | null;
    alt_text: string;
    thumb_url: string | null;
    full_url: string | null;
    width: number | null;
    height: number | null;
}

interface PropertyPhotosProps {
    propertyId: number;
    propertyName: string;
    photos: Photo[];
    limit: number;
    /** Owners upload, describe, reorder and delete; a caretaker only looks. */
    canManage: boolean;
}

/** The server's rules (App\Rules\PropertyPhotoFile), checked here first so a 10 MB mistake isn't uploaded to be refused. */
const MAX_BYTES = 10 * 1024 * 1024;
const ACCEPT = 'image/jpeg,image/png,image/webp';
const POLL_MS = 2000;

/** Sends one file as one request; resolves with the server's message for it, or null when it was accepted. */
function sendPhoto(url: string, file: File): Promise<string | null> {
    return new Promise((resolve) => {
        let problem: string | null = null;

        router.post(
            url,
            { photo: file },
            {
                forceFormData: true,
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => {
                    problem = errors.photo ?? 'The photo could not be uploaded.';
                },
                onFinish: () => resolve(problem),
            },
        );
    });
}

function DescriptionForm({ photo, onDone }: { photo: Photo; onDone: () => void }) {
    const form = useForm({ alt: photo.alt ?? '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.patch(`/landlord/photos/${photo.id}`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form noValidate onSubmit={submit} aria-label={`Describe photo ${photo.position}`}>
            <TextField
                name={`alt-${photo.id}`}
                label="Description"
                value={form.data.alt}
                onChange={(value) => form.setData('alt', value)}
                error={form.errors.alt}
                help="What the photo shows, for people who can't see it: “Bunk beds by the window”. Leave it empty for the default."
                className="mb-2"
                autoFocus
            />
            <div className="d-flex flex-wrap gap-2">
                <SubmitButton processing={form.processing} pendingLabel="Saving…" className="btn-sm">
                    Save description
                </SubmitButton>
                <Button type="button" size="sm" variant="outline-primary" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

function PhotoCard({ photo, count, canManage }: { photo: Photo; count: number; canManage: boolean }) {
    const [describing, setDescribing] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [retrying, setRetrying] = useState(false);
    const [retryError, setRetryError] = useState<string | null>(null);
    const retryId = useId();
    const titleId = useId();
    const name = `photo ${photo.position}`;

    const retry = async (event: ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0];
        event.target.value = '';

        if (!file) {
            return;
        }
        if (file.size > MAX_BYTES) {
            setRetryError('This photo is larger than 10 MB.');
            return;
        }

        setRetrying(true);
        setRetryError(await sendPhoto(`/landlord/photos/${photo.id}/retry`, file));
        setRetrying(false);
    };

    const destroy = () => {
        router.delete(`/landlord/photos/${photo.id}`, {
            preserveScroll: true,
            onStart: () => setDeleting(true),
            onFinish: () => {
                setDeleting(false);
                setConfirming(false);
            },
        });
    };

    return (
        <li className="photo-card">
            <div className="photo-card__frame">
                {photo.status === 'ready' && photo.thumb_url && (
                    <img
                        src={photo.thumb_url}
                        alt={photo.alt_text}
                        width={photo.width ?? undefined}
                        height={photo.height ?? undefined}
                        loading="lazy"
                        decoding="async"
                    />
                )}
                {photo.status === 'processing' && (
                    <div className="photo-card__state" role="status">
                        <Spinner animation="border" size="sm" aria-hidden="true" />
                        <span>Resizing photo {photo.position}…</span>
                    </div>
                )}
                {photo.status === 'failed' && (
                    <div className="photo-card__state photo-card__state--failed">
                        <ExclamationTriangle aria-hidden="true" />
                        <span>We couldn’t read photo {photo.position}.</span>
                    </div>
                )}
                {photo.position === 1 && (
                    <Badge pill bg="" className="status-badge status-badge--primary photo-card__cover">
                        Cover
                    </Badge>
                )}
            </div>

            <div className={canManage || photo.status === 'failed' ? 'photo-card__body' : undefined}>
                {photo.status === 'failed' ? (
                    <p className="small mb-2">
                        {canManage ? 'Upload it again, or delete it. The file may be damaged.' : 'The owner can upload it again or delete it.'}
                    </p>
                ) : (
                    // The description, for whoever can change it. A caretaker gets the picture alone: text that only
                    // repeats the alt text right under the image says everything twice to a screen reader (axe: image-redundant-alt).
                    canManage && <p className="small text-body-secondary mb-2">{photo.alt_text}</p>
                )}

                {canManage && describing && <DescriptionForm photo={photo} onDone={() => setDescribing(false)} />}

                {canManage && !describing && (
                    <div className="d-flex flex-wrap gap-2">
                        <ActionButton
                            href={`/landlord/photos/${photo.id}/move`}
                            method="post"
                            data={{ direction: 'up' }}
                            disabled={photo.position === 1}
                            accessibleName={`Move up ${name}`}
                        >
                            Move up
                        </ActionButton>
                        <ActionButton
                            href={`/landlord/photos/${photo.id}/move`}
                            method="post"
                            data={{ direction: 'down' }}
                            disabled={photo.position === count}
                            accessibleName={`Move down ${name}`}
                        >
                            Move down
                        </ActionButton>
                        {photo.status === 'failed' ? (
                            <>
                                <input
                                    id={retryId}
                                    type="file"
                                    accept={ACCEPT}
                                    className="visually-hidden photo-card__retry"
                                    disabled={retrying}
                                    onChange={retry}
                                />
                                <label htmlFor={retryId} className={`btn btn-primary btn-sm mb-0${retrying ? ' disabled' : ''}`}>
                                    {retrying ? 'Uploading…' : `Upload ${name} again`}
                                </label>
                            </>
                        ) : (
                            <Button type="button" size="sm" variant="outline-primary" aria-label={`Describe ${name}`} onClick={() => setDescribing(true)}>
                                Describe
                            </Button>
                        )}
                        <Button type="button" size="sm" variant="danger" aria-label={`Delete ${name}`} onClick={() => setConfirming(true)}>
                            Delete
                        </Button>
                    </div>
                )}
                {retryError && (
                    <p className="invalid-feedback d-flex mt-2 mb-0" role="alert">
                        {retryError}
                    </p>
                )}
            </div>

            {/* No fade: the dialog is there at once (and axe, which reads colours mid-fade as low contrast, sees the real ones). */}
            <Modal show={confirming} onHide={() => setConfirming(false)} centered animation={false} aria-labelledby={titleId}>
                <Modal.Header closeButton>
                    <Modal.Title as="h2" className="h3" id={titleId}>
                        Delete {name}?
                    </Modal.Title>
                </Modal.Header>
                <Modal.Body>
                    <p className="mb-0">
                        The photo is removed from this property for good.{photo.position === 1 && count > 1 ? ' The next photo becomes the cover.' : ''}
                    </p>
                </Modal.Body>
                <Modal.Footer>
                    <Button type="button" variant="outline-primary" onClick={() => setConfirming(false)}>
                        Keep it
                    </Button>
                    <Button type="button" variant="danger" disabled={deleting} onClick={destroy}>
                        {deleting ? 'Deleting…' : 'Delete photo'}
                    </Button>
                </Modal.Footer>
            </Modal>
        </li>
    );
}

/**
 * A property's photos on the landlord page (slice 4.2). The first is the cover. Owners add several files at once
 * (sent one request each, so one bad file doesn't stop the rest), describe them, move them up and down with
 * buttons, and delete them. While any photo is being resized the page asks the server for the photos every
 * two seconds, and stops when they have all settled.
 */
export default function PropertyPhotos({ propertyId, propertyName, photos, limit, canManage }: PropertyPhotosProps) {
    const inputRef = useRef<HTMLInputElement>(null);
    const inputId = useId();
    const helpId = useId();
    const [progress, setProgress] = useState<string | null>(null);
    const [problems, setProblems] = useState<string[]>([]);
    const room = limit - photos.length;
    const processing = photos.some((photo) => photo.status === 'processing');

    const { start, stop } = usePoll(POLL_MS, { only: ['photos'] }, { autoStart: false });
    useEffect(() => {
        if (processing) {
            start();
        } else {
            stop();
        }

        return stop;
        // Only `processing` matters: start and stop are new functions on every render.
    }, [processing]);

    const upload = async (event: FormEvent) => {
        event.preventDefault();
        const chosen = Array.from(inputRef.current?.files ?? []);
        const found: string[] = [];

        if (chosen.length === 0) {
            setProblems(['Choose one or more photos first.']);
            return;
        }

        const files = chosen.slice(0, room);
        if (chosen.length > files.length) {
            found.push(`Only ${room} more ${room === 1 ? 'photo fits' : 'photos fit'} (${limit} at most), so ${chosen.length - files.length} of the files you chose ${chosen.length - files.length === 1 ? 'was' : 'were'} left out.`);
        }

        for (const [index, file] of files.entries()) {
            setProgress(`Uploading ${index + 1} of ${files.length}: ${file.name}`);

            const problem = file.size > MAX_BYTES ? 'This photo is larger than 10 MB.' : await sendPhoto(`/landlord/properties/${propertyId}/photos`, file);
            if (problem) {
                found.push(`${file.name}: ${problem}`);
            }
        }

        setProgress(null);
        setProblems(found);
        if (inputRef.current) {
            inputRef.current.value = '';
        }
    };

    return (
        <Card className="auth-card mb-4">
            <Card.Body className="p-3 p-sm-4">
                <div className="d-flex flex-wrap align-items-baseline justify-content-between gap-2 mb-2">
                    <h2 className="h3 mb-0">Photos</h2>
                    <span className="small text-body-secondary">
                        {photos.length} of {limit}
                    </span>
                </div>

                {photos.length === 0 ? (
                    <p className="text-body-secondary">
                        {propertyName} has no photos yet.{canManage ? ' A listing needs at least one to be published.' : ''}
                    </p>
                ) : (
                    <>
                        <p className="small text-body-secondary">The first photo is the cover: the one renters see in search results.</p>
                        <ul className="photo-grid list-unstyled">
                            {photos.map((photo) => (
                                <PhotoCard key={photo.id} photo={photo} count={photos.length} canManage={canManage} />
                            ))}
                        </ul>
                    </>
                )}

                {canManage && room > 0 && (
                    <form noValidate onSubmit={upload} aria-label="Photo upload">
                        <label htmlFor={inputId} className="form-label">
                            Add photos
                        </label>
                        <input
                            ref={inputRef}
                            id={inputId}
                            type="file"
                            name="photos"
                            multiple
                            accept={ACCEPT}
                            className="form-control mb-1"
                            aria-describedby={helpId}
                            disabled={progress !== null}
                        />
                        <p id={helpId} className="form-text mb-3">
                            JPEG, PNG or WebP, up to 10 MB each and at least 800 pixels on the longer side. You can choose several at once.
                        </p>
                        <SubmitButton processing={progress !== null} pendingLabel="Uploading…" className="">
                            Upload photos
                        </SubmitButton>
                    </form>
                )}
                {canManage && room <= 0 && (
                    <p className="small text-body-secondary mb-0">This property has all {limit} photos it can have. Delete one to add another.</p>
                )}

                <p className="visually-hidden" role="status" aria-live="polite">
                    {progress}
                </p>
                {problems.length > 0 && (
                    <Alert variant="danger" className="mt-3 mb-0" role="alert">
                        <p className="fw-semibold mb-1">{problems.length === 1 ? 'One thing went wrong:' : 'Some things went wrong:'}</p>
                        <ul className="mb-0 ps-3">
                            {problems.map((problem) => (
                                <li key={problem}>{problem}</li>
                            ))}
                        </ul>
                    </Alert>
                )}
            </Card.Body>
        </Card>
    );
}
