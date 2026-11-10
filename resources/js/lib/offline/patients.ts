import { ageToday } from '@/lib/patientAge';
import type { PosPatient } from '@/types/pos';
import { db, metaKeys } from './db';
import type { OfflinePatient } from './db';

/**
 * The till's own copy of the shop's patients (docs/PHARMACY.md, branch 13):
 * name and age only, so the cashier finds a patient while offline. The
 * catalog sync fills it (every patient, then who changed); a patient added
 * at this till goes in at once, pending until the server sends them back.
 */

const LIMIT = 10;

function toRow(patient: PosPatient, pending = false): OfflinePatient {
    return {
        uuid: patient.uuid.toLowerCase(),
        name: patient.name,
        age_months: patient.age_months,
        age_on: patient.age_on,
        search: patient.name.toLowerCase(),
        ...(pending ? { pending: true } : {}),
    };
}

/**
 * Stores what a catalog sync brought: a full sync replaces the copy (keeping
 * the patients added here that the server has not sent back yet), a delta
 * adds or updates.
 */
export async function storePatients(
    patients: PosPatient[],
    full: boolean,
): Promise<void> {
    const rows = patients.map((patient) => toRow(patient));

    await db.transaction('rw', db.patients, async () => {
        if (full) {
            const sent = new Set(rows.map((row) => row.uuid));
            const gone = (await db.patients.toArray())
                .filter((row) => !row.pending && !sent.has(row.uuid))
                .map((row) => row.uuid);
            await db.patients.bulkDelete(gone);
        }

        if (rows.length > 0) {
            await db.patients.bulkPut(rows);
        }
    });
}

/** A patient added at this till (online or offline): found at once by the next sale here. */
export async function rememberPatient(patient: PosPatient): Promise<void> {
    await db.patients.put(toRow(patient, true));
}

/** Names containing every word typed, as the server searches (PatientService::search()). */
export async function searchPatients(term: string): Promise<PosPatient[]> {
    const words = term.toLowerCase().split(/\s+/).filter(Boolean);

    if (term.trim().length < 2 || words.length === 0) {
        return [];
    }

    const found = await db.patients
        .filter((row) => words.every((word) => row.search.includes(word)))
        .toArray();

    return found
        .sort((a, b) => a.name.localeCompare(b.name))
        .slice(0, LIMIT)
        .map((row) => ({
            uuid: row.uuid,
            name: row.name,
            age_months: ageToday(row.age_months, row.age_on),
            age_on: new Date().toLocaleDateString('sv-SE'),
        }));
}

/**
 * Forgets every patient on this till: it lost its access (revoked, reset,
 * token refused), so it must not keep the clinic's patients. The catalog
 * cursor goes too, so the next sync after it is set up again is a full one
 * and brings them back.
 */
export async function wipePatients(): Promise<void> {
    await db.transaction('rw', db.patients, db.meta, async () => {
        await db.patients.clear();
        await db.meta.delete(metaKeys.cursor);
    });
}
