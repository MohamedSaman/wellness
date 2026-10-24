/**
 * Minimal JSON client for the POS endpoints. Laravel puts the CSRF token in
 * the XSRF-TOKEN cookie; it is echoed back in a header.
 */
export class ApiError extends Error {
    constructor(
        message: string,
        public status: number,
        public errors: Record<string, string[]> = {},
    ) {
        super(message);
    }
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function request<T>(
    method: 'GET' | 'POST',
    url: string,
    body?: unknown,
    signal?: AbortSignal,
): Promise<T> {
    let response: Response;

    try {
        response = await fetch(url, {
            method,
            credentials: 'same-origin',
            signal,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
                ...(body === undefined
                    ? {}
                    : { 'Content-Type': 'application/json' }),
            },
            ...(body === undefined ? {} : { body: JSON.stringify(body) }),
        });
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') {
            throw error;
        }

        throw new ApiError(
            'Cannot reach the server. Check the connection and try again.',
            0,
        );
    }

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        const errors = (data.errors ?? {}) as Record<string, string[]>;
        const first = Object.values(errors)[0]?.[0];

        throw new ApiError(
            data.message ?? first ?? 'Something went wrong.',
            response.status,
            errors,
        );
    }

    return data as T;
}

export const getJson = <T>(url: string, signal?: AbortSignal) =>
    request<T>('GET', url, undefined, signal);

export const postJson = <T>(url: string, body: unknown) =>
    request<T>('POST', url, body);

export function queryString(
    params: Record<string, string | number | null | undefined>,
): string {
    const query = new URLSearchParams();

    for (const [key, value] of Object.entries(params)) {
        if (value !== null && value !== undefined && value !== '') {
            query.set(key, String(value));
        }
    }

    const text = query.toString();

    return text === '' ? '' : `?${text}`;
}
