import { BoxArrowUpRight, FileEarmarkPdf } from 'react-bootstrap-icons';

export interface ViewableProof {
    id: number;
    /** `/proofs/{id}`: checks who is asking, then redirects to a signed URL for them (valid 5 minutes). */
    url: string;
    is_image: boolean;
}

interface ProofViewerProps {
    proof: ViewableProof;
    /** What the proof is, for the image's alt text and the link: "Proof of payment uploaded Nov 16, 2026, 10:00 AM". */
    label: string;
}

/**
 * A payment proof (DESIGN_SYSTEM.md "ProofViewer"). An image is shown inline, and tapping it opens
 * it full size in a new tab, where it can be zoomed; a PDF is a link that opens it. Both go through
 * `/proofs/{id}`, so every view is checked and gets its own short-lived signed URL.
 */
export default function ProofViewer({ proof, label }: ProofViewerProps) {
    if (!proof.is_image) {
        return (
            <a href={proof.url} target="_blank" rel="noopener" className="btn btn-outline-primary" aria-label={`Open the PDF: ${label} (opens in a new tab)`}>
                <FileEarmarkPdf aria-hidden="true" focusable="false" />
                Open the PDF
            </a>
        );
    }

    return (
        <a href={proof.url} target="_blank" rel="noopener" className="proof-viewer">
            <img src={proof.url} alt={label} className="proof-viewer__image" loading="lazy" decoding="async" />
            <span className="proof-viewer__hint">
                <BoxArrowUpRight aria-hidden="true" focusable="false" />
                Open full size<span className="visually-hidden"> (opens in a new tab)</span>
            </span>
        </a>
    );
}
