/**
 * compressImage.ts
 *
 * Compresses an image file in the browser using the Canvas API before upload.
 * PDFs and non-image files are returned unchanged.
 *
 * - Scales down images wider than `maxWidthPx`
 * - Re-encodes as JPEG at `quality` (0–1)
 * - If compression produces a larger file than the original, the original is returned
 */
export async function compressImage(
    file: File,
    maxWidthPx  = 1920,
    quality     = 0.82,
): Promise<File> {
    // Only compress images — pass PDFs and other files through unchanged
    if (!file.type.startsWith("image/")) return file;

    return new Promise((resolve, reject) => {
        const img = new Image();
        const objectUrl = URL.createObjectURL(file);

        img.onload = () => {
            URL.revokeObjectURL(objectUrl);

            // Scale down proportionally if wider than maxWidthPx
            let { width, height } = img;
            if (width > maxWidthPx) {
                height = Math.round((height * maxWidthPx) / width);
                width  = maxWidthPx;
            }

            const canvas = document.createElement("canvas");
            canvas.width  = width;
            canvas.height = height;

            const ctx = canvas.getContext("2d");
            if (!ctx) return reject(new Error("Canvas context unavailable"));

            // White background handles transparent PNGs gracefully
            ctx.fillStyle = "#ffffff";
            ctx.fillRect(0, 0, width, height);
            ctx.drawImage(img, 0, 0, width, height);

            canvas.toBlob(
                (blob) => {
                    if (!blob) return reject(new Error("Image compression failed"));

                    const compressed = new File([blob], file.name.replace(/\.\w+$/, ".jpg"), {
                        type:         "image/jpeg",
                        lastModified: Date.now(),
                    });

                    // Return original if compression somehow made it larger
                    resolve(compressed.size < file.size ? compressed : file);
                },
                "image/jpeg",
                quality,
            );
        };

        img.onerror = () => {
            URL.revokeObjectURL(objectUrl);
            reject(new Error("Failed to load image for compression"));
        };

        img.src = objectUrl;
    });
}

function loadImage(url: string): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve(img);
        img.onerror = () => reject(new Error("Failed to load image"));
        img.src = url;
    });
}

function canvasToBlob(canvas: HTMLCanvasElement, type: string, quality: number): Promise<Blob | null> {
    return new Promise((resolve) => canvas.toBlob(resolve, type, quality));
}

/**
 * High-fidelity pre-upload optimiser for product photos.
 *
 * Prioritises quality while keeping the payload small enough to upload fast:
 *   - Passes non-images through untouched.
 *   - Leaves already-small images ALONE (no re-encode → no double-compression),
 *     so the server's own WebP optimiser is the only quality gate.
 *   - Otherwise scales the LONGEST side to `maxDimension` and re-encodes to WebP
 *     at a near-lossless `quality` (falls back to JPEG if the browser can't encode
 *     WebP). A ~12 MP phone photo becomes a few hundred KB with no visible loss.
 *   - Never returns something larger than the original.
 *
 * The server then resizes to its display size (WebP q82 + thumbnail), so page
 * load stays fast regardless.
 */
export async function smartCompressImage(
    file: File,
    { maxDimension = 2560, quality = 0.9, skipUnderBytes = 1_500_000 } = {},
): Promise<File> {
    if (!file.type.startsWith("image/")) return file;

    const objectUrl = URL.createObjectURL(file);
    try {
        const img = await loadImage(objectUrl);
        const longest = Math.max(img.width, img.height);

        // Small + within bounds already → upload as-is (best quality, no re-encode).
        if (file.size <= skipUnderBytes && longest <= maxDimension) return file;

        const scale = longest > maxDimension ? maxDimension / longest : 1;
        const w = Math.max(1, Math.round(img.width * scale));
        const h = Math.max(1, Math.round(img.height * scale));

        const canvas = document.createElement("canvas");
        canvas.width = w;
        canvas.height = h;
        const ctx = canvas.getContext("2d");
        if (!ctx) return file;
        ctx.fillStyle = "#ffffff"; // flatten transparency
        ctx.fillRect(0, 0, w, h);
        ctx.drawImage(img, 0, 0, w, h);

        const blob =
            (await canvasToBlob(canvas, "image/webp", quality)) ??
            (await canvasToBlob(canvas, "image/jpeg", quality));
        if (!blob) return file;

        const ext = blob.type === "image/webp" ? ".webp" : ".jpg";
        const out = new File([blob], file.name.replace(/\.\w+$/, ext), {
            type: blob.type,
            lastModified: Date.now(),
        });
        return out.size < file.size ? out : file;
    } catch {
        return file; // never block an upload on optimisation failure
    } finally {
        URL.revokeObjectURL(objectUrl);
    }
}

/** Returns a human-readable file size string e.g. "1.2 MB" */
export function formatFileSize(bytes: number): string {
    if (bytes < 1024)        return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}