
import Alpine from 'alpinejs';
import QRCode from 'qrcode';
import './echo';

window.Alpine = Alpine;

Alpine.start();

window.renderOpenWaQr = (canvas, payload) => {
    if (canvas && payload) QRCode.toCanvas(canvas, payload, { width: 176, margin: 1, errorCorrectionLevel: 'M' });
};
