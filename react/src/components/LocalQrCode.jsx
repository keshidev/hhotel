import { useEffect, useState } from 'react';
import { Download } from 'lucide-react';
import QRCode from 'qrcode';

const LocalQrCode = ({ value, downloadName }) => {
  const [result, setResult] = useState({ value: '', dataUrl: '', error: false });
  const dataUrl = result.value === value ? result.dataUrl : '';
  const error = !value || (result.value === value && result.error);

  useEffect(() => {
    let active = true;

    if (!value) {
      return () => {
        active = false;
      };
    }

    QRCode.toDataURL(value, {
      width: 320,
      margin: 2,
      errorCorrectionLevel: 'M',
      color: {
        dark: '#000000',
        light: '#ffffff',
      },
    }).then((generatedDataUrl) => {
      if (active) {
        setResult({ value, dataUrl: generatedDataUrl, error: false });
      }
    }).catch(() => {
      if (active) {
        setResult({ value, dataUrl: '', error: true });
      }
    });

    return () => {
      active = false;
    };
  }, [value]);

  return (
    <>
      <div className="qr-code-shell">
        <div className="qr-code-container">
          {dataUrl && (
            <img src={dataUrl} alt="GCash QR Code" className="qr-code-image" />
          )}
          {!dataUrl && (
            <div className="qr-code-message" role={error ? 'alert' : 'status'}>
              {error ? 'QR unavailable. Open GCash directly.' : 'Generating QR securely...'}
            </div>
          )}
        </div>
      </div>
      {dataUrl ? (
        <a href={dataUrl} download={downloadName} className="qr-save-link">
          <Download size={15} />
          Save QR to Gallery
        </a>
      ) : (
        <span className="qr-save-link qr-save-link-disabled" aria-disabled="true">
          <Download size={15} />
          Save QR to Gallery
        </span>
      )}
    </>
  );
};

export default LocalQrCode;
