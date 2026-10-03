# NABILET Checker - Android Ticket Verification App

Production-ready Android application for offline-capable ticket verification at event venues.

## Features

- **Offline-first architecture**: Download encrypted ticket bundles and verify without network
- **QR Code scanning**: Fast, accurate scanning with camera permission handling
- **Cryptographic verification**: HMAC-SHA256 signature validation of QR codes
- **Multi-device sync**: Multiple checkers can work simultaneously with automatic reconciliation
- **Real-time statistics**: Dashboard showing scans per minute, success/failure rates
- **Audit trail**: All scans logged locally and synced when online

## Project Structure

```
android/
├── app/
│   ├── src/main/
│   │   ├── java/com/nabilet/checker/
│   │   │   ├── CheckerApplication.kt      # App initialization, DI setup
│   │   │   ├── MainActivity.kt            # Main scanner UI
│   │   │   ├── data/
│   │   │   │   ├── local/                 # Room database, DAOs
│   │   │   │   ├── remote/                # API client, bundle download
│   │   │   │   └── repository/            # Repository pattern implementation
│   │   │   ├── domain/
│   │   │   │   ├── model/                 # Domain models (Ticket, ScanResult)
│   │   │   │   └── util/                  # Crypto utils, QR parser
│   │   │   └── ui/
│   │   │       ├── scanner/               # Camera, QR detection
│   │   │       ├── dashboard/             # Statistics, recent scans
│   │   │       └── settings/              # Device config, sync status
│   │   ├── res/                           # Resources, layouts
│   │   └── AndroidManifest.xml
│   ├── build.gradle.kts
│   └── proguard-rules.pro
├── gradle/
├── build.gradle.kts
└── settings.gradle.kts
```

## Technical Stack

- **Language**: Kotlin 1.9+
- **Min SDK**: 24 (Android 7.0)
- **Target SDK**: 34 (Android 14)
- **Architecture**: MVVM + Clean Architecture
- **DI**: Hilt (Dagger)
- **Database**: Room with SQLCipher encryption
- **Network**: Retrofit + OkHttp
- **Async**: Kotlin Coroutines + Flow
- **Camera**: CameraX API
- **QR Processing**: ML Kit Barcode Scanning
- **Crypto**: Tink library for HMAC verification

## Build Instructions

### Prerequisites

- Android Studio Hedgehog or later
- JDK 17
- Android SDK 34

### Build Commands

```bash
# Debug build
./gradlew assembleDebug

# Release build (signed)
./gradlew assembleRelease

# Run tests
./gradlew test

# Generate APK
./gradlew build
```

## Configuration

### API Endpoint

Configure in `local.properties` or environment:

```properties
NABILET_API_URL=https://api.nabilet.com
NABILET_API_KEY=your_api_key_here
```

### Security

The app requires:
- Valid device registration with the server
- Encrypted bundle download (TLS 1.3)
- Local database encryption (SQLCipher)
- Secure key storage (Android Keystore)

## Usage Flow

1. **Device Registration**
   - Admin creates device in web dashboard
   - Device downloads initial bundle with public key fingerprint
   - Device authenticated and ready for scanning

2. **Bundle Download**
   - App periodically checks for new bundles
   - Downloads encrypted bundle containing ticket hashes
   - Stores in local encrypted database

3. **Ticket Scanning**
   - Camera detects QR code
   - App parses payload and extracts signature
   - Verifies HMAC-SHA256 signature using stored public key
   - Checks if ticket hash exists in local bundle
   - Records scan result (valid/invalid/already_used)

4. **Sync**
   - When online, uploads scan results to server
   - Downloads updated revocation lists
   - Resolves any conflicts

## Production Checklist

- [ ] Configure ProGuard/R8 rules
- [ ] Set up signing configuration
- [ ] Enable strict mode for debugging
- [ ] Configure crash reporting (Firebase Crashlytics)
- [ ] Set up analytics (optional)
- [ ] Test on multiple devices (different screen sizes, cameras)
- [ ] Verify offline functionality
- [ ] Test multi-device conflict resolution
- [ ] Security audit (penetration testing)

## License

Proprietary - NABILET Core Project
