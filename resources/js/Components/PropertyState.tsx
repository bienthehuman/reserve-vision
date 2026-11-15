import { Badge } from 'react-bootstrap';

/** Archived, published or not: the listing state of a property, in words (not by color alone). */
export default function PropertyState({ published, archived }: { published: boolean; archived: boolean }) {
    if (archived) {
        return (
            <Badge pill bg="" className="status-badge status-badge--secondary">
                Archived
            </Badge>
        );
    }

    return published ? (
        <Badge pill bg="" className="status-badge status-badge--success">
            Published
        </Badge>
    ) : (
        <Badge pill bg="" className="status-badge status-badge--secondary">
            Not published
        </Badge>
    );
}
