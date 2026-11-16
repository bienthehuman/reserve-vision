import { Link } from '@inertiajs/react';
import { Badge } from 'react-bootstrap';
import { GeoAlt, Image } from 'react-bootstrap-icons';
import PriceTag from '@/Components/PriceTag';
import { genderLabel, kindLabel, type GenderPolicy, type PropertyKind } from '@/lib/labels';

export interface ListingCardData {
    id: number;
    url: string;
    name: string;
    kind: PropertyKind;
    gender_policy: GenderPolicy;
    barangay: string;
    city: string;
    from_rate: number | null;
    cover: { src: string; width: number; height: number; alt: string } | null;
}

/** A property in a list: cover thumbnail (4:3, lazy), name, place, from-price and the two chips. The whole card is one link. */
/** `level` is the heading the name is: 3 under a section heading (the home page), 2 directly under the page's h1 (the list). */
export default function ListingCard({ listing, level = 3 }: { listing: ListingCardData; level?: 2 | 3 }) {
    const { cover } = listing;
    const Heading = level === 2 ? 'h2' : 'h3';

    return (
        <article className="listing-card card h-100">
            {cover ? (
                <img className="listing-card__photo card-img-top" src={cover.src} width={cover.width} height={cover.height} alt={cover.alt} loading="lazy" />
            ) : (
                <div className="listing-card__photo listing-card__photo--empty card-img-top" aria-hidden="true">
                    <Image focusable="false" />
                </div>
            )}
            <div className="card-body d-flex flex-column gap-2">
                <Heading className="h5 mb-0">
                    <Link href={listing.url} className="stretched-link link-plain">
                        {listing.name}
                    </Link>
                </Heading>
                <p className="small text-body-secondary d-flex align-items-start gap-1 mb-0">
                    <GeoAlt aria-hidden="true" focusable="false" className="flex-shrink-0 mt-1" />
                    <span>
                        {listing.barangay}, {listing.city}
                    </span>
                </p>
                <p className="mb-0 mt-auto">
                    {listing.from_rate === null ? (
                        <span className="small text-body-secondary">No rooms yet</span>
                    ) : (
                        <>
                            <span className="small text-body-secondary">From </span>
                            <PriceTag centavos={listing.from_rate} per="month" />
                        </>
                    )}
                </p>
                <p className="d-flex flex-wrap gap-1 mb-0">
                    <Badge bg="light" text="dark" className="listing-chip">
                        {kindLabel[listing.kind]}
                    </Badge>
                    <Badge bg="light" text="dark" className="listing-chip">
                        {genderLabel[listing.gender_policy]}
                    </Badge>
                </p>
            </div>
        </article>
    );
}
