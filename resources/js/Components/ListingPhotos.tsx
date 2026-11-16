export interface ListingPhoto {
    id: number;
    alt: string;
    thumb: string | null;
    full: string | null;
    width: number | null;
    height: number | null;
    thumb_width: number;
}

/**
 * The gallery: the cover large, the rest in a strip you swipe or scroll (CSS only, no script).
 * The cover loads at once and with high priority, since it's the largest thing on the page; the others wait until they're near.
 * Every image has its width and height, so the page doesn't jump as they arrive.
 */
export default function ListingPhotos({ photos }: { photos: ListingPhoto[] }) {
    if (photos.length === 0) {
        return null;
    }

    const image = (photo: ListingPhoto, index: number) => {
        const full = photo.full ?? photo.thumb ?? '';
        const hasBoth = photo.thumb !== null && photo.full !== null && photo.width !== null;

        return (
            <img
                key={photo.id}
                src={full}
                srcSet={hasBoth ? `${photo.thumb} ${photo.thumb_width}w, ${photo.full} ${photo.width}w` : undefined}
                sizes={index === 0 ? '(min-width: 992px) 960px, 100vw' : '(min-width: 992px) 320px, 60vw'}
                width={photo.width ?? undefined}
                height={photo.height ?? undefined}
                alt={photo.alt}
                loading={index === 0 ? 'eager' : 'lazy'}
                fetchPriority={index === 0 ? 'high' : undefined}
                decoding={index === 0 ? undefined : 'async'}
            />
        );
    };

    const [cover, ...rest] = photos;

    return (
        <section className="gallery" aria-label="Photos">
            <div className="gallery__cover">{cover && image(cover, 0)}</div>
            {rest.length > 0 && <div className="gallery__strip" role="group" aria-label="More photos" tabIndex={0}>{rest.map((photo, index) => image(photo, index + 1))}</div>}
        </section>
    );
}
