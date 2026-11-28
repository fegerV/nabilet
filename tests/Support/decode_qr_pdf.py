"""Декодировать QR из PDF: растеризовать первую страницу и прочитать OpenCV.

Независимая проверка печатной версии билета: QR должен читаться не только
в PNG рендерера, но и после печати в PDF. Если pymupdf/OpenCV недоступны,
скрипт возвращает код 3 — PHPUnit пропускает тест, а не падает по среде.
"""
import sys

try:
    import cv2
    import numpy
    import pymupdf as fitz
except ImportError:
    sys.exit(3)

doc = fitz.open(sys.argv[1])
if doc.page_count == 0:
    sys.exit(4)
pix = doc[0].get_pixmap(matrix=fitz.Matrix(3, 3), alpha=False)
raw = numpy.frombuffer(pix.samples, dtype='uint8').reshape(pix.height, pix.width, pix.n)
image = cv2.cvtColor(raw[:, :, :3], cv2.COLOR_RGB2BGR)
if image is None:
    sys.exit(5)
decoded, _, _ = cv2.QRCodeDetector().detectAndDecode(image)
if not decoded:
    sys.exit(6)
sys.stdout.write(decoded)
