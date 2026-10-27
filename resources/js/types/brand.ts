/** The shop as every page knows it (shared `brand`, BrandingService::pageBrand). */
export type Brand = {
    name: string;
    logoUrl: string | null;
    tagline: string;
    /** A clinic (pharmacy module): no logo shows a medical cross. */
    clinic: boolean;
};
