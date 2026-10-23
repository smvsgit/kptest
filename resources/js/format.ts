/** Shared display formatting. */

const BYTE_UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'] as const;

/**
 * Human-readable byte size, scaled to the largest unit that keeps the number
 * small (20.6 MB, 929.9 GB, 1.99 TB). Raw byte counts are unreadable once they
 * run past a few digits, and a fixed unit is wrong at the other end of the
 * range - 0.00002 GB is no better than 21892043899888.
 */
export function formatBytes(bytes: number | null | undefined, precision = 1): string {
    const value = Number(bytes);
    if (!Number.isFinite(value) || value <= 0) return '0 B';

    const exponent = Math.min(
        BYTE_UNITS.length - 1,
        Math.floor(Math.log(value) / Math.log(1024)),
    );
    const scaled = value / Math.pow(1024, exponent);

    // Whole bytes never need decimals.
    return `${scaled.toFixed(exponent === 0 ? 0 : precision)} ${BYTE_UNITS[exponent]}`;
}

/** The exact figure, for a tooltip beside the rounded one. */
export function exactBytes(bytes: number | null | undefined): string {
    const value = Number(bytes);
    return Number.isFinite(value) ? `${Math.round(value).toLocaleString('en-US')} bytes` : '';
}
