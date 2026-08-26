(function() {
    if (window.Chart) return;
    console.warn('Chart.js could not be loaded from the CDN. Using lightweight fallback.');
    function noop() {}
    window.Chart = class {
        constructor(ctx, config) {
            this.ctx = ctx;
            this.config = config;
            console.warn('Chart rendering skipped: fallback stub in use.', config?.type || 'unknown');
        }
        update() { return this; }
        destroy() { return noop(); }
        toBase64Image() { return ''; }
    };
    window.Chart.__fallbackStub = true;
})();
