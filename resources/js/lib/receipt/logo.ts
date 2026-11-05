/**
 * The shop's logo in pure black and white, for the thermal receipt. A thermal
 * head has no grey: it dithers one into sparse dots, so a logo is thresholded
 * here at the head's own resolution (8 dots a mm), the same size limits as
 * EscPos::raster(). Anything darker than near-white turns black, so a pale
 * brand colour does not vanish; transparent counts as white.
 *
 * Null when the image can't be read (not loaded, or from another origin).
 */
const MAX_WIDTH_DOTS = 288;
const MAX_HEIGHT_DOTS = 160;
const DARK_BELOW = 200;

export function blackLogo(source: string): Promise<string | null> {
    return new Promise((resolve) => {
        const image = new Image();

        image.onerror = () => resolve(null);
        image.onload = () => {
            const naturalWidth = image.naturalWidth || MAX_WIDTH_DOTS;
            const naturalHeight = image.naturalHeight || MAX_HEIGHT_DOTS;
            const scale = Math.min(
                MAX_WIDTH_DOTS / naturalWidth,
                MAX_HEIGHT_DOTS / naturalHeight,
            );
            const canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.round(naturalWidth * scale));
            canvas.height = Math.max(1, Math.round(naturalHeight * scale));
            const context = canvas.getContext('2d');

            if (context === null) {
                resolve(null);

                return;
            }

            context.fillStyle = '#fff';
            context.fillRect(0, 0, canvas.width, canvas.height);
            context.drawImage(image, 0, 0, canvas.width, canvas.height);

            try {
                const pixels = context.getImageData(
                    0,
                    0,
                    canvas.width,
                    canvas.height,
                );

                for (let i = 0; i < pixels.data.length; i += 4) {
                    const luminance =
                        0.299 * pixels.data[i] +
                        0.587 * pixels.data[i + 1] +
                        0.114 * pixels.data[i + 2];
                    const value = luminance < DARK_BELOW ? 0 : 255;

                    pixels.data[i] = value;
                    pixels.data[i + 1] = value;
                    pixels.data[i + 2] = value;
                    pixels.data[i + 3] = 255;
                }

                context.putImageData(pixels, 0, 0);
                resolve(canvas.toDataURL('image/png'));
            } catch {
                resolve(null);
            }
        };

        image.src = source;
    });
}
