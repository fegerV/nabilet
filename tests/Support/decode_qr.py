"""Декодировать QR из PNG через OpenCV (вспомогательный скрипт тестов).

Используется PHPUnit-тестом TicketQrDecodeTest как независимый декодер:
собственный QrEncoder проверяется не побайтовым сравнением матрицы, а реальным
чтением готовой картинки. Если OpenCV недоступен, тест пропускается.
"""
import sys

try:
    import cv2
except ImportError:
    sys.exit(3)

image = cv2.imread(sys.argv[1])
if image is None:
    sys.exit(4)

decoded, _, _ = cv2.QRCodeDetector().detectAndDecode(image)
if not decoded:
    sys.exit(5)
sys.stdout.write(decoded)
