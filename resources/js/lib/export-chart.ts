/**
 * Export the Recharts SVG inside `container` as a downloaded PNG.
 *
 * Recharts colors its marks with `var(--viz-*)` custom properties, which only
 * resolve while the SVG lives in the document. The clone therefore gets every
 * element's computed fill/stroke/font inlined before serialization. The HTML
 * legend Recharts renders is not part of the SVG, so callers pass `legend`
 * entries to have swatches drawn onto the canvas instead.
 */
export async function exportChartPng(
    container: HTMLElement,
    options: {
        filename: string;
        title?: string;
        /** color is a CSS custom property name, e.g. "--viz-1", resolved against the container. */
        legend?: { label: string; color: string }[];
    },
): Promise<void> {
    const svg = container.querySelector('svg');
    if (!svg) return;

    const rect = svg.getBoundingClientRect();
    const clone = svg.cloneNode(true) as SVGSVGElement;

    const source = [svg, ...Array.from(svg.querySelectorAll('*'))];
    const target = [clone, ...Array.from(clone.querySelectorAll('*'))];
    source.forEach((el, i) => {
        const computed = getComputedStyle(el);
        const out = target[i] as SVGElement;
        out.setAttribute('fill', computed.fill);
        out.setAttribute('stroke', computed.stroke);
        out.setAttribute('font-family', computed.fontFamily);
        out.setAttribute('font-size', computed.fontSize);
        out.setAttribute('opacity', computed.opacity);
    });

    clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
    clone.setAttribute('width', String(rect.width));
    clone.setAttribute('height', String(rect.height));

    const styles = getComputedStyle(container);
    const surface = styles.getPropertyValue('--viz-surface').trim() || '#ffffff';
    const ink = styles.getPropertyValue('--viz-text').trim() || '#52514e';

    const image = new Image();
    await new Promise<void>((resolve, reject) => {
        image.onload = () => resolve();
        image.onerror = () => reject(new Error('SVG rasterization failed'));
        image.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(new XMLSerializer().serializeToString(clone));
    });

    const scale = 2;
    const pad = 16;
    const titleHeight = options.title ? 30 : 0;
    const legendHeight = options.legend?.length ? 26 : 0;
    const width = rect.width + pad * 2;
    const height = rect.height + pad * 2 + titleHeight + legendHeight;

    const canvas = document.createElement('canvas');
    canvas.width = width * scale;
    canvas.height = height * scale;
    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    ctx.scale(scale, scale);
    ctx.fillStyle = surface;
    ctx.fillRect(0, 0, width, height);

    if (options.title) {
        ctx.fillStyle = ink;
        ctx.font = '600 14px sans-serif';
        ctx.direction = 'rtl';
        ctx.textAlign = 'right';
        ctx.fillText(options.title, width - pad, pad + 10);
    }

    if (options.legend?.length) {
        ctx.font = '12px sans-serif';
        ctx.direction = 'rtl';
        ctx.textAlign = 'right';
        let x = width - pad;
        const y = pad + titleHeight + 8;
        for (const entry of options.legend) {
            ctx.fillStyle = ink;
            ctx.fillText(entry.label, x, y + 4);
            x -= ctx.measureText(entry.label).width + 12;
            ctx.fillStyle = styles.getPropertyValue(entry.color).trim() || ink;
            ctx.beginPath();
            ctx.arc(x, y, 4, 0, Math.PI * 2);
            ctx.fill();
            x -= 20;
        }
    }

    ctx.drawImage(image, pad, pad + titleHeight + legendHeight, rect.width, rect.height);

    const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/png'));
    if (!blob) return;

    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = options.filename;
    link.click();
    URL.revokeObjectURL(link.href);
}
