/**
 * Display only. Money arrives from the server as integers in minor units
 * (125050 = 1,250.50). Nothing here adds, subtracts or prices anything.
 */
export function formatMoney(
    minor: number | null | undefined,
    decimals: number,
    currency?: string,
): string {
    if (minor === null || minor === undefined) {
        return '-';
    }

    const text = (minor / 10 ** decimals).toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });

    return currency ? `${currency} ${text}` : text;
}

/** Minor units as a plain number string for an input box: 125050 -> "1250.50". */
export function toInputValue(minor: number, decimals: number): string {
    return (minor / 10 ** decimals).toFixed(decimals);
}

export function formatQuantity(quantity: number): string {
    return Number(quantity.toFixed(3)).toString();
}
