/**
 * Client-Side PDF Compressor using offline pdf.js and pdf-lib
 * Reads PDF, renders pages to Canvas at optimized resolution,
 * compresses via JPEG (quality 0.72), and rebuilds compact PDF.
 */
window.ClientPDFCompressor = {
    /**
     * Compress a File or Blob if larger than threshold (default 1MB)
     * @param {File} file 
     * @param {Object} options
     * @returns {Promise<{file: File, compressed: boolean, originalSize: number, newSize: number}>}
     */
    async compressIfNeeded(file, options = {}) {
        const threshold = options.threshold || (1024 * 1024); // 1 MB
        const quality = options.quality || 0.72; // JPEG compression
        const maxDpiWidth = options.maxDpiWidth || 1400; // max width px for high legibility
        const onProgress = options.onProgress || function(percent, message) {};

        const originalSize = file.size;

        if (file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) {
            return { file, compressed: false, originalSize, newSize: originalSize };
        }

        if (originalSize <= threshold) {
            onProgress(100, 'Ukuran file sudah <= 1MB, tidak perlu kompresi.');
            return { file, compressed: false, originalSize, newSize: originalSize };
        }

        onProgress(5, 'Mempersiapkan engine kompresi PDF lokal...');

        // Ensure libraries exist
        if (typeof pdfjsLib === 'undefined' || typeof PDFLib === 'undefined') {
            console.warn('pdfjsLib or PDFLib not loaded. Bypassing client compression.');
            return { file, compressed: false, originalSize, newSize: originalSize };
        }

        try {
            const arrayBuffer = await file.arrayBuffer();
            const loadingTask = pdfjsLib.getDocument({ data: arrayBuffer });
            const pdfDoc = await loadingTask.promise;
            const numPages = pdfDoc.numPages;

            onProgress(15, `Memulai kompresi (${numPages} halaman)...`);

            const newPdfDoc = await PDFLib.PDFDocument.create();

            for (let i = 1; i <= numPages; i++) {
                const currentPercent = Math.round(15 + ((i - 1) / numPages) * 75);
                onProgress(currentPercent, `Mengompres halaman ${i} dari ${numPages}...`);

                const page = await pdfDoc.getPage(i);
                const viewport = page.getViewport({ scale: 1.0 });

                // Scale down if page is excessively large, maintain min crispness
                let scale = 1.5; // standard ~108-150 DPI
                if (viewport.width * scale > maxDpiWidth) {
                    scale = maxDpiWidth / viewport.width;
                }

                const scaledViewport = page.getViewport({ scale });
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                canvas.width = Math.floor(scaledViewport.width);
                canvas.height = Math.floor(scaledViewport.height);

                await page.render({
                    canvasContext: ctx,
                    viewport: scaledViewport
                }).promise;

                // Compress rendered canvas to JPEG blob
                const jpegBlob = await new Promise(resolve => {
                    canvas.toBlob(blob => resolve(blob), 'image/jpeg', quality);
                });

                const jpegBytes = await jpegBlob.arrayBuffer();
                const embeddedImage = await newPdfDoc.embedJpg(jpegBytes);

                // Add page in original dimensions (in points)
                const newPage = newPdfDoc.addPage([viewport.width, viewport.height]);
                newPage.drawImage(embeddedImage, {
                    x: 0,
                    y: 0,
                    width: viewport.width,
                    height: viewport.height
                });

                // Cleanup canvas
                canvas.width = 0;
                canvas.height = 0;
            }

            onProgress(92, 'Menyusun dokumen PDF terkompresi...');
            const compressedPdfBytes = await newPdfDoc.save();
            const compressedBlob = new Blob([compressedPdfBytes], { type: 'application/pdf' });

            const newSize = compressedBlob.size;

            // If compressed result happens to be larger (e.g. text-only PDF), keep original
            if (newSize >= originalSize) {
                onProgress(100, 'Ukuran asli sudah optimal.');
                return { file, compressed: false, originalSize, newSize: originalSize };
            }

            const compressedFile = new File([compressedBlob], file.name, {
                type: 'application/pdf',
                lastModified: Date.now()
            });

            onProgress(100, `Kompresi berhasil! ${(originalSize / 1024 / 1024).toFixed(2)} MB ➔ ${(newSize / 1024 / 1024).toFixed(2)} MB`);
            return {
                file: compressedFile,
                compressed: true,
                originalSize,
                newSize
            };
        } catch (error) {
            console.error('Error during client-side PDF compression:', error);
            onProgress(100, 'Gagal kompresi, menggunakan file asli.');
            return { file, compressed: false, originalSize, newSize: originalSize };
        }
    },

    /**
     * Direct file compression helper returning raw File instance directly
     * @param {File} file 
     * @param {Function} onProgress (percent, text)
     * @returns {Promise<File>}
     */
    async compressPdfFile(file, onProgress) {
        const res = await this.compressIfNeeded(file, {
            onProgress: onProgress || function() {}
        });
        return res.file || file;
    }
};

// Aliases for unified cross-module compatibility
window.OqcPdfCompressor = window.ClientPDFCompressor;

