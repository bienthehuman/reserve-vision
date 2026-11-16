import PriceTag from '@/Components/PriceTag';

/** On a listing page on phones: the from-price and a button that scrolls to the rooms. A plain anchor, so it works without JavaScript. */
export default function StickyReserveBar({ fromRate }: { fromRate: number | null }) {
    return (
        <>
            <div className="sticky-reserve-bar d-md-none">
                <p className="mb-0">
                    {fromRate !== null && (
                        <>
                            <span className="small text-body-secondary d-block">From</span>
                            <PriceTag centavos={fromRate} per="month" />
                        </>
                    )}
                </p>
                <a href="#rooms" className="btn btn-primary">
                    Choose a room
                </a>
            </div>
            {/* Room for the bar, so it never covers the footer. */}
            <div className="sticky-reserve-spacer d-md-none" aria-hidden="true" />
        </>
    );
}
