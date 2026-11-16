import { act, cleanup, renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { usePlaceList } from './usePlaceList';

const cities = [{ id: 1, name: 'Manila', slug: 'manila' }];

describe('usePlaceList (slice 4.4)', () => {
    const fetchMock = vi.fn();

    beforeEach(() => {
        fetchMock.mockReset();
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('is idle, with nothing fetched, until the dropdown above has a choice', () => {
        const { result } = renderHook(() => usePlaceList(null));

        expect(result.current.status).toBe('idle');
        expect(result.current.items).toEqual([]);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('uses a list the page came with, without a request', () => {
        const { result } = renderHook(() => usePlaceList('/places/provinces/metro-manila/cities', { '/places/provinces/metro-manila/cities': cities }));

        expect(result.current).toMatchObject({ status: 'ready', items: cities });
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('loads a list, then keeps it for the next time the same choice is made', async () => {
        fetchMock.mockResolvedValue(new Response(JSON.stringify(cities), { status: 200 }));
        const { result, rerender } = renderHook(({ url }: { url: string | null }) => usePlaceList(url), {
            initialProps: { url: '/places/provinces/metro-manila/cities' as string | null },
        });

        expect(result.current.status).toBe('loading');
        await waitFor(() => expect(result.current.status).toBe('ready'));
        expect(result.current.items).toEqual(cities);

        rerender({ url: null });
        rerender({ url: '/places/provinces/metro-manila/cities' });
        expect(result.current.status).toBe('ready');
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('turns a failed request into an error with a retry, never an empty list', async () => {
        fetchMock.mockResolvedValueOnce(new Response('', { status: 500 }));
        fetchMock.mockResolvedValueOnce(new Response(JSON.stringify(cities), { status: 200 }));
        const { result } = renderHook(() => usePlaceList('/places/provinces/metro-manila/cities'));

        await waitFor(() => expect(result.current.status).toBe('error'));

        act(() => result.current.retry());
        await waitFor(() => expect(result.current.status).toBe('ready'));
        expect(result.current.items).toEqual(cities);
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });
});
