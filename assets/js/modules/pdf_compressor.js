/**
 * Client-Side PDF Compressor Utility
 * Uses local Mozilla pdf.js (renderer) + PDFLib (builder)
 * 100% Offline, runs completely in browser before uploading to server.
 */

window.OqcPdfCompressor = (function() {
    'use strict';

    if (window.pdfjsLib) {
        window.pdfjsLib.GlobalWorkerOptions.workerSrc = (window.APP_BASE_URL || '') + '/assets/js/vendor/pdf.worker.min.js';
    }

    async function compressPdfFile(file, onProgress, options) {
        if (!file || (file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf'))) {
            return file;
        }

        const opts = Object.assign({
            maxDpiScale: 1.5,
            jpegQuality: 0.78,
            sizeThresholdMb: 0.5
        }, options || {});

        const originalSizeBytes = file.size;
        const originalSizeMb = (originalSizeBytes / (1024 * 1024)).toFixed(2);

        if (originalSizeBytes < (opts.sizeThresholdMb * 1024 * 1024)) {
            if (typeof onProgress === 'function') {
                onProgress(100, 'Ukuran ' + originalSizeMb + ' MB sudah optimal.');
            }
            return file;
        }

        if (typeof onProgress === 'function') {
            onProgress(10, 'Membaca ' + file.name + ' (' + originalSizeMb + ' MB)...');
        }

        const arrayBuffer = await file.arrayBuffer();

        if (!window.pdfjsLib || !window.PDFLib) {
            console.warn('[OqcPdfCompressor] pdfjsLib atau PDFLib belum termuat, mengirim file original.');
            return file;
        }

        const loadingTask = window.pdfjsLib.getDocument({ data: new Uint8Array(arrayBuffer) });
        const pdfDoc = await loadingTask.promise;
        const numPages = pdfDoc.numPages;

        const newPdfDoc = await window.PDFLib.PDFDocument.create();

        for (let pageNum = 1; pageNum <= numPages; pageNum++) {
            const pageProgress = Math.round(15 + ((pageNum / numPages) * 75));
            if (typeof onProgress === 'function') {
                onProgress(pageProgress, 'Mengompres halaman ' + pageNum + ' dari ' + numPages + '...');
            }

            const page = await pdfDoc.getPage(pageNum);
            const viewport = page.getViewport({ scale: opts.maxDpiScale });

            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            canvas.width = viewport.width;
            canvas.height = viewport.height;

            const renderContext = {
                canvasContext: ctx,
                viewport: viewport
            };

            await page.render(renderContext).promise;

            const jpegBlob = await new Promise(function(resolve) {
                canvas.toBlob(function(blob) { resolve(blob); }, 'image/jpeg', opts.jpegQuality);
            });

            const jpegBytes = await jpegBlob.arrayBuffer();
            const embeddedImage = await newPdfDoc.embedJpg(jpegBytes);

            const newPage = newPdfDoc.addPage([viewport.width, viewport.height]);
            newPage.drawImage(embeddedImage, {
                x: 0,
                y: 0,
                width: viewport.width,
                height: viewport.height
            });

            canvas.width = 0;
            canvas.height = 0;
        }

        if (typeof onProgress === 'function') {
            onProgress(95, 'Menyusun berkas PDF terkompresi...');
        }

        const compressedBytes = await newPdfDoc.save();
        const compressedBlob = new Blob([compressedBytes], { type: 'application/pdf' });

        if (compressedBlob.size < originalSizeBytes) {
            const compressedMb = (compressedBlob.size / (1024 * 1024)).toFixed(2);
            const savedPct = Math.round((1 - (compressedBlob.size / originalSizeBytes)) * 100);
            if (typeof onProgress === 'function') {
                onProgress(100, 'Kompresi selesai: ' + originalSizeMb + ' MB -> ' + compressedMb + ' MB (Hemat ' + savedPct + '%)');
            }
            return new File([compressedBlob], file.name, {
                type: 'application/pdf',
                lastModified: Date.now()
            });
        } else {
            if (typeof onProgress === 'function') {
                onProgress(100, 'File sudah dalam ukuran optimal (' + originalSizeMb + ' MB).');
            }
            return file;
        }
    }

    return {
        compressPdfFile: compressPdfFile
    };
})();
