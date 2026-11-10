/**
 * A patient's age (docs/PHARMACY.md, branch 13), in whole months: typed as
 * years ("34", "34y") or months ("8m"), shown "8 m" under 2 years, else
 * "34 y". A mirror of App\Support\PatientAge (parse and format): change
 * both together.
 */

/** 150 years: anything older is a typing mistake. */
export const MAX_AGE_MONTHS = 1800;

/** Below this many months, an age is shown in months. */
const MONTHS_UNTIL = 24;

/** "34", "34y", "34 y", "8m", "8 M" → months; null when it is not an age. */
export function parseAge(typed: string | null | undefined): number | null {
    const match = /^(\d{1,3})\s*(y|m)?$/.exec(
        String(typed ?? '')
            .trim()
            .toLowerCase(),
    );

    if (match === null) {
        return null;
    }

    const months = Number(match[1]) * (match[2] === 'm' ? 1 : 12);

    return months <= MAX_AGE_MONTHS ? months : null;
}

/** 8 → "8 m", 408 → "34 y". */
export function formatAge(months: number | null | undefined): string | null {
    if (months === null || months === undefined || months < 0) {
        return null;
    }

    return months < MONTHS_UNTIL
        ? `${months} m`
        : `${Math.floor(months / 12)} y`;
}

/** Whole months from one YYYY-MM-DD day to another (PHP's diffInMonths for dates). */
function monthsBetween(from: string, to: string): number {
    const [fy, fm, fd] = from.split('-').map(Number);
    const [ty, tm, td] = to.split('-').map(Number);
    let months = (ty - fy) * 12 + (tm - fm);

    if (td < fd) {
        months -= 1;
    }

    return months;
}

/** The till's local day as YYYY-MM-DD. */
export function today(): string {
    return new Date().toLocaleDateString('sv-SE');
}

/**
 * A cached age brought up to today: the till keeps each patient's age as
 * the server worked it out on `ageOn`, and ages it by the months since.
 */
export function ageToday(
    ageMonths: number | null,
    ageOn: string,
    day: string = today(),
): number | null {
    if (ageMonths === null) {
        return null;
    }

    return Math.min(
        MAX_AGE_MONTHS,
        Math.max(0, ageMonths + Math.max(0, monthsBetween(ageOn, day))),
    );
}
