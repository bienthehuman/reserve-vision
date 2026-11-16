import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import PropertyPhotos, { type Photo } from './PropertyPhotos';

const poll = { start: vi.fn(), stop: vi.fn(), polling: false };

vi.mock('@inertiajs/react', () => ({
    router: { post: vi.fn(), delete: vi.fn() },
    useForm: () => ({ data: { alt: '' }, errors: {}, processing: false, setData: vi.fn(), patch: vi.fn() }),
    usePoll: () => poll,
}));

const photo = (overrides: Partial<Photo>): Photo => ({
    id: 1,
    status: 'ready',
    position: 1,
    alt: null,
    alt_text: 'Photo 1 of Alpha Home',
    thumb_url: '/media/photos/properties/a-thumb.webp',
    full_url: '/media/photos/properties/a-full.webp',
    width: 1600,
    height: 1200,
    ...overrides,
});

const show = (photos: Photo[], canManage = true) =>
    render(<PropertyPhotos propertyId={7} propertyName="Alpha Home" photos={photos} limit={12} canManage={canManage} />);

afterEach(cleanup);

describe('PropertyPhotos', () => {
    beforeEach(() => {
        poll.start.mockClear();
        poll.stop.mockClear();
    });

    it('says so when there are no photos, and offers the upload to an owner', () => {
        show([]);

        expect(screen.getByText(/Alpha Home has no photos yet\. A listing needs at least one to be published\./)).not.toBeNull();
        expect(screen.getByText('0 of 12')).not.toBeNull();
        expect(screen.getByLabelText('Add photos').getAttribute('accept')).toBe('image/jpeg,image/png,image/webp');
        expect(screen.getByLabelText('Add photos').hasAttribute('multiple')).toBe(true);
    });

    it('shows a ready photo with its alt text and size, the first one as the cover', () => {
        show([photo({}), photo({ id: 2, position: 2, alt: 'Bunk beds', alt_text: 'Bunk beds' })]);

        const first = screen.getByRole('img', { name: 'Photo 1 of Alpha Home' });
        expect(first.getAttribute('src')).toBe('/media/photos/properties/a-thumb.webp');
        expect(first.getAttribute('width')).toBe('1600');
        expect(screen.getByRole('img', { name: 'Bunk beds' })).not.toBeNull();
        expect(screen.getAllByText('Cover')).toHaveLength(1);
    });

    it('names every button after its photo, and switches off the moves that go nowhere', () => {
        show([photo({}), photo({ id: 2, position: 2 })]);

        expect(screen.getByRole('button', { name: 'Move up photo 1' }).hasAttribute('disabled')).toBe(true);
        expect(screen.getByRole('button', { name: 'Move down photo 1' }).hasAttribute('disabled')).toBe(false);
        expect(screen.getByRole('button', { name: 'Move up photo 2' }).hasAttribute('disabled')).toBe(false);
        expect(screen.getByRole('button', { name: 'Move down photo 2' }).hasAttribute('disabled')).toBe(true);
        expect(screen.getByRole('button', { name: 'Describe photo 2' })).not.toBeNull();
        expect(screen.getByRole('button', { name: 'Delete photo 2' })).not.toBeNull();
    });

    it('shows a processing photo as a status and polls until none is left', () => {
        const { rerender } = show([photo({ status: 'processing', thumb_url: null, full_url: null })]);

        expect(screen.getByText('Resizing photo 1…').closest('[role="status"]')).not.toBeNull();
        expect(screen.queryByRole('img')).toBeNull();
        expect(poll.start).toHaveBeenCalledTimes(1);

        rerender(<PropertyPhotos propertyId={7} propertyName="Alpha Home" photos={[photo({})]} limit={12} canManage />);
        expect(screen.getByRole('img', { name: 'Photo 1 of Alpha Home' })).not.toBeNull();
        expect(poll.stop).toHaveBeenCalled();
    });

    it('does not poll when every photo has settled', () => {
        show([photo({}), photo({ id: 2, position: 2, status: 'failed', thumb_url: null, full_url: null })]);

        expect(poll.start).not.toHaveBeenCalled();
    });

    it('lets the owner upload a failed photo again or delete it', () => {
        show([photo({ status: 'failed', thumb_url: null, full_url: null })]);

        expect(screen.getByText('We couldn’t read photo 1.')).not.toBeNull();
        expect(screen.getByLabelText('Upload photo 1 again').getAttribute('type')).toBe('file');
        expect(screen.getByRole('button', { name: 'Delete photo 1' })).not.toBeNull();
        expect(screen.queryByRole('button', { name: 'Describe photo 1' })).toBeNull();
    });

    it('shows a caretaker the photos and nothing to press', () => {
        show([photo({}), photo({ id: 2, position: 2, status: 'failed', thumb_url: null, full_url: null })], false);

        expect(screen.getByRole('img', { name: 'Photo 1 of Alpha Home' })).not.toBeNull();
        expect(screen.getByText('The owner can upload it again or delete it.')).not.toBeNull();
        expect(screen.queryByRole('button')).toBeNull();
        expect(screen.queryByLabelText('Add photos')).toBeNull();
    });

    it('takes the upload away at the limit', () => {
        show(Array.from({ length: 12 }, (_, index) => photo({ id: index + 1, position: index + 1 })));

        expect(screen.queryByLabelText('Add photos')).toBeNull();
        expect(screen.getByText(/has all 12 photos it can have/)).not.toBeNull();
        expect(within(screen.getByRole('list')).getAllByRole('listitem')).toHaveLength(12);
    });
});
