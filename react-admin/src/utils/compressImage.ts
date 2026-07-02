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

/** Returns a human-readable file size string e.g. "1.2 MB" */
export function formatFileSize(bytes: number): string {
    if (bytes < 1024)        return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}