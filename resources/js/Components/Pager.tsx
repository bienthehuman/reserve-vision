import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'react-bootstrap-icons';

interface PagerProps {
    page: number;
    lastPage: number;
    /** Paths (not full URLs) of the neighbouring pages; null at either end. */
    prevHref: string | null;
    nextHref: string | null;
}

/**
 * "Page 3 of 22" with Previous and Next side by side, so paging never means crossing the screen.
 * At either end the button stays in place, disabled, so the other one doesn't move under the pointer.
 */
export default function Pager({ page, lastPage, prevHref, nextHref }: PagerProps) {
    if (lastPage <= 1) {
        return null;
    }

    return (
        <nav aria-label="Pages" className="pager">
            <span className="pager__status">
                Page {page} of {lastPage}
            </span>
            <span className="pager__buttons">
                {prevHref ? (
                    <Link href={prevHref} className="btn btn-outline-primary" rel="prev">
                        <ChevronLeft aria-hidden="true" focusable="false" />
                        Previous
                    </Link>
                ) : (
                    <span className="btn btn-outline-primary disabled" aria-disabled="true">
                        <ChevronLeft aria-hidden="true" focusable="false" />
                        Previous
                    </span>
                )}
                {nextHref ? (
                    <Link href={nextHref} className="btn btn-outline-primary" rel="next">
                        Next
                        <ChevronRight aria-hidden="true" focusable="false" />
                    </Link>
                ) : (
                    <span className="btn btn-outline-primary disabled" aria-disabled="true">
                        Next
                        <ChevronRight aria-hidden="true" focusable="false" />
                    </span>
                )}
            </span>
        </nav>
    );
}
